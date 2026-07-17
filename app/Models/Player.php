<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = [
        'source',
        'external_player_id',
        'registration_number',
        'name',
        'name_kana',
        'birth_date',
        'gender',
        'current_grade',
        'graduation_period',
        'prefecture',
        'district',
        'riding_style',
        'home_bank',
        'status',
        'detail_url',
        'source_updated_at',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'source_updated_at' => 'immutable_datetime',
            'last_fetched_at' => 'immutable_datetime',
        ];
    }
}
