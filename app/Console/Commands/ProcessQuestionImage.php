<?php

namespace App\Console\Commands;

use App\Models\Question;
use App\Services\GeminiService;
use Illuminate\Console\Command;

class ProcessQuestionImage extends Command
{
    protected $signature = 'questions:process-image
                            {--id= : ID of the source question to process}
                            {--all : Process all unprocessed source images}';

    protected $description = 'Process a source image with Gemini Vision to extract individual questions';

    public function handle(GeminiService $gemini): int
    {
        if ($this->option('all')) {
            return $this->processAll($gemini);
        }

        $id = $this->option('id');

        if (! $id) {
            $this->error('Specify --id or --all');

            return self::FAILURE;
        }

        $sourceQuestion = Question::find($id);

        if (! $sourceQuestion) {
            $this->error("Question with ID {$id} not found.");

            return self::FAILURE;
        }

        return $this->processImage($sourceQuestion, $gemini);
    }

    private function processAll(GeminiService $gemini): int
    {
        // Get all source images (questions without question_number, meaning unprocessed)
        $sources = Question::whereNull('question_number')->get();

        if ($sources->isEmpty()) {
            $this->info('No unprocessed source images found.');

            return self::SUCCESS;
        }

        $this->info("Processing {$sources->count()} source images...");

        $totalExtracted = 0;

        foreach ($sources as $source) {
            $this->info("\nProcessing source ID {$source->id}: {$source->source_image_path}");

            try {
                $count = $this->processImage($source, $gemini);
                $totalExtracted += $count;
            } catch (\Exception $e) {
                $this->error("  Error: {$e->getMessage()}");

                continue;
            }

            // Small delay to respect rate limits
            if ($sources->last()->id !== $source->id) {
                sleep(1);
            }
        }

        $this->info("\nDone. Extracted {$totalExtracted} individual questions from {$sources->count()} source images.");

        return self::SUCCESS;
    }

    private function processImage(Question $source, GeminiService $gemini): int
    {
        $this->line('  Calling Gemini Vision...');

        $data = $gemini->extractQuestionsFromImage($source->source_image_path);

        $questions = $data['questions'] ?? [];

        if (empty($questions)) {
            $this->warn('  No questions extracted.');

            return 0;
        }

        $this->line('  Extracted '.count($questions).' questions.');

        $bar = $this->output->createProgressBar(count($questions));

        foreach ($questions as $index => $q) {
            $question = Question::create([
                'source_image_path' => $source->source_image_path,
                'question_number' => $q['question_number'] ?? ($index + 1),
                'question_text' => $q['question_text'] ?? null,
                'correct_answer' => $q['correct_answer'] ?? null,
                'explanation' => $q['explanation'] ?? null,
                'options' => $q['options'] ?? null,
                'topic' => $q['topic'] ?? null,
                'has_image' => $q['has_image'] ?? false,
            ]);

            // Crop the question image if it has a visual element
            if ($question->has_image && isset($q['bbox'])) {
                $croppedPath = $gemini->cropQuestionImage(
                    $source->source_image_path,
                    $q['bbox'],
                    $question->id
                );

                if ($croppedPath) {
                    $question->update(['cropped_image_path' => $croppedPath]);
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return count($questions);
    }
}
