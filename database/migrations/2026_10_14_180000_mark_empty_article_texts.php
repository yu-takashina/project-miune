<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('sitemap_pages')
            ->where('article_text', '')
            ->update([
                'extraction_status' => 'empty',
                'article_text_sha256' => null,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('sitemap_pages')
            ->where('article_text', '')
            ->update([
                'article_text_sha256' => hash('sha256', ''),
            ]);
    }
};
