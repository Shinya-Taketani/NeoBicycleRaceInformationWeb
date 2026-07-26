<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('race_entries', function (Blueprint $table): void {
            $table->timestampTz('race_score_fetched_at')->nullable();
            $table->softDeletesTz();
        });

        Schema::table('race_entry_snapshots', function (Blueprint $table): void {
            $table->string('external_player_id', 32)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('race_entry_snapshots', function (Blueprint $table): void {
                $table->timestampTz('first_observed_at')->nullable()->change();
                $table->timestampTz('last_observed_at')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $unknownObservationCount = DB::table('race_entry_snapshots')
                ->whereNull('first_observed_at')
                ->orWhereNull('last_observed_at')
                ->count();
            if ($unknownObservationCount > 0) {
                throw new RuntimeException(
                    "Cannot make race entry snapshot observation times required: {$unknownObservationCount} row(s) have unknown timing.",
                );
            }

            Schema::table('race_entry_snapshots', function (Blueprint $table): void {
                $table->timestampTz('first_observed_at')->nullable(false)->change();
                $table->timestampTz('last_observed_at')->nullable(false)->change();
            });
        }

        Schema::table('race_entry_snapshots', function (Blueprint $table): void {
            $table->dropColumn('external_player_id');
        });

        Schema::table('race_entries', function (Blueprint $table): void {
            $table->dropColumn(['race_score_fetched_at', 'deleted_at']);
        });
    }
};
