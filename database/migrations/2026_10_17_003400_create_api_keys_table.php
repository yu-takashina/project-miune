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
        $binary = Schema::getConnection()->getDriverName() !== 'sqlite';

        Schema::create('api_keys', function (Blueprint $table) use ($binary) {
            $table->id();
            $token = $table->string('token', 32)->unique();

            if ($binary) {
                $token->collation('utf8mb4_bin');
            }

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
