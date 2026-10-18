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
            $table->char('article_text_sha256', 64)->nullable()->after('article_text');
        });

        $pages = DB::table('sitemap_pages')
            ->select(['id', 'article_text'])
            ->whereNotNull('article_text')
            ->orderBy('id')
            ->lazyById();

        foreach ($pages as $page) {
            $id = $page->id;
            $articleText = $page->article_text;

            if ((! is_int($id) && ! is_string($id)) || ! is_string($articleText)) {
                continue;
            }

            DB::table('sitemap_pages')->where('id', $id)->update([
                'article_text_sha256' => hash('sha256', $articleText),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->dropColumn('article_text_sha256');
        });
    }
};
