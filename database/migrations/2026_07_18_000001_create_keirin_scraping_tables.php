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
        Schema::create('batch_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 80);
            $table->string('source', 80);
            $table->string('status', 40);
            $table->string('lock_key', 160)->nullable();
            $table->json('parameters')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->index(['type', 'source', 'status']);
            $table->index('lock_key');
        });

        Schema::create('batch_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_run_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 80);
            $table->string('item_key', 255);
            $table->string('status', 60);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('skip_reason', 120)->nullable();
            $table->string('error_type', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['batch_run_id', 'item_type', 'item_key']);
            $table->index(['status', 'item_type']);
        });

        Schema::create('scraping_fetch_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 80);
            $table->string('request_method', 12);
            $table->text('request_url');
            $table->string('request_key', 255);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestampTz('fetched_at');
            $table->string('content_type')->nullable();
            $table->string('detected_encoding', 60)->nullable();
            $table->boolean('utf8_conversion_succeeded')->default(false);
            $table->unsignedBigInteger('response_size');
            $table->char('sha256', 64);
            $table->string('raw_file_path');
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('parser_version', 80);
            $table->string('error_type', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['source', 'request_key']);
            $table->index('sha256');
            $table->index('fetched_at');
        });

        Schema::create('players', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80);
            $table->string('external_player_id', 32);
            $table->string('registration_number', 32)->nullable();
            $table->string('name');
            $table->string('name_kana')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('current_grade', 40)->nullable();
            $table->string('graduation_period', 20)->nullable();
            $table->string('prefecture', 40)->nullable();
            $table->string('district', 40)->nullable();
            $table->string('riding_style', 20)->nullable();
            $table->string('home_bank', 80)->nullable();
            $table->string('status', 40)->nullable();
            $table->text('detail_url')->nullable();
            $table->timestampTz('source_updated_at')->nullable();
            $table->timestampTz('last_fetched_at')->nullable();
            $table->timestampsTz();

            $table->unique(['source', 'external_player_id']);
            $table->index(['current_grade', 'gender']);
        });

        Schema::create('player_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('grade', 40)->nullable();
            $table->date('grade_assigned_on')->nullable();
            $table->string('status', 40)->nullable();
            $table->text('source_url')->nullable();
            $table->timestampTz('fetched_at');
            $table->timestampsTz();

            $table->unique(['player_id', 'grade', 'grade_assigned_on']);
        });

        Schema::create('player_stat_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->date('basis_date')->nullable();
            $table->decimal('race_score', 6, 2)->nullable();
            $table->decimal('win_rate', 5, 2)->nullable();
            $table->decimal('quinella_rate', 5, 2)->nullable();
            $table->decimal('trio_rate', 5, 2)->nullable();
            $table->unsignedInteger('back_count')->nullable();
            $table->unsignedInteger('home_count')->nullable();
            $table->unsignedInteger('start_count')->nullable();
            $table->text('source_url')->nullable();
            $table->timestampTz('fetched_at');
            $table->timestampsTz();

            $table->unique(['player_id', 'basis_date', 'fetched_at']);
        });

        Schema::create('racetracks', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80);
            $table->string('external_track_id', 32);
            $table->string('name');
            $table->string('region', 40)->nullable();
            $table->timestampsTz();

            $table->unique(['source', 'external_track_id']);
        });

        Schema::create('races', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80);
            $table->string('external_race_id', 160);
            $table->foreignId('racetrack_id')->nullable()->constrained()->nullOnDelete();
            $table->date('race_date');
            $table->unsignedSmallInteger('race_number')->nullable();
            $table->timestampTz('scheduled_start_at')->nullable();
            $table->string('name')->nullable();
            $table->string('grade', 40)->nullable();
            $table->string('race_type', 80)->nullable();
            $table->unsignedSmallInteger('entrant_count')->nullable();
            $table->string('result_status', 40)->default('UNAVAILABLE');
            $table->text('race_card_url')->nullable();
            $table->text('result_url')->nullable();
            $table->timestampTz('result_confirmed_at')->nullable();
            $table->timestampTz('last_fetched_at')->nullable();
            $table->timestampsTz();

            $table->unique(['source', 'external_race_id']);
            $table->index(['race_date', 'racetrack_id']);
        });

        Schema::create('race_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_player_id', 32)->nullable();
            $table->unsignedSmallInteger('bike_number');
            $table->unsignedSmallInteger('frame_number')->nullable();
            $table->string('grade', 40)->nullable();
            $table->decimal('race_score', 6, 2)->nullable();
            $table->string('line_text')->nullable();
            $table->timestampTz('fetched_at');
            $table->timestampsTz();

            $table->unique(['race_id', 'bike_number']);
        });

        Schema::create('race_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('race_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('bike_number')->nullable();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->string('result_status', 40);
            $table->string('winning_technique', 40)->nullable();
            $table->string('raw_result_text')->nullable();
            $table->timestampTz('fetched_at');
            $table->timestampsTz();

            $table->unique(['race_id', 'bike_number']);
            $table->index(['race_id', 'result_status']);
        });

        Schema::create('race_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->string('bet_type_code', 40);
            $table->string('combination', 80);
            $table->unsignedInteger('payout_amount')->nullable();
            $table->unsignedInteger('popularity')->nullable();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->text('source_url')->nullable();
            $table->timestampTz('fetched_at');
            $table->timestampsTz();

            $table->unique(['race_id', 'bet_type_code', 'combination', 'sequence']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE players ADD CONSTRAINT players_gender_check CHECK (gender IS NULL OR gender IN ('male', 'female', 'unknown'))");
            DB::statement("ALTER TABLE races ADD CONSTRAINT races_result_status_check CHECK (result_status IN ('UNAVAILABLE', 'PROVISIONAL', 'UNDER_REVIEW', 'CONFIRMED', 'CORRECTED', 'CANCELLED'))");
            DB::statement("ALTER TABLE race_results ADD CONSTRAINT race_results_status_check CHECK (result_status IN ('FINISHED', 'TIED', 'DISQUALIFIED', 'DID_NOT_START', 'DID_NOT_FINISH', 'WITHDRAWN', 'CRASHED', 'UNKNOWN'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('race_payouts');
        Schema::dropIfExists('race_results');
        Schema::dropIfExists('race_entries');
        Schema::dropIfExists('races');
        Schema::dropIfExists('racetracks');
        Schema::dropIfExists('player_stat_snapshots');
        Schema::dropIfExists('player_status_histories');
        Schema::dropIfExists('players');
        Schema::dropIfExists('scraping_fetch_logs');
        Schema::dropIfExists('batch_run_items');
        Schema::dropIfExists('batch_runs');
    }
};
