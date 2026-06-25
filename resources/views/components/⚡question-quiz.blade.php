<?php

use App\Models\Player;
use App\Models\PlayerLogin;
use App\Models\Question;
use App\Services\GeminiService;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?Player $player = null;

    public ?Question $question = null;

    public string $nickname = '';

    public string $answer = '';

    public int $answeredCount = 0;

    public int $correctCount = 0;

    /** Filters */
    public string $selectedArea = '';

    public string $selectedTopic = '';

    public string $selectedLevel = '';

    private GeminiService $gemini;

    public function boot(GeminiService $gemini): void
    {
        $this->gemini = $gemini;
    }

    public function mount(): void
    {
        if (session()->has('player_id')) {
            $this->player = Player::find(session('player_id'));

            if ($this->player) {
                $this->loadStats();
                $this->loadQuestion();
            }
        }
    }

    public function login(): void
    {
        $this->validate(['nickname' => 'required|string|min:2|max:30']);

        $this->player = Player::findOrCreateByNickname($this->nickname);
        session()->put('player_id', $this->player->id);

        PlayerLogin::record([
            'player_id' => $this->player->id,
            'nickname' => $this->nickname,
            'action' => 'login_success',
        ]);

        $this->loadStats();
        $this->loadQuestion();
    }

    public function logout(): void
    {
        PlayerLogin::record([
            'player_id' => $this->player->id,
            'nickname' => $this->player->nickname,
            'action' => 'logout',
        ]);

        session()->forget('player_id');
        $this->player = null;
        $this->question = null;
        $this->nickname = '';
        $this->answeredCount = 0;
        $this->correctCount = 0;
    }

    public function updatedSelectedArea(): void
    {
        $this->selectedTopic = '';
        $this->loadQuestion();
    }

    public function updatedSelectedTopic(): void
    {
        $this->loadQuestion();
    }

    public function updatedSelectedLevel(): void
    {
        $this->loadQuestion();
    }

    public function loadQuestion(): void
    {
        $correctIds = $this->player ? $this->player->correctAnswerIds() : [];

        $query = Question::whereNotNull('question_text')
            ->when($this->selectedArea, fn($q) => $q->byArea($this->selectedArea))
            ->when($this->selectedTopic, fn($q) => $q->byTopic($this->selectedTopic))
            ->when($this->selectedLevel, fn($q) => $q->byLevel($this->selectedLevel))
            ->when(count($correctIds) > 0, fn($q) => $q->whereNotIn('id', $correctIds))
            ->random();

        $this->question = $query->first();
        $this->answer = '';
    }

    public function selectOption(string $letter): void
    {
        if (! $this->question || ! $this->player) {
            return;
        }

        $this->answer = $letter;

        $isCorrect = $this->isAnswerCorrect($this->question, $letter);

        // Save answer to DB
        $this->player->answers()->create([
            'question_id' => $this->question->id,
            'is_correct' => $isCorrect,
        ]);

        $this->loadStats();

        if ($isCorrect) {
            $this->dispatch('show-correct');
        } else {
            $explanation = $this->getExplanation($this->question, $letter);
            $this->dispatch('show-incorrect', ['explanation' => $explanation]);
        }
    }

    #[On('next-question')]
    public function nextQuestion(): void
    {
        $this->loadQuestion();
    }

    public function getTopicsForArea(): array
    {
        if ($this->selectedArea === '') {
            return [];
        }

        return Question::topics()[$this->selectedArea] ?? [];
    }

    public function getPercentage(): int
    {
        if ($this->answeredCount === 0) {
            return 0;
        }

        return (int) round(($this->correctCount / $this->answeredCount) * 100);
    }

    private function loadStats(): void
    {
        if (! $this->player) {
            return;
        }

        $stats = $this->player->stats();
        $this->answeredCount = $stats['total'];
        $this->correctCount = $stats['correct'];
    }

    private function isAnswerCorrect(Question $question, string $userAnswer): bool
    {
        $correct = $question->correct_answer;

        if ($this->matchAnswer($correct, $userAnswer)) {
            return true;
        }

        $options = $question->options ?? [];

        if (isset($options[$correct]) && $this->matchAnswer($options[$correct], $userAnswer)) {
            return true;
        }

        return false;
    }

    private function matchAnswer(string $expected, string $actual): bool
    {
        return strcasecmp(trim($expected), trim($actual)) === 0;
    }

    private function getExplanation(Question $question, string $userAnswer): string
    {
        if ($question->explanation) {
            return $question->explanation;
        }

        try {
            $result = $this->gemini->checkAnswer(
                $question->question_text,
                $question->options ?? [],
                $question->correct_answer,
                $userAnswer
            );

            $explanation = $result['explanation'] ?? null;

            if ($explanation) {
                $question->update(['explanation' => $explanation]);
            }

            return $explanation ?: $this->defaultExplanation($question);
        } catch (\Exception $e) {
            return $this->defaultExplanation($question);
        }
    }

    private function defaultExplanation(Question $question): string
    {
        $correct = $question->correct_answer;
        $options = $question->options ?? [];
        $correctText = $options[$correct] ?? $correct;

        return "La respuesta correcta es <strong>{$correct}</strong>: {$correctText}. Revisa el razonamiento e inténtalo de nuevo.";
    }
};
?>

