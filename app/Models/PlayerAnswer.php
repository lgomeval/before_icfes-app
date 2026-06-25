<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerAnswer extends Model
{
    protected $fillable = ['player_id', 'question_id', 'is_correct'];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
