<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;

    private string $baseUrl;

    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';
        $this->model = 'gemini-2.5-flash';
    }

    /**
     * Analyze a source image containing multiple exam questions.
     * Returns an array of individual question data extracted by Gemini Vision.
     */
    public function extractQuestionsFromImage(string $imagePath): array
    {
        $fullPath = public_path($imagePath);

        if (! file_exists($fullPath)) {
            throw new \RuntimeException("Image not found: {$fullPath}");
        }

        $imageData = base64_encode(file_get_contents($fullPath));
        $mimeType = mime_content_type($fullPath) ?: 'image/png';

        $prompt = $this->buildExtractionPrompt();

        $response = $this->callGemini($prompt, $imageData, $mimeType);

        return $this->parseResponse($response);
    }

    /**
     * Check if a user's answer is correct for a given question.
     * Returns array with 'is_correct' (bool) and 'explanation' (string).
     */
    public function checkAnswer(string $questionText, array $options, string $correctAnswer, string $userAnswer): array
    {
        $optionsText = json_encode($options, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Eres un evaluador de respuestas para el examen ICFES colombiano.

Pregunta: {$questionText}
Opciones: {$optionsText}
Respuesta correcta: {$correctAnswer}
Respuesta del estudiante: {$userAnswer}

Compara la respuesta del estudiante con la respuesta correcta. Sé flexible con el formato (mayúsculas/minúsculas, espacios extra, equivalencias numéricas como "1/2" y "0.5").

Responde EXCLUSIVAMENTE en este formato JSON:
{
    "is_correct": true/false,
    "explanation": "Explicación breve en español de por qué la respuesta correcta es la correcta."
}

Si la respuesta del estudiante es correcta, la explicación debe ser un mensaje de felicitación breve.
Si es incorrecta, la explicación debe explicar por qué la respuesta correcta es la correcta y por qué la del estudiante no.
PROMPT;

        $response = $this->callGemini($prompt);

        return $this->parseResponse($response);
    }

    /**
     * Generate a variant of a question with changed numeric variables.
     */
    public function generateVariant(string $questionText, array $options, string $correctAnswer): array
    {
        $optionsText = json_encode($options, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Eres un generador de variantes de preguntas para el examen ICFES colombiano.

A continuación recibirás una pregunta con sus opciones y respuesta correcta.
Debes generar una NUEVA versión de la misma pregunta cambiando los valores numéricos y las variables, manteniendo exactamente la misma estructura, tipo de razonamiento y formato.

Pregunta original: {$questionText}
Opciones originales: {$optionsText}
Respuesta correcta original: {$correctAnswer}

Genera una nueva pregunta del MISMO TIPO con diferentes números. La nueva pregunta debe evaluar el mismo concepto pero con valores distintos. Recalcula la respuesta correcta.

Responde EXCLUSIVAMENTE en este formato JSON:
{
    "question_text": "Texto de la nueva pregunta con variables cambiadas",
    "options": {"A": "opción A", "B": "opción B", "C": "opción C", "D": "opción D"},
    "correct_answer": "A" o "B" o "C" o "D" o el valor correcto,
    "explanation": "Breve explicación de la respuesta correcta para la nueva pregunta"
}
PROMPT;

        $response = $this->callGemini($prompt);

        return $this->parseResponse($response);
    }

    /**
     * Generate ICFES-aligned questions for a specific area and level.
     * Returns an array of question data ready to be stored in the database.
     */
    public function generateIcfesQuestions(string $area, string $level, int $count): array
    {
        $prompt = $this->buildIcfesGenerationPrompt($area, $level, $count);

        $response = $this->callGeminiWithHigherTokens($prompt);

        $questions = $response['questions'] ?? [];

        if (empty($questions)) {
            throw new \RuntimeException('Gemini returned no questions');
        }

        return array_map(fn (array $q) => [
            'question_text' => $q['question_text'] ?? '',
            'options' => $q['options'] ?? [],
            'correct_answer' => $q['correct_answer'] ?? '',
            'explanation' => $q['explanation'] ?? null,
            'topic' => $q['topic'] ?? null,
            'area' => $area,
            'level' => $level,
            'source' => 'ai',
        ], $questions);
    }

    /**
     * Build the prompt for generating real ICFES-style questions.
     */
    private function buildIcfesGenerationPrompt(string $area, string $level, int $count): string
    {
        $difficulty = match ($level) {
            'Facil' => 'básico. Preguntas de comprensión literal y aplicación directa de conceptos fundamentales.',
            'Medio' => 'intermedio. Preguntas que requieren relacionar conceptos, interpretar información y resolver problemas de 2-3 pasos.',
            'Dificil' => 'avanzado. Preguntas que exigen análisis profundo, inferencia, síntesis de información y razonamiento multi-paso.',
            default => 'intermedio.',
        };

        $areaPrompt = match ($area) {
            'Matematicas' => $this->mathIcfesPrompt($difficulty),
            'Ciencias Naturales' => $this->scienceIcfesPrompt($difficulty),
            'Ingles' => $this->englishIcfesPrompt($difficulty),
            default => '',
        };

        return <<<PROMPT
Eres un generador de preguntas para el examen ICFES Saber 11 colombiano. Genera EXACTAMENTE {$count} preguntas del área de **{$area}** con nivel **{$level}** ({$difficulty}).

{$areaPrompt}

## Formato de respuesta

Responde EXCLUSIVAMENTE con un JSON válido sin markdown:

{
    "questions": [
        {
            "question_text": "Texto completo de la pregunta con su contexto o enunciado",
            "options": {"A": "Opción A", "B": "Opción B", "C": "Opción C", "D": "Opción D"},
            "correct_answer": "A",
            "explanation": "Explicación breve en español de por qué esa es la respuesta correcta",
            "topic": "Tema específico"
        }
    ]
}

REGLAS:
- Las opciones deben ser plausibles (no absurdas ni obvias).
- La respuesta correcta debe estar distribuida aleatoriamente entre A, B, C, D.
- Cada pregunta debe evaluar una competencia, no solo memoria.
- Los textos deben ser originales, no copiados.
- Las explicaciones deben ser educativas y en español.
PROMPT;
    }

    /**
     * ICFES-style prompt for Mathematics.
     */
    private function mathIcfesPrompt(string $difficulty): string
    {
        return <<<'PROMPT'
## INSTRUCCIONES ESPECÍFICAS PARA MATEMÁTICAS ICFES

Genera preguntas que evalúen competencias matemáticas, NO simple memoria de fórmulas. Cada pregunta debe incluir un CONTEXTO o SITUACIÓN REAL.

Tipos de preguntas que debes generar (varíalas):
1. **Razonamiento cuantitativo**: problemas con situaciones cotidianas (compras, mediciones, presupuestos).
2. **Interpretación de datos**: preguntas que describan una tabla, gráfico o conjunto de datos e inviten a interpretarlos.
3. **Geometría aplicada**: problemas de áreas, volúmenes o perímetros en contextos reales (terrenos, empaques, construcción).
4. **Probabilidad y estadística**: situaciones de juegos, encuestas, experimentos aleatorios.
5. **Pensamiento variacional**: patrones, secuencias, proporcionalidad, porcentajes en contextos financieros.

Temas permitidos: Geometria, Algebra, Estadistica, Probabilidad, Porcentajes.

Ejemplo de buen formato:
"Una tienda ofrece un descuento del 25% sobre el precio original de un artículo. Si después del descuento se aplica un IVA del 19% sobre el precio rebajado, y el cliente paga $142,800, ¿cuál era el precio original del artículo?"
PROMPT;
    }

    /**
     * ICFES-style prompt for Natural Sciences.
     */
    private function scienceIcfesPrompt(string $difficulty): string
    {
        return <<<'PROMPT'
## INSTRUCCIONES ESPECÍFICAS PARA CIENCIAS NATURALES ICFES

Genera preguntas que evalúen la capacidad de analizar fenómenos científicos, NO simple memorización de datos. Usa el método científico como eje.

Tipos de preguntas que debes generar (varíalas):
1. **Diseño experimental**: plantea un experimento con variables (independiente, dependiente, controladas) y pregunta cuál es la conclusión válida.
2. **Interpretación de gráficas/tablas**: describe datos de un experimento e invita a interpretarlos.
3. **Aplicación de conceptos**: presenta un fenómeno cotidiano y pregunta qué principio científico lo explica.
4. **Análisis de cadenas tróficas, ciclos biogeoquímicos o ecosistemas**.
5. **Relación estructura-función en biología, física aplicada o química en contexto**.

Temas permitidos: Biologia, Fisica, Quimica, Ecologia.

Ejemplo de buen formato:
"Un estudiante coloca una planta en una caja con una única abertura lateral por donde entra luz. Después de una semana observa que el tallo crece inclinado hacia la abertura. ¿Qué fenómeno explica mejor este resultado?"
PROMPT;
    }

    /**
     * ICFES-style prompt for English.
     */
    private function englishIcfesPrompt(string $difficulty): string
    {
        return <<<'PROMPT'
## INSTRUCCIONES ESPECÍFICAS PARA INGLÉS ICFES

Genera preguntas que evalúen competencia comunicativa en inglés en contextos auténticos, NO simple traducción o reglas gramaticales aisladas.

Tipos de preguntas que debes generar (varíalas):
1. **Reading comprehension**: un pasaje corto en inglés (60-150 palabras) + preguntas sobre idea principal, detalles, inferencia, propósito del autor.
   - El pasaje debe ser un texto auténtico: email, artículo, anuncio, carta, reseña.
   - En el question_text incluye el pasaje COMPLETO seguido de la pregunta.
2. **Grammar in context**: un párrafo con espacios en blanco donde el estudiante debe elegir la palabra/frase correcta según el contexto.
3. **Vocabulary in context**: completar oraciones con la palabra que mejor se ajusta al significado del texto.
4. **Situaciones comunicativas**: diálogos o situaciones donde se evalúa la respuesta apropiada.

Temas permitidos: Vocabulary, Grammar, Reading.

IMPORTANTE: Las preguntas y opciones deben estar en INGLÉS. Solo la EXPLANATION va en español.

Ejemplo de buen formato (Reading):
"Read the following text: 'The city council announced yesterday that all public schools will receive new computers starting next month. The initiative, funded by a national grant, aims to reduce the digital gap among students from low-income families. Teachers have expressed enthusiasm about the program.' According to the text, what is the main purpose of the initiative?"
PROMPT;
    }

    /**
     * Call Gemini API with higher token limit for question generation.
     * Includes retry logic with exponential backoff for rate limiting (503).
     */
    private function callGeminiWithHigherTokens(string $prompt): array
    {
        $url = "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topP' => 0.95,
                'maxOutputTokens' => 8192,
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ],
        ];

        $maxRetries = 3;
        $lastError = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $delay = (int) pow(2, $attempt);
                Log::warning("Gemini API retry {$attempt}/{$maxRetries}, waiting {$delay}s...");
                sleep($delay);
            }

            try {
                $response = Http::timeout(120)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($url, $payload);
            } catch (ConnectionException $e) {
                $lastError = new \RuntimeException('Gemini API connection failed: '.$e->getMessage());
                Log::error('Gemini API connection failed', ['error' => $e->getMessage()]);

                continue;
            }

            if (! $response->successful()) {
                $status = $response->status();
                Log::error('Gemini API error', [
                    'status' => $status,
                    'body' => $response->body(),
                ]);

                if ($status === 503 || $status === 429) {
                    $lastError = new \RuntimeException("Gemini API error: {$status}");

                    continue;
                }

                throw new \RuntimeException("Gemini API error: {$status}");
            }

            $data = $response->json();

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if ($text === null) {
                Log::error('Gemini API unexpected response', ['response' => $data]);
                throw new \RuntimeException('Unexpected Gemini API response structure');
            }

            $text = trim($text);
            $text = preg_replace('/^```(?:json)?\s*\n/', '', $text);
            $text = preg_replace('/\n```\s*$/', '', $text);
            $text = trim($text);

            $text = $this->sanitizeJsonText($text);

            $decoded = json_decode($text, true);
            $jsonError = json_last_error();

            if ($jsonError !== JSON_ERROR_NONE) {
                file_put_contents(storage_path('logs/gemini_generate_raw.txt'), $text);
                throw new \RuntimeException('Gemini returned invalid JSON: '.json_last_error_msg().' (code: '.$jsonError.')');
            }

            if ($decoded === null && $text !== 'null') {
                throw new \RuntimeException('Gemini JSON decoded to null. Text length: '.strlen($text));
            }

            return $decoded;
        }

        throw $lastError ?: new \RuntimeException('Gemini API failed after '.$maxRetries.' retries');
    }

    /**
     * Build the prompt for extracting questions from a source image.
     */
    private function buildExtractionPrompt(): string
    {
        return <<<'PROMPT'
Analiza esta imagen de un examen o banco de preguntas tipo ICFES colombiano. Extrae SOLO la información esencial de cada pregunta. Sé CONCISO en los textos para ahorrar tokens.

Para cada pregunta devuelve:

1. question_number: el número
2. question_text: el texto COMPLETO pero conciso de la pregunta y enunciado
3. options: objeto con opciones {"A": "...", "B": "...", "C": "...", "D": "..."}
4. correct_answer: la letra correcta (A, B, C, D). Si está marcada en la imagen, usa esa. Si no, dedúcela
5. has_image: true si la pregunta requiere una imagen/gráfico/tabla para responderse
6. topic: área (matematicas, ciencias, lectura_critica, sociales, ingles)
7. bbox: si has_image es true, coordenadas aproximadas del area de la pregunta en la imagen como porcentajes: {"x":0,"y":0,"width":100,"height":25}

NO incluyas explicaciones. Solo datos esenciales.

Responde EXCLUSIVAMENTE con JSON:
{"total_questions":N,"questions":[{"question_number":1,"question_text":"...","options":{"A":"...","B":"...","C":"...","D":"..."},"correct_answer":"A","has_image":false,"topic":"...","bbox":{"x":0,"y":0,"width":100,"height":20}}]}

No incluyas ningun texto antes o despues del JSON.
PROMPT;
    }

    /**
     * Call the Gemini API.
     */
    private function callGemini(string $prompt, ?string $imageData = null, ?string $mimeType = null): array
    {
        $url = "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}";

        $parts = [
            ['text' => $prompt],
        ];

        if ($imageData !== null) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType ?? 'image/png',
                    'data' => $imageData,
                ],
            ];
        }

        $payload = [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'topP' => 0.95,
                'maxOutputTokens' => 8192,
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ],
        ];

        try {
            $response = Http::timeout(60)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::error('Gemini API connection failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Gemini API connection failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            Log::error('Gemini API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException("Gemini API error: {$response->status()}");
        }

        $data = $response->json();

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($text === null) {
            Log::error('Gemini API unexpected response', ['response' => $data]);
            throw new \RuntimeException('Unexpected Gemini API response structure');
        }

        // Strip markdown code fences if present
        $text = trim($text);
        if (str_starts_with($text, '```json')) {
            $text = substr($text, 7);
        }
        if (str_starts_with($text, '```')) {
            $text = substr($text, 3);
        }
        if (str_ends_with($text, '```')) {
            $text = substr($text, 0, -3);
        }
        $text = trim($text);

        // Strip markdown code fences
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*\n/', '', $text);
        $text = preg_replace('/\n```\s*$/', '', $text);
        $text = trim($text);

        // Sanitize: replace unescaped control chars in JSON strings
        // This handles cases where Gemini embeds raw newlines/tabs in string values
        $text = $this->sanitizeJsonText($text);

        $decoded = json_decode($text, true);
        $jsonError = json_last_error();

        if ($jsonError !== JSON_ERROR_NONE) {
            // Save problematic text for debugging
            file_put_contents(storage_path('logs/gemini_raw_response.txt'), $text);
            throw new \RuntimeException('Gemini returned invalid JSON: '.json_last_error_msg().' (code: '.$jsonError.')');
        }

        if ($decoded === null && $text !== 'null') {
            throw new \RuntimeException('Gemini JSON decoded to null. Text length: '.strlen($text));
        }

        return $decoded;
    }

    /**
     * Parse the Gemini response into a consistent format.
     */
    private function parseResponse(array $response): array
    {
        return $response;
    }

    /**
     * Sanitize JSON text by escaping control characters within string values.
     * Handles Gemini responses that embed raw newlines/tabs inside JSON strings.
     */
    private function sanitizeJsonText(string $json): string
    {
        $result = '';
        $length = strlen($json);
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($escaped) {
                $result .= $char;
                $escaped = false;

                continue;
            }

            if ($inString) {
                if ($char === '\\') {
                    $escaped = true;
                    $result .= $char;
                } elseif ($char === '"') {
                    $inString = false;
                    $result .= $char;
                } elseif (ord($char) < 32) {
                    // Escape control characters inside JSON strings
                    $result .= match ($char) {
                        "\n" => '\n',
                        "\r" => '\r',
                        "\t" => '\t',
                        default => sprintf('\u%04x', ord($char)),
                    };
                } else {
                    $result .= $char;
                }
            } else {
                if ($char === '"') {
                    $inString = true;
                }
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Crop a question area from the source image using GD.
     * Returns the path to the cropped image relative to public/.
     */
    public function cropQuestionImage(string $sourceImagePath, array $bbox, int $questionId): ?string
    {
        $fullPath = public_path($sourceImagePath);

        if (! file_exists($fullPath)) {
            return null;
        }

        $sourceImage = imagecreatefrompng($fullPath);

        if ($sourceImage === false) {
            return null;
        }

        $srcWidth = imagesx($sourceImage);
        $srcHeight = imagesy($sourceImage);

        // Convert percentage coordinates to pixels
        $x = (int) round(($bbox['x'] / 100) * $srcWidth);
        $y = (int) round(($bbox['y'] / 100) * $srcHeight);
        $width = (int) round(($bbox['width'] / 100) * $srcWidth);
        $height = (int) round(($bbox['height'] / 100) * $srcHeight);

        // Clamp values to image boundaries
        $x = max(0, min($x, $srcWidth - 1));
        $y = max(0, min($y, $srcHeight - 1));
        $width = max(1, min($width, $srcWidth - $x));
        $height = max(1, min($height, $srcHeight - $y));

        $cropped = imagecreatetruecolor($width, $height);

        // Preserve transparency
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);

        imagecopyresampled(
            $cropped, $sourceImage,
            0, 0,          // dest x, y
            $x, $y,        // src x, y
            $width, $height,
            $width, $height
        );

        $outputDir = public_path('images/questions');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputPath = "images/questions/{$questionId}.png";
        $outputFullPath = public_path($outputPath);

        imagepng($cropped, $outputFullPath, 9);

        imagedestroy($sourceImage);
        imagedestroy($cropped);

        return $outputPath;
    }
}
