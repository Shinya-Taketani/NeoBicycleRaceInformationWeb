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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE race_results ALTER COLUMN raw_result_text TYPE TEXT');

            return;
        }

        Schema::table('race_results', function (Blueprint $table): void {
            $table->text('raw_result_text')->nullable()->change();
        });
    }

    public function down(): void
    {
        // SQLite does not enforce varchar lengths, so protect the narrowing explicitly on every driver.
        $oversizedCount = DB::table('race_results')
            ->whereNotNull('raw_result_text')
            ->whereRaw('LENGTH(raw_result_text) > 255')
            ->count();

        if ($oversizedCount > 0) {
            throw new RuntimeException(
                "Cannot change race_results.raw_result_text to varchar(255): {$oversizedCount} row(s) exceed 255 characters.",
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE race_results ALTER COLUMN raw_result_text TYPE VARCHAR(255)');

            return;
        }

        Schema::table('race_results', function (Blueprint $table): void {
            $table->string('raw_result_text', 255)->nullable()->change();
        });
    }
};
