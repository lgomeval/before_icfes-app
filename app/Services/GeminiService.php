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
