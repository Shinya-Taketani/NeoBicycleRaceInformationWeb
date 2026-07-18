<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrapingFetchLog extends Model
{
    protected $fillable = [
        'batch_run_id',
        'source',
        'request_method',
        'request_url',
        'request_key',
        'http_status',
        'fetched_at',
        'content_type',
        'detected_encoding',
        'utf8_conversion_succeeded',
        'response_size',
        'sha256',
        'raw_file_path',
        'retry_count',
        'parser_version',
        'error_type',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'immutable_datetime',
            'utf8_conversion_succeeded' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
