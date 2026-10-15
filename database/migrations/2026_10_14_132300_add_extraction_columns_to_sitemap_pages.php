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
        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->longText('readability_text')->nullable()->after('body_sha256');
            $table->longText('trafilatura_text')->nullable()->after('readability_text');
            $table->string('extraction_status')->nullable()->after('trafilatura_text');
            $table->timestamp('extracted_at')->nullable()->after('extraction_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->dropColumn([
                'readability_text',
                'trafilatura_text',
                'extraction_status',
                'extracted_at',
            ]);
        });
    }
};
