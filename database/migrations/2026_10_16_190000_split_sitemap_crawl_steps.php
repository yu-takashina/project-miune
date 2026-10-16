<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sitemaps', function (Blueprint $table) {
            $table->dropColumn('recrawl_after_page_id');
        });

        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->unsignedInteger('fetch_failure_count')->default(0)->after('error_message');
            $table->timestamp('fetch_retry_at')->nullable()->after('fetch_failure_count');
            $table->unsignedInteger('embedding_failure_count')->default(0)->after('embedding_failed_sha256');
            $table->timestamp('embedding_retry_at')->nullable()->after('embedding_failure_count');
        });

        Schema::create('crawl_error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->foreignId('sitemap_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sitemap_page_id')->nullable()->constrained()->nullOnDelete();
            $table->text('url')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('message');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crawl_error_logs');

        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->dropColumn([
                'fetch_failure_count',
                'fetch_retry_at',
                'embedding_failure_count',
                'embedding_retry_at',
            ]);
        });

        Schema::table('sitemaps', function (Blueprint $table) {
            $table->unsignedBigInteger('recrawl_after_page_id')->nullable()->after('absence_limit');
        });
    }
};
