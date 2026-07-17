<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchRun extends Model
{
    protected $fillable = [
        'type',
        'source',
        'status',
        'lock_key',
        'parameters',
        'started_at',
        'finished_at',
        'success_count',
        'skipped_count',
        'failure_count',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BatchRunItem::class);
    }
}
