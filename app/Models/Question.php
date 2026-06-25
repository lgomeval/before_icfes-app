<?php

namespace App\Models;

use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->correct_answer !== null;
    }

    public function scopeRandom($query)
    {
        return $query->inRandomOrder();
    }

    public function scopeByArea($query, ?string $area)
    {
        if ($area) {
            return $query->where('area', $area);
        }

        return $query;
    }

    public function scopeByTopic($query, ?string $topic)
    {
        if ($topic) {
            return $query->where('topic', $topic);
        }

        return $query;
    }

    public function scopeByLevel($query, ?string $level)
    {
        if ($level) {
            return $query->where('level', $level);
        }

        return $query;
    }

    public function scopeBySource($query, ?string $source)
    {
        if ($source !== null) {
            return $query->where('source', $source);
        }

        return $query->whereNull('source');
    }

    public static function areas(): array
    {
        return ['Matematicas', 'Ciencias Naturales', 'Ingles'];
    }

    public static function topics(): array
    {
        return [
            'Matematicas' => ['Geometria', 'Algebra', 'Estadistica', 'Probabilidad', 'Porcentajes'],
            'Ciencias Naturales' => ['Biologia', 'Fisica', 'Quimica', 'Ecologia'],
            'Ingles' => ['Vocabulary', 'Grammar', 'Reading'],
        ];
    }

    public static function levels(): array
    {
        return ['Facil', 'Medio', 'Dificil'];
    }
}
