<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = ['nickname', 'pin', 'xp', 'level', 'streak', 'hearts', 'coins'];

    protected $hidden = ['pin'];

    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'level' => 'integer',
            'streak' => 'integer',
            'hearts' => 'integer',
            'coins' => 'integer',
        ];
    }

    public function hasPin(): bool
    {
        return $this->pin !== null;
    }

    public function verifyPin(string $pin): bool
    {
        if (! $this->hasPin()) {
            return false;
        }

        return password_verify($pin, $this->pin);
    }

    public function setPin(string $pin): void
    {
        $this->update(['pin' => password_hash($pin, PASSWORD_BCRYPT)]);
    }

    public function answers()
    {
        return $this->hasMany(PlayerAnswer::class);
    }

    public function correctAnswerIds(): array
    {
        return $this->answers()->where('is_correct', true)->pluck('question_id')->toArray();
    }

    public static function findByNickname(string $nickname): ?self
    {
        return static::where('nickname', $nickname)->first();
    }

    public static function findOrCreateByNickname(string $nickname): self
    {
        return static::firstOrCreate(
            ['nickname' => trim($nickname)],
            ['xp' => 0, 'level' => 1, 'streak' => 0, 'hearts' => 5, 'coins' => 0]
        );
    }

    public function stats(): array
    {
        $total = $this->answers()->count();
        $correct = $this->answers()->where('is_correct', true)->count();

        return [
            'total' => $total,
            'correct' => $correct,
            'incorrect' => $total - $correct,
            'percentage' => $total > 0 ? (int) round(($correct / $total) * 100) : 0,
        ];
    }
}
