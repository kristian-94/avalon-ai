<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedInteger('api_calls')->default(0)->after('current_phase');
            $table->unsignedInteger('total_tokens')->default(0)->after('api_calls');
            $table->unsignedInteger('prompt_tokens')->default(0)->after('total_tokens');
            $table->unsignedInteger('completion_tokens')->default(0)->after('prompt_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['api_calls', 'total_tokens', 'prompt_tokens', 'completion_tokens']);
        });
    }
};
