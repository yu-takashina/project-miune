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
        Schema::create('sitemaps', function (Blueprint $table) {
            $table->id();
            $table->text('url');
            $table->char('url_sha256', 64)->unique();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('absence_limit')->nullable()->default(1);
            $table->unsignedBigInteger('recrawl_after_page_id')->nullable();
            $table->timestamp('crawled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sitemap_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sitemap_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_sha256', 64);
            $table->boolean('searchable')->default(true);
            $table->timestamp('unlisted_at')->nullable();
            $table->unsignedTinyInteger('absence_count')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('response_bytes')->nullable();
            $table->longText('body')->nullable();
            $table->char('body_sha256', 64)->nullable();
            $table->timestamp('crawled_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['sitemap_id', 'url_sha256']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sitemap_pages');
        Schema::dropIfExists('sitemaps');
    }
};
