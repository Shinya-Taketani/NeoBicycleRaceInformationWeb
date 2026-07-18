<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BatchRunItem extends Model
{
    protected $fillable = [
        'batch_run_id',
        'item_type',
        'item_key',
        'status',
        'attempt_count',
        'started_at',
        'finished_at',
        'skip_reason',
        'error_type',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
