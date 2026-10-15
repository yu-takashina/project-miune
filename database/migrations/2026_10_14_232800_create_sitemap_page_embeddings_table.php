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
        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->text('embedding_error')->nullable()->after('article_text_sha256');
            $table->char('embedding_failed_sha256', 64)->nullable()->after('embedding_error');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::create('sitemap_page_embeddings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sitemap_page_id')->constrained()->cascadeOnDelete();
                $table->text('embedding');
                $table->char('article_text_sha256', 64);
                $table->timestamp('embedded_at')->nullable();
            });

            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE sitemap_page_embeddings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                sitemap_page_id BIGINT UNSIGNED NOT NULL,
                embedding VECTOR(1536) NOT NULL,
                article_text_sha256 CHAR(64) NOT NULL,
                embedded_at TIMESTAMP NULL DEFAULT NULL,
                INDEX sitemap_page_embeddings_sitemap_page_id_index (sitemap_page_id),
                CONSTRAINT sitemap_page_embeddings_sitemap_page_id_foreign
                    FOREIGN KEY (sitemap_page_id) REFERENCES sitemap_pages (id) ON DELETE CASCADE
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        DB::statement('ALTER TABLE sitemap_page_embeddings ADD VECTOR INDEX embedding (embedding) M=8 DISTANCE=cosine');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sitemap_page_embeddings');

        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->dropColumn(['embedding_error', 'embedding_failed_sha256']);
        });
    }
};
