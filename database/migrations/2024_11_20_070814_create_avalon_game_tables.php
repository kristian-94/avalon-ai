<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Core game table
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('has_human_player')->default(false);
            // Current game state fields
            $table->enum('current_phase', [
                'setup',
                'team_proposal',
                'team_voting',
                'mission',
            ])->default('setup');
            $table->integer('turn_count')->default(0);
            $table->enum('winner', ['good', 'evil'])->nullable();
            $table->timestamps();
        });

        // Players table
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->integer('player_index');
            $table->string('name');
            $table->enum('role', [
                'merlin',
                'assassin',
                'loyal_servant',
                'minion'
            ]);
            $table->boolean('is_human')->default(false);
            $table->json('role_knowledge')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'player_index']);
        });

        // Add foreign key to games for current leader
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('current_leader_id')->nullable()
                ->constrained('players')
                ->onDelete('set null');
        });

        // Missions table
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->integer('mission_number');
            $table->integer('required_players');
            $table->enum('status', [
                'pending',
                'success',
                'fail'
            ])->default('pending');
            $table->integer('success_votes')->default(0);
            $table->integer('fail_votes')->default(0);
            $table->timestamps();

            $table->unique(['game_id', 'mission_number']);
        });

        // Add foreign key to games for current mission
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('current_mission_id')->nullable()
                ->constrained('missions')
                ->onDelete('set null');
        });

        // Mission proposals table
        Schema::create('mission_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->foreignId('mission_id')->constrained()->onDelete('cascade');
            $table->foreignId('proposed_by_id')->constrained('players')->onDelete('cascade');
            $table->integer('proposal_number');
            $table->enum('status', [
                'pending',
                'approved',
                'rejected'
            ])->default('pending');
            $table->timestamps();

            $table->unique(['mission_id', 'proposal_number']);
        });

        // Add foreign key to games for current proposal
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('current_proposal_id')->nullable()
                ->constrained('mission_proposals')
                ->onDelete('set null');
        });

        // Mission proposal team members table
        Schema::create('mission_proposal_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')
                ->constrained('mission_proposals')
                ->onDelete('cascade');
            $table->foreignId('player_id')
                ->constrained()
                ->onDelete('cascade');
            $table->timestamps();

            $table->unique(['proposal_id', 'player_id']);
        });

        // Mission proposal votes table
        Schema::create('mission_proposal_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')
                ->constrained('mission_proposals')
                ->onDelete('cascade');
            $table->foreignId('player_id')
                ->constrained()
                ->onDelete('cascade');
            $table->boolean('approved');
            $table->timestamps();

            $table->unique(['proposal_id', 'player_id']);
        });

        // Mission team members and their votes table
        Schema::create('mission_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('player_id')
                ->constrained()
                ->onDelete('cascade');
            $table->boolean('vote_success')->nullable();
            $table->timestamps();

            $table->unique(['mission_id', 'player_id']);
        });

        // Game events table for logging game history
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->enum('event_type', [
                'game_start',
                'team_proposal',
                'team_vote',
                'mission_vote',
                'mission_complete',
                'assassination',
                'game_end'
            ]);
            $table->foreignId('player_id')->nullable()
                ->constrained()
                ->onDelete('cascade');
            $table->json('event_data')->nullable();
            $table->timestamps();
        });

        // Messages table for chat and system messages
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->foreignId('player_id')->nullable()
                ->constrained()
                ->onDelete('cascade');
            $table->enum('message_type', [
                'system_prompt',
                'game_event',
                'public_chat',
                'private_thought',
            ]);
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Drop tables in reverse order of creation
        Schema::dropIfExists('messages');
        Schema::dropIfExists('game_events');
        Schema::dropIfExists('mission_team_members');
        Schema::dropIfExists('mission_proposal_votes');
        Schema::dropIfExists('mission_proposal_members');
        // Remove foreign keys from games table first
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['current_proposal_id']);
            $table->dropForeign(['current_mission_id']);
            $table->dropForeign(['current_leader_id']);
        });
        Schema::dropIfExists('mission_proposals');
        Schema::dropIfExists('missions');
        Schema::dropIfExists('players');
        Schema::dropIfExists('games');
    }
};
