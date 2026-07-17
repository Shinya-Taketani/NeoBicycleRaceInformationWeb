<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerStatusHistory extends Model
{
    protected $fillable = [
        'player_id',
        'grade',
        'grade_assigned_on',
        'status',
        'source_url',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'grade_assigned_on' => 'immutable_date',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
