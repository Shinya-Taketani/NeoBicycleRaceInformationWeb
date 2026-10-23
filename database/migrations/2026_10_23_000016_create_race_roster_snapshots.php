<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Roster snapshots require PostgreSQL or isolated SQLite.');
        }
        Schema::create('race_roster_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->foreignId('scraping_fetch_log_id')->constrained()->restrictOnDelete();
            $table->date('race_date');
            $table->string('track_code', 10);
            $table->unsignedSmallInteger('race_number');
            $table->string('source', 20);
            $table->unsignedSmallInteger('entrant_count');
            $table->json('entries');
            $table->timestampTz('fetched_at', 6)->nullable();
            $table->timestampTz('fetch_log_saved_at', 6)->nullable();
            $table->timestampTz('scheduled_start_at', 6)->nullable();
            $table->json('schedule_raw');
            $table->string('schedule_timezone', 50);
            $table->string('schedule_timezone_basis', 100);
            $table->string('timing_status', 50);
            $table->text('source_url');
            $table->text('raw_file_path');
            $table->char('raw_sha256', 64);
            $table->unsignedInteger('raw_bytes');
            $table->char('converted_sha256', 64);
            $table->char('source_metadata_hash', 64);
            $table->string('source_parser_version', 100);
            $table->string('parser_version', 100);
            $table->string('snapshot_version', 100);
            $table->string('official_publication_status', 30);
            $table->timestampTz('official_publication_at', 6)->nullable();
            $table->string('use_class', 30);
            $table->boolean('historical_as_of_available');
            $table->string('prediction_use', 30);
            $table->timestampTz('created_at', 6);
            $table->unique(['scraping_fetch_log_id', 'parser_version'], 'roster_snapshot_capture_parser_unique');
            $table->index(['race_id', 'fetched_at'], 'roster_snapshot_race_time_index');
        });
        // Calendar-year authorization belongs to the execution service, not immutable storage.
        $checks = ['race_number BETWEEN 1 AND 12', 'entrant_count BETWEEN 1 AND 9', 'raw_bytes BETWEEN 1 AND 4194304',
            "source='PJ0315'", "official_publication_status='UNKNOWN'", 'official_publication_at IS NULL',
            "use_class='RETROSPECTIVE_ONLY'", 'historical_as_of_available=false', "prediction_use='NOT_AUTHORIZED'",
            "timing_status IN ('UNKNOWN','BEFORE_SAME_RAW_START','AT_OR_AFTER_SAME_RAW_START')"];
        if ($driver === 'pgsql') {
            $checks[] = "json_typeof(entries)='array' AND json_array_length(entries)=entrant_count";
            $check = implode(' AND ', $checks);
            DB::statement("ALTER TABLE race_roster_snapshots ADD CONSTRAINT roster_snapshots_values_check CHECK (COALESCE(($check),false))");
            DB::unprepared("CREATE FUNCTION reject_roster_snapshot_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Roster snapshots are append-only'; END; $$");
            DB::unprepared('CREATE TRIGGER roster_snapshots_immutable BEFORE UPDATE OR DELETE ON race_roster_snapshots FOR EACH ROW EXECUTE FUNCTION reject_roster_snapshot_mutation()');
        } else {
            $checks[] = "json_type(entries)='array' AND json_array_length(entries)=entrant_count";
            $check = implode(' AND ', $checks);
            $names = ['race_number', 'entrant_count', 'raw_bytes', 'source', 'official_publication_status', 'official_publication_at',
                'use_class', 'historical_as_of_available', 'prediction_use', 'timing_status', 'entries'];
            $check = preg_replace('/\b('.implode('|', $names).')\b/', 'NEW.$1', $check);
            DB::unprepared("CREATE TRIGGER roster_snapshots_values BEFORE INSERT ON race_roster_snapshots WHEN NOT COALESCE(($check),0) BEGIN SELECT RAISE(ABORT,'Invalid roster snapshot'); END");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER roster_snapshots_no_{$operation} BEFORE {$operation} ON race_roster_snapshots BEGIN SELECT RAISE(ABORT,'Roster snapshots are append-only'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('race_roster_snapshots')->exists()) {
            throw new RuntimeException('Cannot remove populated roster snapshots. Review and export first.');
        }
        Schema::drop('race_roster_snapshots');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION reject_roster_snapshot_mutation()');
        }
    }
};
