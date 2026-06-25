<?php

namespace Database\Seeders;

use App\Models\Question;
use App\Services\GeminiService;
use Illuminate\Database\Seeder;

class QuestionSeeder extends Seeder
{
    public function run(): void
    {
        $files = [
            'PreguntasSeeder_Matematicas_100.json',
            'PreguntasSeeder_Naturales_100.json',
            'PreguntasSeeder_Ingles_100.json',
        ];

        $gemini = app(GeminiService::class);
        $total = 0;

        foreach ($files as $filename) {
            $path = database_path("seeders/{$filename}");

            if (! file_exists($path)) {
                $this->command?->warn("File not found: {$filename}");

                continue;
            }

            $questions = json_decode(file_get_contents($path), true);

            if (! is_array($questions)) {
                $this->command?->error("Invalid JSON in {$filename}");

                continue;
            }

            $count = 0;

            foreach ($questions as $data) {
                $question = Question::create([
                    'source_image_path' => $data['source_image_path'] ?? null,
                    'question_number' => $data['question_number'] ?? null,
                    'question_text' => $data['question_text'] ?? null,
                    'correct_answer' => $data['correct_answer'] ?? null,
                    'explanation' => $data['explanation'] ?? null,
                    'options' => $data['options'] ?? null,
                    'topic' => $data['topic'] ?? null,
                    'area' => $data['area'] ?? null,
                    'level' => $data['level'] ?? null,
                    'has_image' => $data['has_image'] ?? false,
                ]);

                if ($question->has_image && isset($data['bbox']) && $question->source_image_path) {
                    $croppedPath = $gemini->cropQuestionImage(
                        $question->source_image_path,
                        $data['bbox'],
                        $question->id
                    );

                    if ($croppedPath) {
                        $question->update(['cropped_image_path' => $croppedPath]);
                    }
                }

                $count++;
            }

            $this->command?->info("Seeded {$count} questions from {$filename}");
            $total += $count;
        }

        $this->command?->info("Total: {$total} questions seeded.");
    }
}
