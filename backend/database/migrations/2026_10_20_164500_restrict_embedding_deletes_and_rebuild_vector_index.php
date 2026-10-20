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
        $this->rebuild(function (Blueprint $table): void {
            $table->foreign('sitemap_page_id')->references('id')->on('sitemap_pages')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->rebuild(function (Blueprint $table): void {
            $table->foreign('sitemap_page_id')->references('id')->on('sitemap_pages')->cascadeOnDelete();
        });
    }

    /**
     * 連鎖削除で壊れたベクトル索引は、行を足しても OPTIMIZE TABLE でも直らない（MDEV-41227）。作り直すしかない。
     * 作り直すときに M と DISTANCE を省くと、既定の euclidean になり検索結果が変わる。
     *
     * @param  Closure(Blueprint): void  $foreign
     */
    private function rebuild(Closure $foreign): void
    {
        $vector = Schema::getConnection()->getDriverName() !== 'sqlite';

        if ($vector) {
            DB::statement('ALTER TABLE sitemap_page_embeddings DROP INDEX embedding');
        }

        Schema::table('sitemap_page_embeddings', function (Blueprint $table) use ($foreign): void {
            $table->dropForeign(['sitemap_page_id']);
            $foreign($table);
        });

        if ($vector) {
            DB::statement('ALTER TABLE sitemap_page_embeddings ADD VECTOR INDEX embedding (embedding) M=8 DISTANCE=cosine');
        }
    }
};
