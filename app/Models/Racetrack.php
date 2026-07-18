<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Racetrack extends Model
{
    protected $fillable = ['source', 'external_track_id', 'name', 'region'];
}
