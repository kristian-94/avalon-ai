<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('has_human_player')->default(false);
            $table->json('game_state')->nullable();
            $table->enum('winner', ['good', 'evil'])->nullable();
            $table->timestamps();
        });

        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->integer('player_index');
            $table->string('name');
            $table->enum('role', ['merlin', 'assassin', 'loyal_servant', 'minion']);
            $table->boolean('is_human')->default(false);
            $table->json('role_knowledge')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'player_index']);
        });

        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->enum('event_type', [
                'game_start',
                'team_proposal',
                'team_vote',
                'mission_vote',
                'mission_complete',
                'assassination_attempt',
                'game_end'
            ]);
            $table->foreignId('player_id')->nullable()->constrained()->onDelete('cascade');
            $table->json('event_data')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->foreignId('player_id')->nullable()->constrained()->onDelete('cascade');
            $table->enum('message_type', ['public_chat', 'private_thought', 'game_event']);
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('game_events');
        Schema::dropIfExists('players');
        Schema::dropIfExists('games');
    }
};
