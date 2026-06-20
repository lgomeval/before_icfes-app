<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = ['nickname'];

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
        return static::firstOrCreate(['nickname' => trim($nickname)]);
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
