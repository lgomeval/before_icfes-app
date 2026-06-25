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
    public string $nickname = '';
    public string $pin = '';
    public string $loginStep = 'nickname';
    public string $pinError = '';
    public string $currentScreen = 'mapa';
    public ?string $questionSource = null;

    /** Quiz */
    public ?Question $question = null;
    public string $answer = '';
    public int $answeredCount = 0;
    public int $correctCount = 0;
    public ?string $lastExplanation = null;

    /** Filters */
    public string $selectedArea = '';
    public string $selectedTopic = '';
    public string $selectedLevel = '';

    /** Session summary */
    public int $sessionCorrect = 0;
    public int $sessionTotal = 0;
    public int $xpGained = 0;

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
            }
        }
    }

    public function login(): void
    {
        $this->validate(['nickname' => 'required|string|min:2|max:30']);

        $player = Player::findByNickname($this->nickname);

        if ($player) {
            $this->loginStep = $player->hasPin() ? 'enter_pin' : 'set_pin';
        } else {
            $player = Player::findOrCreateByNickname($this->nickname);
            $this->loginStep = 'set_pin';
        }

        PlayerLogin::record([
            'player_id' => $player->id,
            'nickname' => $this->nickname,
            'action' => 'login_attempt',
        ]);

        $this->pin = '';
        $this->pinError = '';
    }

    public function verifyPinAndLogin(): void
    {
        $this->validate([
            'pin' => 'required|string|size:4|regex:/^[0-9]{4}$/',
        ]);

        $this->pinError = '';

        $player = Player::findByNickname($this->nickname);

        if (! $player || ! $player->verifyPin($this->pin)) {
            $this->pinError = 'PIN incorrecto. Intenta de nuevo.';

            PlayerLogin::record([
                'player_id' => $player?->id,
                'nickname' => $this->nickname,
                'action' => 'login_failure',
                'failure_reason' => 'wrong_pin',
            ]);

            return;
        }

        $this->player = $player;
        session()->put('player_id', $this->player->id);

        PlayerLogin::record([
            'player_id' => $this->player->id,
            'nickname' => $this->nickname,
            'action' => 'login_success',
        ]);

        $this->loadStats();
    }

    public function setupPinAndLogin(): void
    {
        $this->validate([
            'pin' => 'required|string|size:4|regex:/^[0-9]{4}$/',
        ]);

        $player = Player::findByNickname($this->nickname);

        if (! $player) {
            $this->loginStep = 'nickname';

            return;
        }

        $player->setPin($this->pin);
        $this->player = $player;
        session()->put('player_id', $this->player->id);

        PlayerLogin::record([
            'player_id' => $this->player->id,
            'nickname' => $this->nickname,
            'action' => 'login_success',
        ]);

        $this->loadStats();
    }

    public function backToNickname(): void
    {
        $this->loginStep = 'nickname';
        $this->pin = '';
        $this->pinError = '';
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
        $this->nickname = '';
        $this->pin = '';
        $this->loginStep = 'nickname';
        $this->pinError = '';
        $this->currentScreen = 'mapa';
    }

    public function navigate(string $screen): void
    {
        $this->currentScreen = $screen;

        if ($screen === 'mapa') {
            $this->questionSource = null;
        } elseif ($screen === 'mapa_ia') {
            $this->questionSource = 'ai';
        }

        if ($screen === 'errores' && $this->player) {
            $this->loadMistakes();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Lesson / Quiz                                                       */
    /* ------------------------------------------------------------------ */

    public function startLesson(): void
    {
        $this->sessionCorrect = 0;
        $this->sessionTotal = 0;
        $this->xpGained = 0;
        $this->loadQuestion();
    }

    public function startNewSession(): void
    {
        $this->questionSource = null;
        $this->startLesson();
        $this->navigate('leccion');
    }

    public function startAISession(): void
    {
        $this->questionSource = 'ai';
        $this->startLesson();
        $this->navigate('leccion');
    }

    public function getAiQuestionsCount(): int
    {
        return Question::where('source', 'ai')->count();
    }

    public function getMapScreen(): string
    {
        return $this->questionSource === 'ai' ? 'mapa_ia' : 'mapa';
    }

    public function loadQuestion(): void
    {
        $correctIds = $this->player ? $this->player->correctAnswerIds() : [];

        $query = Question::whereNotNull('question_text')
            ->when($this->selectedArea, fn($q) => $q->byArea($this->selectedArea))
            ->when($this->selectedTopic, fn($q) => $q->byTopic($this->selectedTopic))
            ->when($this->selectedLevel, fn($q) => $q->byLevel($this->selectedLevel))
            ->bySource($this->questionSource)
            ->when(count($correctIds) > 0, fn($q) => $q->whereNotIn('id', $correctIds))
            ->random();

        $this->question = $query->first();
        $this->answer = '';
        $this->lastExplanation = null;
    }

    public function selectOption(string $letter): void
    {
        if (! $this->question || ! $this->player) {
            return;
        }

        $isCorrect = $this->isAnswerCorrect($this->question, $letter);

        $this->player->answers()->create([
            'question_id' => $this->question->id,
            'is_correct' => $isCorrect,
        ]);

        $this->sessionTotal++;

        if ($isCorrect) {
            $this->sessionCorrect++;
            $this->xpGained += $this->xpForLevel();
            $this->player->increment('xp', $this->xpForLevel());
            $this->player->increment('coins', 10);
            $this->player->increment('streak');
            $this->checkLevelUp();
            $this->dispatch('toast', type: 'correct');
        } else {
            $this->lastExplanation = $this->getExplanation($this->question, $letter);

            if ($this->player->hearts > 0) {
                $this->player->decrement('hearts');
            }

            $this->player->update(['streak' => 0]);
            $this->dispatch('toast', type: 'incorrect');
        }

        $this->loadStats();
        $this->navigate('resumen');
    }

    public function nextQuestion(): void
    {
        $this->loadQuestion();
        $this->navigate('leccion');
    }

    #[On('next-question')]
    public function handleNextQuestion(): void
    {
        $this->nextQuestion();
    }

    /* ------------------------------------------------------------------ */
    /*  Gamification                                                       */
    /* ------------------------------------------------------------------ */

    public function getPercentage(): int
    {
        if ($this->answeredCount === 0) {
            return 0;
        }

        return (int) round(($this->correctCount / $this->answeredCount) * 100);
    }

    public function xpForLevel(): int
    {
        return match (true) {
            $this->player->level <= 3 => 50,
            $this->player->level <= 6 => 100,
            $this->player->level <= 9 => 150,
            default => 200,
        };
    }

    public function xpForNextLevel(): int
    {
        return $this->player->level * 300;
    }

    public function xpProgressPercent(): int
    {
        $needed = $this->xpForNextLevel();

        return $needed > 0 ? (int) round(($this->player->xp / $needed) * 100) : 100;
    }

    private function checkLevelUp(): void
    {
        while ($this->player->xp >= $this->xpForNextLevel()) {
            $this->player->increment('level');
            $this->player->update(['hearts' => 5]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Stats                                                              */
    /* ------------------------------------------------------------------ */

    public function loadStats(): void
    {
        if (! $this->player) {
            return;
        }

        $stats = $this->player->stats();
        $this->answeredCount = $stats['total'];
        $this->correctCount = $stats['correct'];
    }

    /** @return array<int, array{id: int, question: Question, is_correct: bool}> */
    public array $mistakes = [];

    public function loadMistakes(): void
    {
        if (! $this->player) {
            return;
        }

        $this->mistakes = $this->player->answers()
            ->with('question')
            ->latest()
            ->take(20)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'question' => $a->question,
                'is_correct' => $a->is_correct,
            ])
            ->filter(fn($m) => ! $m['is_correct'] && $m['question'])
            ->values()
            ->toArray();
    }

    public function markUnderstood(int $answerId): void
    {
        if (! $this->player) {
            return;
        }

        $answer = $this->player->answers()->find($answerId);

        if ($answer && ! $answer->is_correct) {
            $answer->delete();
            $this->loadMistakes();
            $this->loadStats();
        }
    }

    public function leaderboard(): array
    {
        return Player::orderByDesc('xp')->take(10)->get()->toArray();
    }

    /* ------------------------------------------------------------------ */
    /*  Filters                                                            */
    /* ------------------------------------------------------------------ */

    public function updatedSelectedArea(): void
    {
        $this->selectedTopic = '';
    }

    public function getTopicsForArea(): array
    {
        if ($this->selectedArea === '') {
            return [];
        }

        return Question::topics()[$this->selectedArea] ?? [];
    }

    /* ------------------------------------------------------------------ */
    /*  Answer helpers                                                     */
    /* ------------------------------------------------------------------ */

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

        return "La respuesta correcta es {$correct}: {$correctText}. Revisa el razonamiento e inténtalo de nuevo.";
    }

    private array $screenMap = [
        'mapa' => 'Mapa de Aprendizaje',
        'mapa_ia' => 'Mapa con IA',
        'leccion' => 'Lección en Vivo',
        'resumen' => 'Resumen de Sesión',
        'errores' => 'Revisión de Errores',
        'ranking' => 'Tabla de Posiciones',
        'perfil' => 'Perfil y Mascota',
    ];
};
?>

<div class="flex min-h-screen" x-data="{
    transition: '',
    show: true,
    setTransition(t) { this.transition = t; this.show = false; setTimeout(() => { this.show = true; }, 50); }
}">
    {{-- Toast alert --}}
    <div
        x-data="{
            show: false,
            type: 'correct',
            messages: {
                correct: ['¡Increíble!', '¡Eres un crack!', '¡Perfecto!', '¡Imparable!', '¡Genio total!'],
                incorrect: ['¡Casi!', '¡No te rindas!', '¡Tú puedes!', '¡Ánimo, guerrero!', '¡La próxima sale!'],
            },
            emojis: {
                correct: ['🎉', '🔥', '✨', '👏', '💪'],
                incorrect: ['😅', '🤞', '🙌', '💡', '🌟'],
            },
            get message() {
                const list = this.messages[this.type];
                return list ? list[Math.floor(Math.random() * list.length)] : '';
            },
            get emoji() {
                const list = this.emojis[this.type];
                return list ? list[Math.floor(Math.random() * list.length)] : '';
            },
            showToast(event) {
                this.type = event.detail.type || 'correct';
                this.show = true;
                setTimeout(() => { this.show = false; }, 2500);
            },
        }"
        @toast.window="showToast($event)"
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 -translate-y-8 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 -translate-y-8 scale-95"
        class="fixed top-6 left-1/2 -translate-x-1/2 z-50 pointer-events-none"
        style="display: none;"
    >
        <div
            :class="type === 'correct'
                ? 'bg-green-500 border-green-700'
                : 'bg-orange-500 border-orange-700'"
            class="flex items-center gap-3 px-6 py-4 rounded-3xl border-b-4 shadow-[0_8px_0_0_rgba(0,0,0,0.15)] text-white font-black text-lg"
        >
            <span x-text="emoji" class="text-2xl"></span>
            <span x-text="message"></span>
        </div>
    </div>

    @if (! $player)
        {{-- Login / Register --}}
        <div class="flex-1 flex items-center justify-center px-4 min-h-screen">
            <div class="w-full max-w-sm animate-fade-in">
                <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm p-8 text-center">
                    <div class="text-5xl mb-4">📚</div>
                    <h1 class="text-2xl font-black text-slate-800 mb-1">ICFES Study</h1>
                    <p class="text-sm text-slate-500 font-medium mb-1">Duk Edition</p>

                    {{-- Step 1: Nickname --}}
                    @if ($loginStep === 'nickname')
                        <p class="text-slate-400 text-xs mb-6">Elige un nickname para empezar tu viaje</p>

                        <form wire:submit="login" class="space-y-4">
                            <input
                                type="text"
                                wire:model="nickname"
                                placeholder="Tu nickname..."
                                class="w-full rounded-2xl border-4 border-slate-200 focus:border-blue-500 focus:ring-0 text-lg px-4 py-3 text-center font-semibold"
                                autocomplete="off"
                                autofocus
                            />
                            @error('nickname')
                                <p class="text-red-500 text-xs font-semibold">{{ $message }}</p>
                            @enderror

                            <button type="submit" wire:loading.attr="disabled"
                                class="w-full bg-blue-500 hover:bg-blue-600 disabled:bg-slate-300 text-white font-black py-3 px-6 rounded-2xl border-b-4 border-blue-700 active:translate-y-1 active:border-b-2 transition-all text-lg shadow-[0_4px_0_0_rgba(30,64,175,1)]">
                                <span wire:loading.remove>Entrar</span>
                                <span wire:loading>Cargando...</span>
                            </button>
                        </form>

                    {{-- Step 2: Set PIN (new player or existing without PIN) --}}
                    @elseif ($loginStep === 'set_pin')
                        <p class="text-slate-400 text-xs mb-1">¡Bienvenid@ <strong class="text-slate-700">{{ $nickname }}</strong>!</p>
                        <p class="text-slate-400 text-xs mb-6">Crea un PIN de 4 dígitos para proteger tu progreso</p>

                        <form wire:submit="setupPinAndLogin" class="space-y-4">
                            <input
                                type="password"
                                wire:model="pin"
                                inputmode="numeric"
                                maxlength="4"
                                pattern="[0-9]*"
                                autocomplete="new-password"
                                placeholder="••••"
                                class="w-full rounded-2xl border-4 border-slate-200 focus:border-green-500 focus:ring-0 text-2xl px-4 py-3 text-center font-bold tracking-[0.5em]"
                                autofocus
                            />
                            @error('pin')
                                <p class="text-red-500 text-xs font-semibold">{{ $message }}</p>
                            @enderror

                            <button type="submit" wire:loading.attr="disabled"
                                class="w-full bg-green-500 hover:bg-green-600 disabled:bg-slate-300 text-white font-black py-3 px-6 rounded-2xl border-b-4 border-green-700 active:translate-y-1 active:border-b-2 transition-all text-lg shadow-[0_4px_0_0_rgba(21,128,61,1)]">
                                <span wire:loading.remove>Guardar PIN y Entrar</span>
                                <span wire:loading>Guardando...</span>
                            </button>

                            <button type="button" wire:click="backToNickname"
                                class="w-full text-xs font-bold text-slate-400 hover:text-slate-600 uppercase py-2">
                                Cambiar nickname
                            </button>
                        </form>

                    {{-- Step 3: Enter PIN (returning player) --}}
                    @elseif ($loginStep === 'enter_pin')
                        <p class="text-slate-400 text-xs mb-1">¡Hola de nuevo <strong class="text-slate-700">{{ $nickname }}</strong>!</p>
                        <p class="text-slate-400 text-xs mb-6">Ingresa tu PIN de 4 dígitos para continuar</p>

                        <form wire:submit="verifyPinAndLogin" class="space-y-4">
                            <input
                                type="password"
                                wire:model="pin"
                                inputmode="numeric"
                                maxlength="4"
                                pattern="[0-9]*"
                                autocomplete="current-password"
                                placeholder="••••"
                                class="w-full rounded-2xl border-4 border-slate-200 focus:border-blue-500 focus:ring-0 text-2xl px-4 py-3 text-center font-bold tracking-[0.5em]"
                                autofocus
                            />
                            @error('pin')
                                <p class="text-red-500 text-xs font-semibold">{{ $message }}</p>
                            @enderror
                            @if ($pinError)
                                <p class="text-red-500 text-xs font-semibold">{{ $pinError }}</p>
                            @endif

                            <button type="submit" wire:loading.attr="disabled"
                                class="w-full bg-blue-500 hover:bg-blue-600 disabled:bg-slate-300 text-white font-black py-3 px-6 rounded-2xl border-b-4 border-blue-700 active:translate-y-1 active:border-b-2 transition-all text-lg shadow-[0_4px_0_0_rgba(30,64,175,1)]">
                                <span wire:loading.remove>Entrar</span>
                                <span wire:loading>Verificando...</span>
                            </button>

                            <button type="button" wire:click="backToNickname"
                                class="w-full text-xs font-bold text-slate-400 hover:text-slate-600 uppercase py-2">
                                Cambiar nickname
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

    @else
        {{-- Sidebar (desktop) --}}
        <aside class="hidden lg:flex flex-col fixed inset-y-0 left-0 w-64 bg-white border-r-4 border-slate-200 z-20">
            <div class="p-5 border-b-4 border-slate-100">
                <button wire:click="navigate('mapa')" class="text-left">
                    <h2 class="text-lg font-black text-blue-600 leading-tight">ICFES Study</h2>
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Duk Edition</p>
                </button>
            </div>

            <nav class="flex-1 p-4 space-y-2">
                @foreach ([
                    ['id' => 'mapa', 'icon' => 'menu_book', 'label' => 'MAPA'],
                    ['id' => 'mapa_ia', 'icon' => 'smart_toy', 'label' => 'MAPA IA'],
                    ['id' => 'errores', 'icon' => 'edit_note', 'label' => 'ERRORES'],
                    ['id' => 'ranking', 'icon' => 'emoji_events', 'label' => 'RANKING'],
                    ['id' => 'perfil', 'icon' => 'person', 'label' => 'PERFIL'],
                ] as $item)
                    <button wire:click="navigate('{{ $item['id'] }}')"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-2xl font-black text-sm uppercase tracking-wider transition-all
                        {{ $currentScreen === $item['id'] ? 'bg-blue-500 text-white border-blue-700 shadow-[0_4px_0_0_rgba(30,64,175,1)]' : 'bg-slate-100 text-slate-500 border-2 border-slate-200 hover:bg-slate-50' }}">
                        <span class="material-symbols-outlined {{ $currentScreen === $item['id'] ? 'filled-icon' : '' }} text-xl">
                            {{ $item['icon'] }}
                        </span>
                        {{ $item['label'] }}
                    </button>
                @endforeach
            </nav>

            <div class="p-4 border-t-4 border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-blue-100 border-2 border-blue-300 flex items-center justify-center text-blue-600 font-black text-sm">
                        {{ strtoupper(substr($player->nickname, 0, 2)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-slate-700 truncate">{{ $player->nickname }}</p>
                        <p class="text-[10px] font-black uppercase text-slate-400">Nivel {{ $player->level }}</p>
                    </div>
                </div>
                <div class="mt-3">
                    <div class="flex justify-between text-[9px] font-black uppercase text-slate-400 mb-1">
                        <span>XP</span>
                        <span>{{ $player->xp }} / {{ $this->xpForNextLevel() }}</span>
                    </div>
                    <div class="h-2 bg-slate-100 rounded-full overflow-hidden border border-slate-200">
                        <div class="h-full bg-blue-500 rounded-full transition-all" style="width: {{ $this->xpProgressPercent() }}%"></div>
                    </div>
                </div>
                <button wire:click="logout" class="mt-3 text-[10px] font-bold text-slate-400 hover:text-red-500 uppercase tracking-wider">
                    Salir
                </button>
            </div>
        </aside>

        {{-- Main content --}}
        <div class="flex-1 lg:pl-64 w-full pb-20 lg:pb-0">
            {{-- Mapa de Aprendizaje --}}
            @if ($currentScreen === 'mapa')
                <div class="animate-fade-in" x-transition>
                    <div class="sticky top-0 z-10 bg-[#faf9f5]/95 backdrop-blur-md border-b-4 border-slate-200">
                        <div class="max-w-4xl mx-auto px-4 py-3">
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Study Duk - Learn</p>
                            <h1 class="text-xl font-black text-slate-800">Mapa de Aprendizaje</h1>
                        </div>
                    </div>

                    <div class="max-w-4xl mx-auto px-4 py-6 space-y-5">
                        {{-- Gamification badges --}}
                        <div class="flex gap-3 flex-wrap">
                            <div class="flex items-center gap-2 bg-white rounded-full border-2 border-orange-200 px-4 py-2 shadow-sm">
                                <span class="material-symbols-outlined text-amber-500 text-lg">local_fire_department</span>
                                <span class="text-xs font-black text-slate-600">{{ $player->streak }} Días</span>
                            </div>
                            <div class="flex items-center gap-2 bg-white rounded-full border-2 border-red-200 px-4 py-2 shadow-sm">
                                <span class="material-symbols-outlined text-red-400 text-lg">favorite</span>
                                <span class="text-xs font-black text-slate-600">{{ max(0, $player->hearts) }} Vidas</span>
                            </div>
                            <div class="flex items-center gap-2 bg-white rounded-full border-2 border-amber-200 px-4 py-2 shadow-sm">
                                <span class="material-symbols-outlined text-amber-500 text-lg">monetization_on</span>
                                <span class="text-xs font-black text-slate-600">{{ $player->coins }} Won</span>
                            </div>
                        </div>

                        {{-- Banner card --}}
                        <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm overflow-hidden relative pojagi-pattern">
                            <div class="relative p-6 sm:p-8">
                                <span class="inline-block bg-blue-100 text-blue-700 text-[10px] font-black uppercase tracking-widest px-4 py-1.5 rounded-full border-2 border-blue-200 mb-4">
                                    Lectura Crítica
                                </span>
                                <h2 class="text-xl font-black text-slate-800 mb-2">Análisis de Textos y Preguntas ICFES</h2>
                                <p class="text-sm text-slate-500 mb-5">Practica con preguntas reales del examen. Mejora tu comprensión lectora, razonamiento matemático y habilidades científicas.</p>
                                <button wire:click="startNewSession"
                                    class="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white font-black px-6 py-3 rounded-2xl border-b-4 border-green-700 active:translate-y-1 active:border-b-2 transition-all shadow-[0_4px_0_0_rgba(21,128,61,1)]">
                                    <span class="material-symbols-outlined">play_arrow</span>
                                    Continuar Guía
                                </button>
                            </div>
                        </div>

                        {{-- Roadmap --}}
                        <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm p-6">
                            <h3 class="text-sm font-black text-slate-500 uppercase tracking-wider mb-6">Tu Progreso</h3>

                            <div class="relative pl-8">
                                {{-- Vertical line --}}
                                <div class="absolute left-[19px] top-0 bottom-0 w-0.5 bg-slate-200 border-l-2 border-dashed border-slate-300"></div>

                                {{-- Node: Completado --}}
                                <div class="relative mb-6">
                                    <div class="absolute -left-[35px] top-1 w-10 h-10 rounded-full bg-green-100 border-4 border-green-300 flex items-center justify-center z-10">
                                        <span class="material-symbols-outlined text-green-600 text-lg">check</span>
                                    </div>
                                    <div class="ml-4 p-4 bg-green-50/50 rounded-2xl border-2 border-green-100">
                                        <p class="text-sm font-bold text-green-700">Bienvenida</p>
                                        <p class="text-xs text-green-600">Completado</p>
                                    </div>
                                </div>

                                {{-- Node: En curso --}}
                                <div class="relative mb-6">
                                    <div class="absolute -left-[35px] top-1 w-10 h-10 rounded-full bg-blue-100 border-4 border-blue-500 flex items-center justify-center z-10">
                                        <div class="absolute inset-0 rounded-full border-4 border-blue-400 animate-ping opacity-20"></div>
                                        <span class="material-symbols-outlined text-blue-600 text-lg filled-icon">bolt</span>
                                    </div>
                                    <div class="ml-4 p-4 bg-blue-50 rounded-2xl border-4 border-blue-500 shadow-[0_4px_0_0_rgba(59,130,246,1)]">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-[9px] font-black uppercase tracking-widest bg-blue-500 text-white px-2 py-0.5 rounded-full">EN CURSO</span>
                                        </div>
                                        <p class="text-sm font-black text-slate-800">Práctica General ICFES</p>
                                        <p class="text-xs text-blue-600 font-semibold mt-1">
                                            {{ $answeredCount }} preguntas respondidas
                                        </p>
                                        <div class="mt-3">
                                            <div class="flex justify-between text-[9px] font-black uppercase text-blue-400 mb-1">
                                                <span>Progreso</span><span>{{ $answeredCount }} / {{ $answeredCount + 5 }}</span>
                                            </div>
                                            <div class="h-2 bg-blue-100 rounded-full border border-blue-200">
                                                <div class="h-full bg-blue-500 rounded-full" style="width: {{ min($answeredCount * 5, 100) }}%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Node: Bloqueado --}}
                                <div class="relative">
                                    <div class="absolute -left-[35px] top-1 w-10 h-10 rounded-full bg-slate-100 border-4 border-slate-300 flex items-center justify-center z-10 opacity-50">
                                        <span class="material-symbols-outlined text-slate-400 text-lg">lock</span>
                                    </div>
                                    <div class="ml-4 p-4 bg-slate-50 rounded-2xl border-2 border-dashed border-slate-300 opacity-50">
                                        <p class="text-sm font-bold text-slate-500">Simulacro Completo</p>
                                        <p class="text-xs text-slate-400">Bloqueado — Completa la práctica general primero</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Stats overview --}}
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            @foreach ([
                                ['label' => 'Correctas', 'value' => $correctCount, 'color' => 'text-green-600 bg-green-50 border-green-200'],
                                ['label' => 'Incorrectas', 'value' => $answeredCount - $correctCount, 'color' => 'text-red-500 bg-red-50 border-red-200'],
                                ['label' => 'Precisión', 'value' => $this->getPercentage().'%', 'color' => 'text-blue-600 bg-blue-50 border-blue-200'],
                                ['label' => 'Nivel', 'value' => $player->level, 'color' => 'text-amber-600 bg-amber-50 border-amber-200'],
                            ] as $stat)
                                <div class="bg-white rounded-2xl border-4 {{ $stat['color'] }} shadow-sm p-4 text-center">
                                    <p class="text-[10px] font-black uppercase tracking-widest {{ explode(' ', $stat['color'])[0] }} mb-1">{{ $stat['label'] }}</p>
                                    <p class="text-2xl font-black {{ explode(' ', $stat['color'])[0] }}">{{ $stat['value'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

            {{-- Mapa con IA --}}
            @elseif ($currentScreen === 'mapa_ia')
                <div class="animate-fade-in" x-transition>
                    <div class="sticky top-0 z-10 bg-[#faf9f5]/95 backdrop-blur-md border-b-4 border-purple-200">
                        <div class="max-w-4xl mx-auto px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="inline-block bg-purple-100 text-purple-700 text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full border border-purple-300">
                                    IA
                                </span>
                            </div>
                            <h1 class="text-xl font-black text-slate-800">Mapa con IA</h1>
                        </div>
                    </div>

                    <div class="max-w-4xl mx-auto px-4 py-6 space-y-5">
                        {{-- Gamification badges --}}
                        <div class="flex gap-3 flex-wrap">
                            <div class="flex items-center gap-2 bg-white rounded-full border-2 border-orange-200 px-4 py-2 shadow-sm">
                                <span class="material-symbols-outlined text-amber-500 text-lg">local_fire_department</span>
                                <span class="text-xs font-black text-slate-600">{{ $player->streak }} Días</span>
                            </div>
                            <div class="flex items-center gap-2 bg-white rounded-full border-2 border-red-200 px-4 py-2 shadow-sm">
                                <span class="material-symbols-outlined text-red-400 text-lg">favorite</span>
                                <span class="text-xs font-black text-slate-600">{{ max(0, $player->hearts) }} Vidas</span>
                            </div>
                            <div class="flex items-center gap-2 bg-white rounded-full border-2 border-amber-200 px-4 py-2 shadow-sm">
                                <span class="material-symbols-outlined text-amber-500 text-lg">monetization_on</span>
                                <span class="text-xs font-black text-slate-600">{{ $player->coins }} Won</span>
                            </div>
                        </div>

                        {{-- Banner card --}}
                        <div class="bg-white rounded-3xl border-4 border-purple-200 shadow-sm overflow-hidden relative">
                            <div class="absolute inset-0 opacity-5" style="background: repeating-linear-gradient(45deg, #7c3aed, #7c3aed 2px, transparent 2px, transparent 10px);"></div>
                            <div class="relative p-6 sm:p-8">
                                <div class="flex items-center gap-2 mb-4">
                                    <span class="inline-flex items-center gap-1 bg-purple-100 text-purple-700 text-[10px] font-black uppercase tracking-widest px-4 py-1.5 rounded-full border-2 border-purple-200">
                                        <span class="material-symbols-outlined text-sm">smart_toy</span>
                                        Inteligencia Artificial
                                    </span>
                                </div>
                                <h2 class="text-xl font-black text-slate-800 mb-2">Preguntas Generadas por IA</h2>
                                <p class="text-sm text-slate-500 mb-5">Practica con preguntas generadas por IA, alineadas al formato real del examen ICFES Saber 11. Preguntas contextuales, con razonamiento y análisis.</p>

                                @if ($this->getAiQuestionsCount() === 0)
                                    <div class="bg-amber-50 rounded-2xl border-4 border-amber-200 p-4 mb-4">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="material-symbols-outlined text-amber-500">info</span>
                                            <p class="text-[10px] font-black uppercase tracking-widest text-amber-600">Sin preguntas IA</p>
                                        </div>
                                        <p class="text-xs text-amber-700">Aún no hay preguntas generadas. Ejecuta el comando:</p>
                                        <code class="block mt-1 text-xs bg-amber-100 text-amber-800 p-2 rounded-xl font-mono">php artisan questions:generate-ai --all --count=30</code>
                                    </div>
                                @else
                                    <button wire:click="startAISession"
                                        class="inline-flex items-center gap-2 bg-purple-500 hover:bg-purple-600 text-white font-black px-6 py-3 rounded-2xl border-b-4 border-purple-700 active:translate-y-1 active:border-b-2 transition-all shadow-[0_4px_0_0_rgba(107,33,168,1)]">
                                        <span class="material-symbols-outlined">play_arrow</span>
                                        Iniciar Sesión IA
                                    </button>
                                @endif
                            </div>
                        </div>

                        {{-- Roadmap --}}
                        <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm p-6">
                            <h3 class="text-sm font-black text-slate-500 uppercase tracking-wider mb-6">Tu Progreso</h3>

                            <div class="relative pl-8">
                                <div class="absolute left-[19px] top-0 bottom-0 w-0.5 bg-slate-200 border-l-2 border-dashed border-slate-300"></div>

                                <div class="relative mb-6">
                                    <div class="absolute -left-[35px] top-1 w-10 h-10 rounded-full bg-green-100 border-4 border-green-300 flex items-center justify-center z-10">
                                        <span class="material-symbols-outlined text-green-600 text-lg">check</span>
                                    </div>
                                    <div class="ml-4 p-4 bg-green-50/50 rounded-2xl border-2 border-green-100">
                                        <p class="text-sm font-bold text-green-700">Bienvenida</p>
                                        <p class="text-xs text-green-600">Completado</p>
                                    </div>
                                </div>

                                <div class="relative mb-6">
                                    <div class="absolute -left-[35px] top-1 w-10 h-10 rounded-full bg-purple-100 border-4 border-purple-500 flex items-center justify-center z-10">
                                        <div class="absolute inset-0 rounded-full border-4 border-purple-400 animate-ping opacity-20"></div>
                                        <span class="material-symbols-outlined text-purple-600 text-lg filled-icon">smart_toy</span>
                                    </div>
                                    <div class="ml-4 p-4 bg-purple-50 rounded-2xl border-4 border-purple-500 shadow-[0_4px_0_0_rgba(147,51,234,1)]">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-[9px] font-black uppercase tracking-widest bg-purple-500 text-white px-2 py-0.5 rounded-full">EN CURSO</span>
                                        </div>
                                        <p class="text-sm font-black text-slate-800">Práctica con IA - ICFES Real</p>
                                        <p class="text-xs text-purple-600 font-semibold mt-1">
                                            {{ $answeredCount }} preguntas respondidas
                                        </p>
                                        <div class="mt-3">
                                            <div class="flex justify-between text-[9px] font-black uppercase text-purple-400 mb-1">
                                                <span>Progreso</span><span>{{ $answeredCount }} / {{ $answeredCount + 5 }}</span>
                                            </div>
                                            <div class="h-2 bg-purple-100 rounded-full border border-purple-200">
                                                <div class="h-full bg-purple-500 rounded-full" style="width: {{ min($answeredCount * 5, 100) }}%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="relative">
                                    <div class="absolute -left-[35px] top-1 w-10 h-10 rounded-full bg-slate-100 border-4 border-slate-300 flex items-center justify-center z-10 opacity-50">
                                        <span class="material-symbols-outlined text-slate-400 text-lg">lock</span>
                                    </div>
                                    <div class="ml-4 p-4 bg-slate-50 rounded-2xl border-2 border-dashed border-slate-300 opacity-50">
                                        <p class="text-sm font-bold text-slate-500">Simulacro IA Completo</p>
                                        <p class="text-xs text-slate-400">Bloqueado — Completa la práctica IA primero</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Stats overview --}}
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            @foreach ([
                                ['label' => 'Correctas', 'value' => $correctCount, 'color' => 'text-green-600 bg-green-50 border-green-200'],
                                ['label' => 'Incorrectas', 'value' => $answeredCount - $correctCount, 'color' => 'text-red-500 bg-red-50 border-red-200'],
                                ['label' => 'Precisión', 'value' => $this->getPercentage().'%', 'color' => 'text-purple-600 bg-purple-50 border-purple-200'],
                                ['label' => 'Nivel', 'value' => $player->level, 'color' => 'text-amber-600 bg-amber-50 border-amber-200'],
                            ] as $stat)
                                <div class="bg-white rounded-2xl border-4 {{ $stat['color'] }} shadow-sm p-4 text-center">
                                    <p class="text-[10px] font-black uppercase tracking-widest {{ explode(' ', $stat['color'])[0] }} mb-1">{{ $stat['label'] }}</p>
                                    <p class="text-2xl font-black {{ explode(' ', $stat['color'])[0] }}">{{ $stat['value'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

            {{-- Lección en Vivo --}}
            @elseif ($currentScreen === 'leccion' && $question)
                <div class="animate-fade-in" x-transition>
                    <div class="sticky top-0 z-10 bg-[#faf9f5]/95 backdrop-blur-md border-b-4 border-slate-200">
                        <div class="max-w-4xl mx-auto px-4 py-3 flex items-center gap-3">
                            <button wire:click="navigate('{{ $this->getMapScreen() }}')" class="w-10 h-10 rounded-2xl bg-white border-2 border-slate-200 flex items-center justify-center hover:bg-slate-50">
                                <span class="material-symbols-outlined text-slate-500">close</span>
                            </button>
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <div class="w-7 h-7 rounded-xl bg-blue-100 border-2 border-blue-300 flex items-center justify-center text-blue-600 font-black text-[10px]">
                                        {{ strtoupper(substr($player->nickname, 0, 2)) }}
                                    </div>
                                    <span class="text-xs font-bold text-slate-500">{{ $player->nickname }}</span>
                                    <span class="text-[10px] font-black text-red-500 ml-auto flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm">favorite</span>
                                        {{ max(0, $player->hearts) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="max-w-3xl mx-auto px-4 py-6">
                        <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm overflow-hidden">
                            @if ($question->has_image && $question->cropped_image_path)
                                <img src="{{ asset($question->cropped_image_path) }}" alt="Pregunta" class="w-full h-auto border-b-4 border-slate-100" />
                            @endif

                            <div class="p-6 sm:p-8 space-y-5">
                                {{-- Badges --}}
                                <div class="flex flex-wrap gap-2">
                                    @if ($question->area)
                                        <span class="text-[9px] font-black uppercase tracking-widest bg-red-100 text-red-700 px-3 py-1 rounded-full border-2 border-red-200">
                                            {{ $question->area }}
                                        </span>
                                    @endif
                                    @if ($question->level)
                                        <span class="text-[9px] font-black uppercase tracking-widest px-3 py-1 rounded-full border-2
                                            {{ $question->level === 'Facil' ? 'bg-green-100 text-green-700 border-green-200' : '' }}
                                            {{ $question->level === 'Medio' ? 'bg-yellow-100 text-yellow-700 border-yellow-200' : '' }}
                                            {{ $question->level === 'Dificil' ? 'bg-red-100 text-red-700 border-red-200' : '' }}">
                                            {{ $question->level }}
                                        </span>
                                    @endif
                                </div>

                                <p class="text-lg font-bold text-slate-800 leading-relaxed">{{ $question->question_text }}</p>

                                @if ($question->options && count($question->options))
                                    <p class="text-xs font-black uppercase tracking-wider text-slate-400">Selecciona una opción</p>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        @foreach ($question->options as $letter => $text)
                                            <button wire:click="selectOption('{{ $letter }}')" wire:loading.attr="disabled"
                                                class="flex items-start gap-3 p-4 rounded-2xl border-4 border-slate-200 bg-white hover:border-blue-400 hover:bg-blue-50 transition-all text-left group disabled:opacity-50">
                                                <span class="flex-shrink-0 w-8 h-8 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center font-black text-sm group-hover:bg-blue-100 group-hover:text-blue-700 transition-colors border-2 border-slate-200 group-hover:border-blue-300">
                                                    {{ $letter }}
                                                </span>
                                                <span class="text-sm font-medium text-slate-700 pt-1 group-hover:text-slate-900">{{ $text }}</span>
                                            </button>
                                        @endforeach
                                    </div>

                                    <div wire:loading wire:target="selectOption" class="flex items-center justify-center gap-2 py-3 text-sm text-slate-400 font-semibold">
                                        <div class="w-4 h-4 border-2 border-slate-300 border-t-blue-500 rounded-full animate-spin"></div>
                                        Verificando...
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

            {{-- No question --}}
            @elseif ($currentScreen === 'leccion' && ! $question)
                <div class="flex items-center justify-center min-h-[60vh]">
                    <div class="text-center bg-white rounded-3xl border-4 border-slate-200 shadow-sm p-12">
                        <span class="material-symbols-outlined text-5xl text-green-500 mb-3">check_circle</span>
                        <p class="text-xl font-black text-slate-800 mb-2">¡Completaste todo!</p>
                        <p class="text-sm text-slate-500 mb-4">No hay más preguntas disponibles con estos filtros.</p>
                        <button wire:click="navigate('{{ $this->getMapScreen() }}')"
                            class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-5 py-2.5 rounded-2xl border-2 border-slate-200">
                            Volver al Mapa
                        </button>
                    </div>
                </div>

            {{-- Resumen de Sesión --}}
            @elseif ($currentScreen === 'resumen')
                <div class="animate-fade-in" x-transition>
                    <div class="max-w-2xl mx-auto px-4 py-8">
                        <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm p-8 text-center">
                            <div class="text-6xl mb-4">{{ $sessionCorrect === $sessionTotal ? '🐯' : '😿' }}</div>
                            <p class="text-sm font-black text-slate-400 uppercase tracking-widest mb-2">Sesión Completada</p>
                            <h1 class="text-2xl font-black text-slate-800 mb-6">
                                @if ($sessionCorrect === $sessionTotal)
                                    ¡Perfecto!
                                @elseif ($sessionCorrect === 0)
                                    ¡Respuesta incorrecta!
                                @else
                                    ¡Sigue practicando!
                                @endif
                            </h1>

                            {{-- Result --}}
                            <div class="grid grid-cols-3 gap-3 mb-6">
                                <div class="rounded-2xl border-4 p-4 {{ $xpGained > 0 ? 'bg-green-50 border-green-300' : 'bg-slate-50 border-slate-200' }}">
                                    <p class="text-[10px] font-black uppercase tracking-widest {{ $xpGained > 0 ? 'text-green-600' : 'text-slate-400' }} mb-1">XP Ganado</p>
                                    <p class="text-2xl font-black {{ $xpGained > 0 ? 'text-green-700' : 'text-slate-400' }}">{{ $xpGained > 0 ? '+' : '' }}{{ $xpGained }}</p>
                                </div>
                                <div class="rounded-2xl border-4 p-4 {{ $sessionCorrect === $sessionTotal ? 'bg-green-50 border-green-300' : 'bg-red-50 border-red-300' }}">
                                    <p class="text-[10px] font-black uppercase tracking-widest {{ $sessionCorrect === $sessionTotal ? 'text-green-600' : 'text-red-500' }} mb-1">Resultado</p>
                                    <p class="text-2xl font-black {{ $sessionCorrect === $sessionTotal ? 'text-green-700' : 'text-red-500' }}">
                                        {{ $sessionCorrect }}/{{ $sessionTotal }}
                                    </p>
                                </div>
                                <div class="bg-amber-50 rounded-2xl border-4 border-amber-300 p-4">
                                    <p class="text-[10px] font-black uppercase tracking-widest text-amber-600 mb-1">Racha</p>
                                    <p class="text-2xl font-black text-amber-700">{{ $player->streak }} Días</p>
                                </div>
                            </div>

                            {{-- Explanation when wrong --}}
                            @if ($lastExplanation && $sessionCorrect < $sessionTotal)
                                <div class="bg-red-50 rounded-2xl border-4 border-red-200 p-5 mb-6 text-left">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-red-500">tips_and_updates</span>
                                        <p class="text-[10px] font-black uppercase tracking-widest text-red-500">Explicación</p>
                                    </div>
                                    <p class="text-sm text-red-700 leading-relaxed">{{ $lastExplanation }}</p>
                                </div>
                            @endif

                            {{-- XP Bar --}}
                            <div class="mb-6">
                                <div class="flex justify-between text-[9px] font-black uppercase text-slate-400 mb-1">
                                    <span>Nivel {{ $player->level }}</span>
                                    <span>{{ $player->xp }} / {{ $this->xpForNextLevel() }} XP</span>
                                </div>
                                <div class="h-3 bg-slate-100 rounded-full border-2 border-slate-200 overflow-hidden">
                                    <div class="h-full bg-blue-500 rounded-full transition-all" style="width: {{ $this->xpProgressPercent() }}%"></div>
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex flex-col sm:flex-row gap-3">
                                <button wire:click="nextQuestion"
                                    class="flex-1 bg-green-500 hover:bg-green-600 text-white font-black py-3 px-6 rounded-2xl border-b-4 border-green-700 active:translate-y-1 active:border-b-2 transition-all shadow-[0_4px_0_0_rgba(21,128,61,1)]">
                                    Continuar
                                </button>
                                @if ($sessionCorrect < $sessionTotal)
                                    <button wire:click="navigate('errores')"
                                        class="flex-1 bg-white hover:bg-slate-50 text-slate-700 font-bold py-3 px-6 rounded-2xl border-4 border-slate-200 active:translate-y-1 active:border-b-2 transition-all">
                                        Revisar Errores
                                    </button>
                                @endif
                            </div>

                            <button wire:click="navigate('{{ $this->getMapScreen() }}')" class="mt-4 text-xs font-bold text-slate-400 hover:text-slate-600 uppercase">
                                Volver al Mapa
                            </button>
                        </div>
                    </div>
                </div>

            {{-- Revisión de Errores --}}
            @elseif ($currentScreen === 'errores')
                <div class="animate-fade-in" x-transition>
                    <div class="sticky top-0 z-10 bg-[#faf9f5]/95 backdrop-blur-md border-b-4 border-slate-200">
                        <div class="max-w-4xl mx-auto px-4 py-3">
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Estudio Personalizado</p>
                            <h1 class="text-xl font-black text-slate-800">Revisión de Errores</h1>
                        </div>
                    </div>

                    <div class="max-w-4xl mx-auto px-4 py-6 space-y-4">
                        @if (count($mistakes) === 0)
                            <div class="text-center bg-white rounded-3xl border-4 border-slate-200 shadow-sm p-12">
                                <span class="material-symbols-outlined text-5xl text-green-500">verified_user</span>
                                <p class="text-xl font-black text-slate-800 mt-3">¡Carpeta Limpia!</p>
                                <p class="text-sm text-slate-500">No tienes errores por revisar.</p>
                            </div>
                        @else
                            @foreach ($mistakes as $mistake)
                                @php $mq = $mistake['question']; @endphp
                                @if ($mq)
                                    <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm overflow-hidden">
                                        <div class="p-5 space-y-4">
                                            <div class="flex items-start gap-3">
                                                <span class="flex-shrink-0 w-10 h-10 bg-red-100 border-2 border-red-300 rounded-2xl flex items-center justify-center text-red-600 material-symbols-outlined text-lg">close</span>
                                                <div>
                                                    <span class="text-[10px] font-black uppercase tracking-widest bg-red-100 text-red-700 px-2 py-0.5 rounded-full border border-red-200">
                                                        {{ $mq->area ?? 'General' }}
                                                    </span>
                                                    <p class="text-sm font-bold text-slate-800 mt-2">{{ $mq->question_text }}</p>
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div class="bg-red-50 rounded-2xl border-4 border-red-200 p-4">
                                                    <p class="text-[9px] font-black uppercase tracking-widest text-red-500 mb-2">Tu Respuesta</p>
                                                    <p class="text-sm font-bold text-red-700">Incorrecta ❌</p>
                                                </div>
                                                <div class="bg-green-50 rounded-2xl border-4 border-green-200 p-4">
                                                    <p class="text-[9px] font-black uppercase tracking-widest text-green-600 mb-2">Respuesta Correcta</p>
                                                    <p class="text-sm font-bold text-green-700">
                                                        {{ $mq->correct_answer }}
                                                        @if ($mq->options && isset($mq->options[$mq->correct_answer]))
                                                            — {{ $mq->options[$mq->correct_answer] }}
                                                        @endif
                                                    </p>
                                                </div>
                                            </div>

                                            @if ($mq->explanation)
                                                <div class="bg-slate-50 rounded-2xl border-2 border-slate-200 p-4">
                                                    <div class="flex items-center gap-2 mb-2">
                                                        <span class="material-symbols-outlined text-amber-500 text-sm">tips_and_updates</span>
                                                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">Explicación</p>
                                                    </div>
                                                    <p class="text-sm text-slate-600 leading-relaxed">{{ $mq->explanation }}</p>
                                                </div>
                                            @endif

                                            <div class="flex flex-wrap gap-3">
                                                <button wire:click="markUnderstood({{ $mistake['id'] }})"
                                                    class="inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-white font-black px-5 py-2.5 rounded-2xl border-b-4 border-amber-700 active:translate-y-1 active:border-b-2 transition-all shadow-[0_4px_0_0_rgba(180,83,9,1)] text-sm">
                                                    <span class="material-symbols-outlined text-lg">check</span>
                                                    Entendido
                                                </button>
                                                <button wire:click="navigate('leccion')"
                                                    class="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white font-black px-5 py-2.5 rounded-2xl border-b-4 border-green-700 active:translate-y-1 active:border-b-2 transition-all shadow-[0_4px_0_0_rgba(21,128,61,1)] text-sm">
                                                    <span class="material-symbols-outlined text-lg">autorenew</span>
                                                    Seguir Practicando
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        @endif
                    </div>
                </div>

            {{-- Tabla de Posiciones --}}
            @elseif ($currentScreen === 'ranking')
                <div class="animate-fade-in" x-transition>
                    @php $leaders = $this->leaderboard(); @endphp
                    <div class="sticky top-0 z-10 bg-[#faf9f5]/95 backdrop-blur-md border-b-4 border-slate-200">
                        <div class="max-w-4xl mx-auto px-4 py-3">
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Comunidad de Eruditos</p>
                            <h1 class="text-xl font-black text-slate-800">Tabla de Posiciones</h1>
                        </div>
                    </div>

                    <div class="max-w-4xl mx-auto px-4 py-6 space-y-4">
                        @if (count($leaders) >= 3)
                            {{-- Podium --}}
                            <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm p-6">
                                <div class="grid grid-cols-3 gap-3 items-end">
                                    @php
                                        $podium = [$leaders[1] ?? null, $leaders[0] ?? null, $leaders[2] ?? null]; // 2nd, 1st, 3rd
                                        $medals = ['🥈', '👑', '🥉'];
                                        $colors = ['border-slate-300 bg-slate-50', 'border-amber-400 bg-amber-50', 'border-orange-300 bg-orange-50'];
                                        $sizes = ['h-24', 'h-32', 'h-20'];
                                    @endphp
                                    @foreach ($podium as $i => $p)
                                        @if ($p)
                                            <div class="text-center">
                                                <div class="text-2xl mb-2 {{ $i === 1 ? 'animate-bounce' : '' }}">{{ $medals[$i] }}</div>
                                                <div class="w-14 h-14 mx-auto rounded-2xl {{ $colors[$i] }} border-4 flex items-center justify-center font-black text-lg text-slate-600">
                                                    {{ strtoupper(substr($p['nickname'], 0, 2)) }}
                                                </div>
                                                <p class="text-xs font-bold text-slate-700 mt-2 truncate">{{ $p['nickname'] }}</p>
                                                <p class="text-[10px] font-black text-slate-400">{{ $p['xp'] }} XP</p>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Ranking list --}}
                        <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm divide-y-2 divide-slate-100 overflow-hidden">
                            @foreach ($leaders as $index => $leader)
                                <div class="flex items-center gap-3 p-4 {{ $player && $leader['id'] === $player->id ? 'bg-blue-50 border-l-4 border-blue-500' : '' }}">
                                    <span class="w-8 text-center font-black text-sm {{ $index < 3 ? 'text-amber-500' : 'text-slate-400' }}">
                                        #{{ $index + 1 }}
                                    </span>
                                    <div class="w-9 h-9 rounded-xl bg-slate-100 border-2 border-slate-200 flex items-center justify-center font-black text-xs text-slate-500">
                                        {{ strtoupper(substr($leader['nickname'], 0, 2)) }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-bold text-slate-700 truncate">
                                            {{ $leader['nickname'] }}
                                            @if ($player && $leader['id'] === $player->id)
                                                <span class="text-[9px] font-black bg-blue-500 text-white px-2 py-0.5 rounded-full ml-1">Tú</span>
                                            @endif
                                        </p>
                                        <p class="text-[10px] text-slate-400 font-medium">Nivel {{ $leader['level'] }}</p>
                                    </div>
                                    <span class="text-sm font-black text-slate-600">{{ $leader['xp'] }} XP</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

            {{-- Perfil y Mascota --}}
            @elseif ($currentScreen === 'perfil')
                <div class="animate-fade-in" x-transition>
                    <div class="sticky top-0 z-10 bg-[#faf9f5]/95 backdrop-blur-md border-b-4 border-slate-200">
                        <div class="max-w-4xl mx-auto px-4 py-3">
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Tu Sello Escolar</p>
                            <h1 class="text-xl font-black text-slate-800">Perfil y Mascota</h1>
                        </div>
                    </div>

                    <div class="max-w-4xl mx-auto px-4 py-6 grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {{-- Mascot --}}
                        <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm p-6 text-center">
                            <div class="text-8xl mb-4">🐯</div>
                            <p class="text-lg font-black text-slate-800">{{ $player->nickname }}</p>
                            <p class="text-sm text-slate-500 font-medium">Horangi, el Tigre Erudito</p>

                            <div class="flex justify-center gap-3 mt-4">
                                <span class="bg-amber-100 text-amber-700 text-[10px] font-black uppercase px-3 py-1 rounded-full border-2 border-amber-200">
                                    {{ $player->coins }} Won
                                </span>
                                <span class="bg-blue-100 text-blue-700 text-[10px] font-black uppercase px-3 py-1 rounded-full border-2 border-blue-200">
                                    Nivel {{ $player->level }}
                                </span>
                            </div>
                        </div>

                        {{-- Stats --}}
                        <div class="bg-white rounded-3xl border-4 border-slate-200 shadow-sm p-6">
                            <h3 class="text-sm font-black text-slate-500 uppercase tracking-wider mb-4">Estadísticas</h3>
                            <div class="grid grid-cols-2 gap-3">
                                @foreach ([
                                    ['label' => 'Racha', 'value' => $player->streak.' Días', 'icon' => 'local_fire_department', 'color' => 'text-orange-500'],
                                    ['label' => 'Total Preguntas', 'value' => $answeredCount, 'icon' => 'analytics', 'color' => 'text-blue-500'],
                                    ['label' => 'Precisión', 'value' => $this->getPercentage().'%', 'icon' => 'check_circle', 'color' => 'text-green-500'],
                                    ['label' => 'Nivel', 'value' => $player->level, 'icon' => 'workspace_premium', 'color' => 'text-amber-500'],
                                ] as $stat)
                                    <div class="bg-slate-50 rounded-2xl border-2 border-slate-200 p-4 text-center">
                                        <span class="material-symbols-outlined {{ $stat['color'] }} text-2xl">{{ $stat['icon'] }}</span>
                                        <p class="text-lg font-black text-slate-700 mt-1">{{ $stat['value'] }}</p>
                                        <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">{{ $stat['label'] }}</p>
                                    </div>
                                @endforeach
                            </div>

                            {{-- XP bar --}}
                            <div class="mt-4">
                                <div class="flex justify-between text-[9px] font-black uppercase text-slate-400 mb-1">
                                    <span>Progreso Nivel {{ $player->level }}</span>
                                    <span>{{ $player->xp }} / {{ $this->xpForNextLevel() }} XP</span>
                                </div>
                                <div class="h-3 bg-slate-100 rounded-full border-2 border-slate-200 overflow-hidden">
                                    <div class="h-full bg-blue-500 rounded-full transition-all" style="width: {{ $this->xpProgressPercent() }}%"></div>
                                </div>
                            </div>
                        </div>

                        {{-- Medals --}}
                        <div class="lg:col-span-2 bg-white rounded-3xl border-4 border-slate-200 shadow-sm p-6">
                            <h3 class="text-sm font-black text-slate-500 uppercase tracking-wider mb-4">Medallas de Erudición</h3>
                            <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                                @php
                                    $medals = [
                                        ['name' => 'Enfoque', 'icon' => '🧘', 'unlocked' => $answeredCount >= 5, 'desc' => '5 preguntas respondidas'],
                                        ['name' => 'Sabio', 'icon' => '🦉', 'unlocked' => $correctCount >= 3, 'desc' => '3 respuestas correctas'],
                                        ['name' => 'Cumbre', 'icon' => '⛰️', 'unlocked' => $player->level >= 3, 'desc' => 'Alcanza nivel 3'],
                                        ['name' => 'Perfecto', 'icon' => '💎', 'unlocked' => $correctCount >= 10 && $answeredCount >= 10 && $correctCount === $answeredCount, 'desc' => '10/10 perfectas'],
                                    ];
                                @endphp
                                @foreach ($medals as $medal)
                                    <div class="rounded-2xl border-4 p-4 text-center transition-all {{ $medal['unlocked'] ? 'bg-white border-slate-200 hover:scale-105' : 'bg-slate-50 border-dashed border-slate-300 opacity-40' }}">
                                        <div class="text-3xl mb-2">{{ $medal['icon'] }}</div>
                                        <p class="text-xs font-black text-slate-700">{{ $medal['name'] }}</p>
                                        <p class="text-[9px] text-slate-400 font-medium">{{ $medal['desc'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Bottom NavBar (mobile) --}}
        <nav class="lg:hidden fixed bottom-0 inset-x-0 bg-white border-t-4 border-slate-200 z-20">
            <div class="flex items-center justify-around py-2 px-2">
                @foreach ([
                    ['id' => 'mapa', 'icon' => 'menu_book', 'label' => 'MAPA'],
                    ['id' => 'mapa_ia', 'icon' => 'smart_toy', 'label' => 'MAPA IA'],
                    ['id' => 'errores', 'icon' => 'edit_note', 'label' => 'ERRORES'],
                    ['id' => 'ranking', 'icon' => 'emoji_events', 'label' => 'RANKING'],
                    ['id' => 'perfil', 'icon' => 'person', 'label' => 'PERFIL'],
                ] as $item)
                    <button wire:click="navigate('{{ $item['id'] }}')"
                        class="flex flex-col items-center gap-1 w-16 py-1 rounded-2xl transition-all
                        {{ $currentScreen === $item['id'] ? 'bg-blue-500 text-white shadow-[0_3px_0_0_rgba(30,64,175,1)] scale-105' : 'bg-slate-100 text-slate-400 border-2 border-slate-200' }}">
                        <span class="material-symbols-outlined text-lg {{ $currentScreen === $item['id'] ? 'filled-icon' : '' }}">
                            {{ $item['icon'] }}
                        </span>
                        <span class="text-[9px] font-black uppercase tracking-wider">{{ $item['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </nav>
    @endif
</div>
