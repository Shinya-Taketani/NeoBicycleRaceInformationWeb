<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraping_fetch_logs', function (Blueprint $table): void {
            $table->json('request_parameters')->nullable();
        });

        Schema::table('race_days', function (Blueprint $table): void {
            $table->string('encrypted_parameter', 255)->nullable();
        });

        Schema::table('races', function (Blueprint $table): void {
            $table->timestampTz('sales_close_at')->nullable();
            $table->string('encrypted_parameter', 255)->nullable();
            $table->boolean('result_available')->default(false);
        });

        Schema::table('race_entries', function (Blueprint $table): void {
            $table->string('player_name')->nullable();
            $table->string('prefecture', 40)->nullable();
            $table->string('riding_style', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('race_entries', function (Blueprint $table): void {
            $table->dropColumn(['player_name', 'prefecture', 'riding_style']);
        });

        Schema::table('races', function (Blueprint $table): void {
            $table->dropColumn(['sales_close_at', 'encrypted_parameter', 'result_available']);
        });

        Schema::table('race_days', function (Blueprint $table): void {
            $table->dropColumn('encrypted_parameter');
        });

        Schema::table('scraping_fetch_logs', function (Blueprint $table): void {
            $table->dropColumn('request_parameters');
        });
    }
};
