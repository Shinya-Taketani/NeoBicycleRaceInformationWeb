<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RaceResultImport extends Model
{
    protected $fillable = [
        'race_id',
        'batch_run_id',
        'batch_run_item_id',
        'scraping_fetch_log_id',
        'source_url',
        'source_hash',
        'raw_file_path',
        'parser_version',
        'requested_result_status',
        'parsed_page_status',
        'import_status',
        'result_count',
        'payout_count',
        'error_type',
        'error_message',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'immutable_datetime',
        ];
    }
}
