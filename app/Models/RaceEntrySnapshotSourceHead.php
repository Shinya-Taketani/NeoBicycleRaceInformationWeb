<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaceEntrySnapshotSourceHead extends Model
{
    protected $primaryKey = 'race_entry_snapshot_id';

    public $incrementing = false;

    protected $fillable = [
        'race_entry_snapshot_id',
        'race_entry_snapshot_source_id',
        'race_id',
        'race_entry_id',
    ];

    public function sourceState(): BelongsTo
    {
        return $this->belongsTo(
            RaceEntrySnapshotSource::class,
            'race_entry_snapshot_source_id',
        );
    }
}