<div class="flex flex-col min-h-screen">
    @if (! $player)
        {{-- Nickname form --}}
        <div class="flex-1 flex items-center justify-center px-4">
            <div class="w-full max-w-sm">
                <div class="bg-white rounded-3xl shadow-lg border border-green-100 p-8 text-center">
                    <h1 class="text-2xl font-bold text-gray-800 mb-2">Práctica ICFES</h1>
                    <p class="text-gray-500 text-sm mb-6">Elige un nickname para empezar</p>

                    <form wire:submit="login" class="space-y-4">
                        <div>
                            <input
                                type="text"
                                wire:model="nickname"
                                placeholder="Tu nickname..."
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-lg px-4 py-3 text-center"
                                autocomplete="off"
                                autofocus
                            />
                            @error('nickname')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="w-full bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white font-semibold py-3 px-6 rounded-xl transition-colors text-lg"
                        >
                            <span wire:loading.remove>Entrar</span>
                            <span wire:loading>Cargando...</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

    @elseif (! $question)
        {{-- All questions completed --}}
        <div class="flex-1 flex items-center justify-center px-4">
            <div class="text-center bg-white rounded-3xl shadow-lg p-12 max-w-lg">
                <p class="text-2xl mb-3">¡Completaste todas las preguntas, {{ $player->nickname }}!</p>
                <p class="text-lg font-semibold">
                    <span class="text-green-600">{{ $correctCount }} correctas</span>
                    <span class="text-gray-400 mx-2">|</span>
                    <span class="text-red-500">{{ $answeredCount - $correctCount }} incorrectas</span>
                    <span class="text-gray-400 mx-2">|</span>
                    <span class="text-gray-700">{{ $this->getPercentage() }}%</span>
                </p>
                <button wire:click="logout" class="mt-6 text-sm text-gray-500 hover:text-gray-700 underline">
                    Cambiar de jugador
                </button>
            </div>
        </div>

    @else
        {{-- Quiz --}}
        {{-- Filter bar --}}
        <div class="sticky top-0 z-10 bg-white/80 backdrop-blur-md border-b border-gray-100 shadow-sm">
            <div class="max-w-4xl mx-auto px-4 py-3">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-sm font-semibold text-gray-500 mr-1">{{ $player->nickname }}</span>

                    {{-- Area --}}
                    <select wire:model.live="selectedArea" class="rounded-xl border-gray-200 text-sm px-3 py-2 bg-white focus:border-green-500 focus:ring-green-500">
                        <option value="">Todas las áreas</option>
                        @foreach (Question::areas() as $area)
                            <option value="{{ $area }}">{{ $area }}</option>
                        @endforeach
                    </select>

                    {{-- Topic --}}
                    @if ($selectedArea)
                        <select wire:model.live="selectedTopic" class="rounded-xl border-gray-200 text-sm px-3 py-2 bg-white focus:border-green-500 focus:ring-green-500">
                            <option value="">Todos los temas</option>
                            @foreach ($this->getTopicsForArea() as $topic)
                                <option value="{{ $topic }}">{{ $topic }}</option>
                            @endforeach
                        </select>
                    @endif

                    {{-- Level --}}
                    <select wire:model.live="selectedLevel" class="rounded-xl border-gray-200 text-sm px-3 py-2 bg-white focus:border-green-500 focus:ring-green-500">
                        <option value="">Todos los niveles</option>
                        @foreach (Question::levels() as $level)
                            <option value="{{ $level }}">{{ $level }}</option>
                        @endforeach
                    </select>

                    {{-- Stats --}}
                    @if ($answeredCount > 0)
                        <div class="flex items-center gap-3 ml-auto text-sm">
                            <span class="text-green-700 font-semibold">{{ $correctCount }} correctas</span>
                            <span class="text-red-500 font-semibold">{{ $answeredCount - $correctCount }} incorrectas</span>
                            <div class="w-20 h-2.5 bg-red-100 rounded-full overflow-hidden flex">
                                <div class="h-full bg-green-500 rounded-full transition-all duration-300" style="width: {{ $this->getPercentage() }}%"></div>
                            </div>
                            <span class="text-gray-600 font-semibold">{{ $this->getPercentage() }}%</span>
                        </div>
                    @endif

                    <button wire:click="logout" class="text-xs text-gray-400 hover:text-gray-600 underline ml-auto">
                        Salir
                    </button>
                </div>
            </div>
        </div>

        {{-- Question area --}}
        <div class="flex-1 flex flex-col items-center justify-center px-4 py-8">
            <div class="w-full max-w-3xl">
                <div class="bg-white rounded-3xl shadow-lg border border-green-100 overflow-hidden">
                    {{-- Cropped image --}}
                    @if ($question->has_image && $question->cropped_image_path)
                        <img
                            src="{{ asset($question->cropped_image_path) }}"
                            alt="Imagen de la pregunta"
                            class="w-full h-auto border-b border-gray-100"
                        />
                    @endif

                    <div class="p-6 sm:p-8 space-y-5">
                        {{-- Badges --}}
                        <div class="flex flex-wrap gap-2">
                            @if ($question->area)
                                <span class="text-xs font-medium text-gray-500 bg-gray-100 px-3 py-1 rounded-full">{{ $question->area }}</span>
                            @endif
                            @if ($question->topic)
                                <span class="text-xs font-medium text-green-600 bg-green-50 px-3 py-1 rounded-full">{{ $question->topic }}</span>
                            @endif
                            @if ($question->level)
                                <span class="text-xs font-medium px-3 py-1 rounded-full
                                    {{ $question->level === 'Facil' ? 'bg-green-100 text-green-700' : '' }}
                                    {{ $question->level === 'Medio' ? 'bg-yellow-100 text-yellow-700' : '' }}
                                    {{ $question->level === 'Dificil' ? 'bg-red-100 text-red-700' : '' }}">
                                    {{ $question->level }}
                                </span>
                            @endif
                        </div>

                        {{-- Question text --}}
                        <p class="text-gray-800 text-lg leading-relaxed">{{ $question->question_text }}</p>

                        {{-- Clickable options --}}
                        @if ($question->options && count($question->options))
                            <p class="text-sm font-medium text-gray-500 pt-2">Selecciona una opción:</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach ($question->options as $letter => $text)
                                    <button
                                        wire:click="selectOption('{{ $letter }}')"
                                        wire:loading.attr="disabled"
                                        class="flex items-start gap-3 p-4 rounded-xl border-2 border-gray-200 bg-white hover:border-green-400 hover:bg-green-50 transition-all text-left group disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <span class="flex-shrink-0 w-8 h-8 rounded-full bg-green-100 text-green-700 flex items-center justify-center font-bold text-sm group-hover:bg-green-200 transition-colors">
                                            {{ $letter }}
                                        </span>
                                        <span class="text-sm text-gray-700 pt-1 group-hover:text-gray-900">{{ $text }}</span>
                                    </button>
                                @endforeach
                            </div>

                            <div wire:loading wire:target="selectOption" class="flex items-center justify-center gap-2 py-3 text-sm text-gray-400">
                                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Verificando...
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
