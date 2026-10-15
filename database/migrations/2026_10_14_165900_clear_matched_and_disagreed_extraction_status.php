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
            ->whereIn('extraction_status', ['matched', 'disagreed'])
            ->update([
                'extraction_status' => null,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 一致と不一致は、採用した本文からは復元できない。
    }
};
