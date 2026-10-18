<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::create('search_terms', function (Blueprint $table) {
                $table->id();
                $table->text('term');
                $table->char('term_sha256', 64);
                $table->string('model');
                $table->text('embedding');
                $table->timestamp('embedded_at')->nullable();

                $table->unique(['term_sha256', 'model']);
            });

            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE search_terms (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                term TEXT NOT NULL,
                term_sha256 CHAR(64) NOT NULL,
                model VARCHAR(255) NOT NULL,
                embedding VECTOR(1536) NOT NULL,
                embedded_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY search_terms_term_sha256_model_unique (term_sha256, model)
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_terms');
    }
};
