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
            $table->longText('article_text')->nullable()->after('body_sha256');
        });

        DB::table('sitemap_pages')
            ->whereIn('extraction_status', ['disagreed', 'matched', 'empty'])
            ->update([
                'article_text' => DB::raw('trafilatura_text'),
            ]);

        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->dropColumn(['readability_text', 'trafilatura_text']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->longText('readability_text')->nullable()->after('body_sha256');
            $table->longText('trafilatura_text')->nullable()->after('readability_text');
        });

        DB::table('sitemap_pages')
            ->whereNotNull('article_text')
            ->update([
                'trafilatura_text' => DB::raw('article_text'),
            ]);

        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->dropColumn('article_text');
        });
    }
};
