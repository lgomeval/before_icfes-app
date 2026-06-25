<?php

namespace App\Console\Commands;

use App\Models\Question;
use App\Services\GeminiService;
use Illuminate\Console\Command;

class GenerateAiQuestions extends Command
{
    protected $signature = 'questions:generate-ai
                            {--area= : Area to generate for (Matematicas, Ciencias Naturales, Ingles)}
                            {--count=30 : Total questions to generate}
                            {--all : Generate for all areas}';

    protected $description = 'Generate ICFES-aligned questions using Gemini AI';

    public function handle(GeminiService $gemini): int
    {
        $areas = $this->option('all')
            ? Question::areas()
            : [$this->option('area') ?: 'Matematicas'];

        foreach ($areas as $area) {
            if (! in_array($area, Question::areas())) {
                $this->error("Invalid area: {$area}. Valid: ".implode(', ', Question::areas()));

                return self::FAILURE;
            }
        }

        $totalCount = (int) $this->option('count');
        $perArea = (int) ceil($totalCount / count($areas));

        $distribution = [
            'Facil' => 0.20,
            'Medio' => 0.40,
            'Dificil' => 0.40,
        ];

        $totalGenerated = 0;

        foreach ($areas as $area) {
            $this->info("\n=== Generating for {$area} ===");

            foreach ($distribution as $level => $ratio) {
                $count = (int) round($perArea * $ratio);

                if ($count < 1) {
                    continue;
                }

                $batchSize = min($count, 5);
                $remaining = $count;
                $generated = 0;
                $maxRetries = 3;

                while ($remaining > 0) {
                    $currentBatch = min($batchSize, $remaining);
                    $retryCount = 0;

                    while ($retryCount <= $maxRetries) {
                        if ($retryCount > 0) {
                            $this->line("  Retrying {$currentBatch} questions ({$retryCount}/{$maxRetries})...");
                        } else {
                            $this->line("  Level: {$level} - Generating {$currentBatch} of {$count}...");
                        }

                        try {
                            $questions = $gemini->generateIcfesQuestions($area, $level, $currentBatch);

                            foreach ($questions as $q) {
                                Question::create($q);
                            }

                            $generated += count($questions);
                            $this->info('    Stored '.count($questions).' questions.');

                            break;
                        } catch (\Exception $e) {
                            $retryCount++;

                            if ($retryCount <= $maxRetries) {
                                $delay = (int) pow(2, $retryCount) + 1;
                                $this->warn("    Error: {$e->getMessage()}. Retrying in {$delay}s...");
                                sleep($delay);
                            } else {
                                $this->error("    Failed after {$maxRetries} retries: {$e->getMessage()}");
                            }
                        }
                    }

                    $remaining -= $currentBatch;

                    if ($remaining > 0) {
                        $this->line('    Waiting 3s before next batch...');
                        sleep(3);
                    }
                }

                $totalGenerated += $generated;
                $this->info("  {$level}: {$generated}/{$count} questions stored.");
            }
        }

        $this->info("\nDone. Generated {$totalGenerated} AI questions.");

        return self::SUCCESS;
    }
}
