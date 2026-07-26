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

        $this->changeObservationNullability(true);
    }

    public function down(): void
    {
        $protectedData = [
            'race_score_fetched_at' => DB::table('race_entries')->whereNotNull('race_score_fetched_at')->count(),
            'deleted_at' => DB::table('race_entries')->whereNotNull('deleted_at')->count(),
            'snapshot_external_player_id' => DB::table('race_entry_snapshots')->whereNotNull('external_player_id')->count(),
            'first_observed_at_null' => DB::table('race_entry_snapshots')->whereNull('first_observed_at')->count(),
            'last_observed_at_null' => DB::table('race_entry_snapshots')->whereNull('last_observed_at')->count(),
        ];
        $blockingData = array_filter(
            $protectedData,
            static fn (int $count): bool => $count > 0,
        );
        if ($blockingData !== []) {
            $details = [];
            foreach ($blockingData as $field => $count) {
                $details[] = "{$field}={$count}";
            }

            throw new RuntimeException(
                'Cannot rollback race entry audit lifecycle migration because protected data exists: '
                .implode(', ', $details).'.',
            );
        }

        $this->changeObservationNullability(false);

        Schema::table('race_entry_snapshots', function (Blueprint $table): void {
            $table->dropColumn('external_player_id');
        });

        Schema::table('race_entries', function (Blueprint $table): void {
            $table->dropColumn(['race_score_fetched_at', 'deleted_at']);
        });
    }

    private function changeObservationNullability(bool $nullable): void
    {
        Schema::table('race_entry_snapshots', function (Blueprint $table) use ($nullable): void {
            $table->timestampTz('first_observed_at')->nullable($nullable)->change();
            $table->timestampTz('last_observed_at')->nullable($nullable)->change();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS race_entry_snapshots_current_unique');
            DB::statement(
                'CREATE UNIQUE INDEX race_entry_snapshots_current_unique '
                .'ON race_entry_snapshots (race_entry_id) WHERE is_current = true',
            );
        }
    }
};
