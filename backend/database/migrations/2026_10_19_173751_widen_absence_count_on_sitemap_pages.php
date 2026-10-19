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
            $table->unsignedInteger('absence_count')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sitemap_pages', function (Blueprint $table) {
            $table->unsignedTinyInteger('absence_count')->default(0)->change();
        });
    }
};
