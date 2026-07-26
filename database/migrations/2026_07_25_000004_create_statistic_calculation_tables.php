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
        Schema::table('race_entries', function (Blueprint $table): void {
            $table->unique(['id', 'race_id'], 'race_entries_id_race_unique');
        });

        Schema::create('statistic_calculation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('stat_code', 20);
            $table->string('calculation_version', 80);
            $table->string('status', 40);
            $table->date('target_from')->nullable();
            $table->date('target_to')->nullable();
            $table->foreignId('target_race_id')->nullable()->constrained('races')->nullOnDelete();
            $table->jsonb('parameters');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('target_race_count')->default(0);
            $table->unsignedInteger('processed_race_count')->default(0);
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('partial_count')->default(0);
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->index(['stat_code', 'calculation_version', 'status'], 'stat_calc_runs_code_version_status_index');
            $table->index(['target_from', 'target_to'], 'stat_calc_runs_target_dates_index');
        });

        Schema::create('race_entry_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('race_entry_id');
            $table->foreignId('race_id')->constrained('races')->restrictOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->unsignedSmallInteger('bike_number');
            $table->unsignedSmallInteger('frame_number')->nullable();
            $table->string('grade', 40)->nullable();
            $table->text('race_score_raw_text')->nullable();
            $table->decimal('race_score', 12, 4)->nullable();
            $table->string('race_score_validation_status', 40);
            $table->string('race_score_anomaly_status', 40);
            $table->string('snapshot_type', 40);
            $table->char('snapshot_hash', 64);
            $table->timestampTz('first_observed_at');
            $table->timestampTz('last_observed_at');
            $table->boolean('is_complete');
            $table->string('parser_version', 80)->nullable();
            $table->timestampsTz();

            $table->unique(['race_entry_id', 'snapshot_hash'], 'race_entry_snapshot_hash_unique');
            $table->unique(
                ['id', 'race_id', 'race_entry_id'],
                'race_entry_snapshots_audit_identity_unique',
            );
            $table->foreign(['race_entry_id', 'race_id'], 'race_entry_snapshots_entry_race_foreign')
                ->references(['id', 'race_id'])
                ->on('race_entries')
                ->restrictOnDelete();
            $table->index('race_id', 'race_entry_snapshots_race_index');
        });

        Schema::create('race_entry_snapshot_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('race_entry_snapshot_id');
            $table->unsignedBigInteger('race_id');
            $table->unsignedBigInteger('race_entry_id');
            $table->foreignId('scraping_fetch_log_id')->nullable()->constrained('scraping_fetch_logs')->restrictOnDelete();
            $table->string('source_role', 40);
            $table->string('source_identity_key', 255);
            $table->char('source_fingerprint', 64);
            $table->jsonb('contributed_fields');
            $table->string('source_page_type', 40);
            $table->string('source_race_context_key', 255)->nullable();
            $table->string('context_match_method', 80);
            $table->string('context_verification_status', 60);
            $table->string('historical_backfill_scope', 60);
            $table->jsonb('eligible_fields')->nullable();
            $table->timestampTz('source_fetched_at')->nullable();
            $table->string('parser_version', 80)->nullable();
            $table->text('source_url')->nullable();
            $table->string('raw_file_path')->nullable();
            $table->char('raw_sha256', 64)->nullable();
            $table->timestampTz('context_verified_at')->nullable();
            $table->jsonb('context_evidence')->nullable();
            $table->timestampTz('created_at');

            $table->unique(
                ['race_entry_snapshot_id', 'source_role', 'source_fingerprint'],
                'race_entry_snapshot_source_fingerprint_unique',
            );
            $table->unique(
                ['id', 'race_entry_snapshot_id'],
                'race_entry_snapshot_sources_snapshot_unique',
            );
            $table->unique(
                ['id', 'race_id', 'race_entry_id', 'race_entry_snapshot_id'],
                'race_entry_snapshot_sources_audit_identity_unique',
            );
            $table->foreign(
                ['race_entry_snapshot_id', 'race_id', 'race_entry_id'],
                'race_entry_snapshot_sources_snapshot_foreign',
            )->references(['id', 'race_id', 'race_entry_id'])
                ->on('race_entry_snapshots')
                ->restrictOnDelete();
            $table->index('scraping_fetch_log_id');
        });

        Schema::create('race_entry_snapshot_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('race_id');
            $table->unsignedBigInteger('race_entry_id');
            $table->unsignedBigInteger('race_entry_snapshot_id');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_current');
            $table->timestampTz('state_observed_at');
            $table->timestampsTz();

            $table->unique(
                ['id', 'race_id', 'race_entry_id', 'race_entry_snapshot_id'],
                'race_entry_snapshot_occurrences_audit_identity_unique',
            );
            $table->foreign(
                ['race_entry_snapshot_id', 'race_id', 'race_entry_id'],
                'race_entry_snapshot_occurrences_snapshot_foreign',
            )->references(['id', 'race_id', 'race_entry_id'])
                ->on('race_entry_snapshots')
                ->restrictOnDelete();
            $table->index(
                ['race_entry_snapshot_id', 'effective_from'],
                'race_entry_snapshot_occurrences_snapshot_from_index',
            );
        });

        Schema::create('race_entry_snapshot_source_heads', function (Blueprint $table): void {
            $table->unsignedBigInteger('race_entry_snapshot_id')->primary();
            $table->unsignedBigInteger('race_entry_snapshot_source_id');
            $table->unsignedBigInteger('race_id');
            $table->unsignedBigInteger('race_entry_id');
            $table->timestampsTz();

            $table->foreign(
                ['race_entry_snapshot_id', 'race_id', 'race_entry_id'],
                'race_entry_snapshot_source_heads_snapshot_foreign',
            )->references(['id', 'race_id', 'race_entry_id'])
                ->on('race_entry_snapshots')
                ->restrictOnDelete();
            $table->foreign(
                [
                    'race_entry_snapshot_source_id',
                    'race_id',
                    'race_entry_id',
                    'race_entry_snapshot_id',
                ],
                'race_entry_snapshot_source_heads_source_foreign',
            )->references(['id', 'race_id', 'race_entry_id', 'race_entry_snapshot_id'])
                ->on('race_entry_snapshot_sources')
                ->restrictOnDelete();
        });

        Schema::create('stat_feature_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('stat_code', 20);
            $table->string('feature_code', 80);
            $table->string('feature_name');
            $table->string('value_type', 20);
            $table->string('unit_code', 40);
            $table->text('description');
            $table->boolean('is_active')->default(true);
            $table->string('definition_version', 80);
            $table->timestampsTz();

            $table->unique(
                ['stat_code', 'feature_code', 'definition_version'],
                'stat_feature_definition_version_unique',
            );
            $table->index(['stat_code', 'is_active']);
        });

        Schema::create('stat_feature_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type', 20);
            $table->foreignId('race_id')->constrained('races')->restrictOnDelete();
            $table->foreignId('race_entry_id')->nullable()->constrained('race_entries')->restrictOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('opponent_race_entry_id')->nullable()->constrained('race_entries')->restrictOnDelete();
            $table->foreignId('opponent_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('stat_code', 20);
            $table->timestampTz('input_as_of')->nullable();
            $table->string('input_as_of_policy', 40);
            $table->string('input_snapshot_type', 80);
            $table->char('input_hash', 64);
            $table->string('calculation_version', 80);
            $table->string('status', 40);
            $table->string('data_quality_status', 40);
            $table->timestampTz('history_start_at')->nullable();
            $table->timestampTz('history_end_at')->nullable();
            $table->unsignedBigInteger('sample_count')->nullable();
            $table->decimal('coverage_rate', 7, 6)->nullable();
            $table->timestampTz('source_max_fetched_at')->nullable();
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->index(['race_id', 'stat_code', 'calculation_version'], 'stat_feature_snapshots_race_code_version_index');
            $table->index(['status', 'data_quality_status'], 'stat_feature_snapshots_status_quality_index');
            $table->index('input_hash');
            $table->unique(['id', 'race_id'], 'stat_feature_snapshots_race_identity_unique');
            $table->unique(
                ['id', 'race_id', 'race_entry_id'],
                'stat_feature_snapshots_entry_identity_unique',
            );
        });

        Schema::create('stat_feature_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stat_feature_snapshot_id')->constrained('stat_feature_snapshots')->cascadeOnDelete();
            $table->string('feature_code', 80);
            $table->string('value_type', 20);
            $table->bigInteger('feature_value_integer')->nullable();
            $table->double('feature_value_numeric')->nullable();
            $table->text('feature_value_text')->nullable();
            $table->boolean('feature_value_boolean')->nullable();
            $table->jsonb('feature_value_json')->nullable();
            $table->double('numerator')->nullable();
            $table->double('denominator')->nullable();
            $table->unsignedBigInteger('sample_count')->nullable();
            $table->string('window_type', 40)->nullable();
            $table->string('window_value', 80)->nullable();
            $table->string('unit_code', 40);
            $table->string('status', 40);
            $table->timestampsTz();

            $table->index(['feature_code', 'value_type']);
        });

        Schema::create('stat_feature_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stat_feature_snapshot_id')->constrained('stat_feature_snapshots')->cascadeOnDelete();
            $table->unsignedBigInteger('race_entry_snapshot_id')->nullable();
            $table->unsignedBigInteger('race_entry_snapshot_source_id')->nullable();
            $table->foreignId('scraping_fetch_log_id')->nullable()->constrained('scraping_fetch_logs')->nullOnDelete();
            $table->string('source_role', 40);
            $table->string('source_identity_key', 255);
            $table->string('source_type', 60);
            $table->text('source_url')->nullable();
            $table->string('raw_file_path')->nullable();
            $table->char('raw_sha256', 64)->nullable();
            $table->timestampTz('source_fetched_at')->nullable();
            $table->timestampTz('source_reference_at')->nullable();
            $table->string('parser_version', 80)->nullable();
            $table->string('source_timing_status', 60);
            $table->timestampTz('created_at');

            $table->unique(
                ['stat_feature_snapshot_id', 'source_role', 'source_identity_key'],
                'stat_feature_source_identity_unique',
            );
            $table->foreign(
                ['race_entry_snapshot_source_id', 'race_entry_snapshot_id'],
                'stat_feature_sources_snapshot_source_foreign',
            )->references(['id', 'race_entry_snapshot_id'])
                ->on('race_entry_snapshot_sources')
                ->restrictOnDelete();
            $table->index(['race_entry_snapshot_id', 'scraping_fetch_log_id'], 'stat_feature_sources_input_source_index');
        });

        Schema::create('statistic_run_feature_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calculation_run_id')->constrained('statistic_calculation_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('stat_feature_snapshot_id');
            $table->unsignedBigInteger('race_id');
            $table->timestampTz('created_at');

            $table->unique(
                ['calculation_run_id', 'stat_feature_snapshot_id'],
                'stat_run_feature_snapshot_unique',
            );
            $table->foreign(
                ['stat_feature_snapshot_id', 'race_id'],
                'stat_run_feature_snapshots_feature_race_foreign',
            )->references(['id', 'race_id'])
                ->on('stat_feature_snapshots')
                ->restrictOnDelete();
            $table->index(['calculation_run_id', 'race_id'], 'stat_run_feature_snapshots_run_race_index');
        });

        Schema::create('statistic_run_feature_snapshot_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calculation_run_id')->constrained('statistic_calculation_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('stat_feature_snapshot_id');
            $table->unsignedBigInteger('race_entry_snapshot_occurrence_id');
            $table->unsignedBigInteger('race_entry_snapshot_source_id');
            $table->unsignedBigInteger('race_entry_snapshot_id');
            $table->unsignedBigInteger('race_id');
            $table->unsignedBigInteger('feature_race_entry_id');
            $table->unsignedBigInteger('source_race_entry_id');
            $table->string('source_role', 40);
            $table->timestampTz('created_at');

            $table->unique(
                [
                    'calculation_run_id',
                    'stat_feature_snapshot_id',
                    'race_entry_snapshot_occurrence_id',
                    'race_entry_snapshot_source_id',
                    'source_role',
                ],
                'stat_run_feature_occurrence_unique',
            );
            $table->foreign(
                ['stat_feature_snapshot_id', 'race_id', 'feature_race_entry_id'],
                'stat_run_feature_occurrences_feature_foreign',
            )->references(['id', 'race_id', 'race_entry_id'])
                ->on('stat_feature_snapshots')
                ->restrictOnDelete();
            $table->foreign(
                [
                    'race_entry_snapshot_occurrence_id',
                    'race_id',
                    'source_race_entry_id',
                    'race_entry_snapshot_id',
                ],
                'stat_run_feature_occurrences_occurrence_foreign',
            )->references(['id', 'race_id', 'race_entry_id', 'race_entry_snapshot_id'])
                ->on('race_entry_snapshot_occurrences')
                ->restrictOnDelete();
            $table->foreign(
                [
                    'race_entry_snapshot_source_id',
                    'race_id',
                    'source_race_entry_id',
                    'race_entry_snapshot_id',
                ],
                'stat_run_feature_occurrences_source_foreign',
            )->references(['id', 'race_id', 'race_entry_id', 'race_entry_snapshot_id'])
                ->on('race_entry_snapshot_sources')
                ->restrictOnDelete();
            $table->index(
                ['calculation_run_id', 'source_race_entry_id'],
                'stat_run_feature_occurrences_run_entry_index',
            );
        });

        $this->createPartialIndexes();
        $this->createPostgreSqlChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('statistic_run_feature_snapshot_occurrences');
        Schema::dropIfExists('statistic_run_feature_snapshots');
        Schema::dropIfExists('stat_feature_sources');
        Schema::dropIfExists('stat_feature_values');
        Schema::dropIfExists('stat_feature_snapshots');
        Schema::dropIfExists('stat_feature_definitions');
        Schema::dropIfExists('race_entry_snapshot_source_heads');
        Schema::dropIfExists('race_entry_snapshot_occurrences');
        Schema::dropIfExists('race_entry_snapshot_sources');
        Schema::dropIfExists('race_entry_snapshots');
        Schema::dropIfExists('statistic_calculation_runs');

        Schema::table('race_entries', function (Blueprint $table): void {
            $table->dropUnique('race_entries_id_race_unique');
        });
    }

    private function createPartialIndexes(): void
    {
        $nullsNotDistinct = DB::getDriverName() === 'pgsql' ? ' NULLS NOT DISTINCT' : '';
        DB::statement('CREATE UNIQUE INDEX race_entry_snapshot_occurrences_current_unique ON race_entry_snapshot_occurrences (race_entry_id) WHERE is_current = true');
        DB::statement("CREATE UNIQUE INDEX stat_feature_snapshot_race_unique ON stat_feature_snapshots (race_id, stat_code, input_as_of, calculation_version, input_hash){$nullsNotDistinct} WHERE scope_type = 'RACE'");
        DB::statement("CREATE UNIQUE INDEX stat_feature_snapshot_entry_unique ON stat_feature_snapshots (race_entry_id, stat_code, input_as_of, calculation_version, input_hash){$nullsNotDistinct} WHERE scope_type = 'RACE_ENTRY'");
        DB::statement("CREATE UNIQUE INDEX stat_feature_snapshot_pair_unique ON stat_feature_snapshots (race_entry_id, opponent_race_entry_id, stat_code, input_as_of, calculation_version, input_hash){$nullsNotDistinct} WHERE scope_type = 'PLAYER_PAIR'");
        DB::statement('CREATE UNIQUE INDEX stat_feature_value_unwindowed_unique ON stat_feature_values (stat_feature_snapshot_id, feature_code) WHERE window_type IS NULL AND window_value IS NULL');
        DB::statement('CREATE UNIQUE INDEX stat_feature_value_windowed_unique ON stat_feature_values (stat_feature_snapshot_id, feature_code, window_type, window_value) WHERE window_type IS NOT NULL AND window_value IS NOT NULL');
    }

    private function createPostgreSqlChecks(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE statistic_calculation_runs ADD CONSTRAINT statistic_calculation_runs_status_check CHECK (status IN ('RUNNING', 'SUCCEEDED', 'PARTIALLY_FAILED', 'FAILED', 'NO_TARGETS'))");
        DB::statement("ALTER TABLE race_entry_snapshots ADD CONSTRAINT race_entry_snapshots_validation_check CHECK (race_score_validation_status IN ('VALID', 'MISSING', 'INVALID_FORMAT', 'NON_POSITIVE', 'OUT_OF_STORAGE_RANGE', 'SOURCE_CONFLICT'))");
        DB::statement("ALTER TABLE race_entry_snapshots ADD CONSTRAINT race_entry_snapshots_anomaly_check CHECK (race_score_anomaly_status IN ('NOT_CHECKED', 'NORMAL', 'OUTLIER_WARNING', 'EXTREME_OUTLIER_WARNING'))");
        DB::statement('ALTER TABLE race_entry_snapshots ADD CONSTRAINT race_entry_snapshots_bike_check CHECK (bike_number BETWEEN 1 AND 9)');
        DB::statement('ALTER TABLE race_entry_snapshot_occurrences ADD CONSTRAINT race_entry_snapshot_occurrences_period_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        DB::statement('ALTER TABLE race_entry_snapshot_occurrences ADD CONSTRAINT race_entry_snapshot_occurrences_state_check CHECK ((is_current = true AND effective_to IS NULL) OR (is_current = false AND effective_to IS NOT NULL))');
        DB::statement('ALTER TABLE race_entry_snapshot_occurrences ADD CONSTRAINT race_entry_snapshot_occurrences_observed_check CHECK (state_observed_at = effective_from)');
        DB::statement("ALTER TABLE race_entry_snapshot_sources ADD CONSTRAINT race_entry_snapshot_sources_page_check CHECK (source_page_type IN ('RACE_ENTRY_LIST', 'RACE_DETAIL', 'RACE_RESULT', 'PLAYER_PROFILE', 'OTHER', 'UNKNOWN'))");
        DB::statement("ALTER TABLE race_entry_snapshot_sources ADD CONSTRAINT race_entry_snapshot_sources_verification_check CHECK (context_verification_status IN ('VERIFIED_EXACT', 'VERIFIED_LEGACY_RECONCILED', 'PARTIAL_MATCH', 'CONFLICTED', 'UNVERIFIED'))");
        DB::statement("ALTER TABLE race_entry_snapshot_sources ADD CONSTRAINT race_entry_snapshot_sources_backfill_check CHECK (historical_backfill_scope IN ('ALL_CONTRIBUTED_FIELDS', 'STATIC_RACE_CARD_FIELDS_ONLY', 'NOT_ELIGIBLE'))");
        DB::statement("ALTER TABLE stat_feature_definitions ADD CONSTRAINT stat_feature_definitions_value_type_check CHECK (value_type IN ('INTEGER', 'NUMERIC', 'TEXT', 'BOOLEAN', 'JSON'))");
        DB::statement("ALTER TABLE stat_feature_snapshots ADD CONSTRAINT stat_feature_snapshots_scope_check CHECK ((scope_type = 'RACE' AND race_id IS NOT NULL AND race_entry_id IS NULL AND player_id IS NULL AND opponent_race_entry_id IS NULL AND opponent_player_id IS NULL) OR (scope_type = 'RACE_ENTRY' AND race_id IS NOT NULL AND race_entry_id IS NOT NULL AND opponent_race_entry_id IS NULL AND opponent_player_id IS NULL) OR (scope_type = 'PLAYER_PAIR' AND race_id IS NOT NULL AND race_entry_id IS NOT NULL AND opponent_race_entry_id IS NOT NULL AND race_entry_id <> opponent_race_entry_id))");
        DB::statement("ALTER TABLE stat_feature_snapshots ADD CONSTRAINT stat_feature_snapshots_status_check CHECK (status IN ('VALID', 'NOT_APPLICABLE', 'NO_HISTORY', 'INSUFFICIENT_SAMPLE', 'PARTIAL_HISTORY', 'MISSING_INPUT', 'DEGRADED', 'CONFLICTED_INPUT', 'INVALID_INPUT', 'LEAKAGE_RISK', 'BLOCKED', 'ERROR'))");
        DB::statement("ALTER TABLE stat_feature_snapshots ADD CONSTRAINT stat_feature_snapshots_quality_check CHECK (data_quality_status IN ('VALID', 'PARTIAL', 'DEGRADED', 'BLOCKED', 'LEAKAGE_RISK', 'ERROR'))");
        DB::statement("ALTER TABLE stat_feature_snapshots ADD CONSTRAINT stat_feature_snapshots_as_of_policy_check CHECK (input_as_of_policy IN ('SALES_CLOSE', 'START_TIME', 'INPUT_AS_OF_UNAVAILABLE'))");
        DB::statement("ALTER TABLE stat_feature_values ADD CONSTRAINT stat_feature_values_value_type_check CHECK ((value_type = 'INTEGER' AND feature_value_integer IS NOT NULL AND feature_value_numeric IS NULL AND feature_value_text IS NULL AND feature_value_boolean IS NULL AND feature_value_json IS NULL) OR (value_type = 'NUMERIC' AND feature_value_integer IS NULL AND feature_value_numeric IS NOT NULL AND feature_value_text IS NULL AND feature_value_boolean IS NULL AND feature_value_json IS NULL) OR (value_type = 'TEXT' AND feature_value_integer IS NULL AND feature_value_numeric IS NULL AND feature_value_text IS NOT NULL AND feature_value_boolean IS NULL AND feature_value_json IS NULL) OR (value_type = 'BOOLEAN' AND feature_value_integer IS NULL AND feature_value_numeric IS NULL AND feature_value_text IS NULL AND feature_value_boolean IS NOT NULL AND feature_value_json IS NULL) OR (value_type = 'JSON' AND feature_value_integer IS NULL AND feature_value_numeric IS NULL AND feature_value_text IS NULL AND feature_value_boolean IS NULL AND feature_value_json IS NOT NULL))");
        DB::statement("ALTER TABLE stat_feature_values ADD CONSTRAINT stat_feature_values_status_check CHECK (status IN ('VALID', 'NOT_APPLICABLE', 'NO_HISTORY', 'INSUFFICIENT_SAMPLE', 'PARTIAL_HISTORY', 'MISSING_INPUT', 'DEGRADED', 'CONFLICTED_INPUT', 'INVALID_INPUT', 'LEAKAGE_RISK', 'BLOCKED', 'ERROR'))");
        DB::statement('ALTER TABLE stat_feature_values ADD CONSTRAINT stat_feature_values_window_check CHECK ((window_type IS NULL AND window_value IS NULL) OR (window_type IS NOT NULL AND window_value IS NOT NULL))');
        DB::statement("ALTER TABLE stat_feature_values ADD CONSTRAINT stat_feature_values_finite_check CHECK (feature_value_numeric IS NULL OR (feature_value_numeric <> 'NaN'::double precision AND feature_value_numeric <> 'Infinity'::double precision AND feature_value_numeric <> '-Infinity'::double precision))");
        DB::statement("ALTER TABLE stat_feature_sources ADD CONSTRAINT stat_feature_sources_role_check CHECK (source_role IN ('PRIMARY_INPUT', 'CONTEXT_INPUT', 'HISTORICAL_INPUT', 'RESULT_INPUT', 'MASTER_INPUT', 'AUDIT_ONLY'))");
        DB::statement('ALTER TABLE stat_feature_sources ADD CONSTRAINT stat_feature_sources_snapshot_source_null_check CHECK ((race_entry_snapshot_id IS NULL AND race_entry_snapshot_source_id IS NULL) OR (race_entry_snapshot_id IS NOT NULL AND race_entry_snapshot_source_id IS NOT NULL))');
        DB::statement("ALTER TABLE stat_feature_sources ADD CONSTRAINT stat_feature_sources_race_entry_source_check CHECK (source_type <> 'RACE_ENTRY_SNAPSHOT' OR (race_entry_snapshot_id IS NOT NULL AND race_entry_snapshot_source_id IS NOT NULL))");
        DB::statement("ALTER TABLE statistic_run_feature_snapshot_occurrences ADD CONSTRAINT stat_run_feature_occurrences_role_check CHECK (source_role IN ('PRIMARY_INPUT', 'CONTEXT_INPUT'))");
        DB::statement("ALTER TABLE statistic_run_feature_snapshot_occurrences ADD CONSTRAINT stat_run_feature_occurrences_entry_role_check CHECK ((source_role = 'PRIMARY_INPUT' AND feature_race_entry_id = source_race_entry_id) OR (source_role = 'CONTEXT_INPUT' AND feature_race_entry_id <> source_race_entry_id))");
    }
};
