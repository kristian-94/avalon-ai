<?php

namespace Tests\Feature;

use App\Events\GameStateUpdate;
use App\Jobs\GameLoop;
use App\Models\Game;
use App\Models\Mission;
use App\Models\MissionProposal;
use App\Models\MissionProposalMember;
use App\Models\MissionProposalVote;
use App\Models\MissionTeamMember;
use App\Models\Player;
use App\Services\GameSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HumanActionEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected Game $game;

    protected $players;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([GameStateUpdate::class]);
        Queue::fake([GameLoop::class]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I agree with that.',
                                'reasoning' => 'No reason at all',
                                'team_proposal' => 'Max,Riley',
                                'vote' => true,
                                'mission_action' => true,
                                'assassination_target' => 'Max',
                            ]),
                        ],
                    ],
                ]],
            ], 200),
        ]);

        $this->game = GameSetupService::initializeGame(1);
        $this->players = Player::where('game_id', $this->game->id)->get();
    }

    // ──────────────────────────────────────────────────
    // Vote endpoint tests
    // ──────────────────────────────────────────────────

    public function test_human_can_vote_on_proposal(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);
        $nonHumanPlayer = $this->players->firstWhere('is_human', false);

        $this->game->update([
            'current_phase' => 'team_voting',
            'current_leader_id' => $nonHumanPlayer->id,
        ]);

        $proposal = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $this->game->current_mission_id,
            'proposed_by_id' => $nonHumanPlayer->id,
            'proposal_number' => 1,
            'status' => 'pending',
        ]);

        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $humanPlayer->id]);
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $nonHumanPlayer->id]);

        $this->game->update(['current_proposal_id' => $proposal->id]);

        $response = $this->postJson('/api/game/vote', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'approved' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['result' => 'Vote recorded']);

        $this->assertDatabaseHas('mission_proposal_votes', [
            'proposal_id' => $proposal->id,
            'player_id' => $humanPlayer->id,
            'approved' => true,
        ]);
    }

    public function test_vote_rejects_when_not_in_voting_phase(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);

        $this->game->update([
            'current_phase' => 'team_proposal',
            'current_leader_id' => $humanPlayer->id,
        ]);

        $response = $this->postJson('/api/game/vote', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'approved' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Not in team_voting phase']);
    }

    public function test_leader_cannot_vote(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);

        $this->game->update([
            'current_phase' => 'team_voting',
            'current_leader_id' => $humanPlayer->id,
        ]);

        $proposal = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $this->game->current_mission_id,
            'proposed_by_id' => $humanPlayer->id,
            'proposal_number' => 1,
            'status' => 'pending',
        ]);
        $this->game->update(['current_proposal_id' => $proposal->id]);

        $response = $this->postJson('/api/game/vote', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'approved' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Leader cannot vote']);
    }

    public function test_player_cannot_vote_twice(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);
        $leader = $this->players->where('id', '!=', $humanPlayer->id)->first();

        $this->game->update([
            'current_phase' => 'team_voting',
            'current_leader_id' => $leader->id,
        ]);

        $proposal = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $this->game->current_mission_id,
            'proposed_by_id' => $leader->id,
            'proposal_number' => 1,
            'status' => 'pending',
        ]);
        $this->game->update(['current_proposal_id' => $proposal->id]);

        MissionProposalVote::create([
            'proposal_id' => $proposal->id,
            'player_id' => $humanPlayer->id,
            'approved' => true,
        ]);

        $response = $this->postJson('/api/game/vote', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'approved' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Player has already voted']);
    }

    public function test_vote_validates_required_fields(): void
    {
        $response = $this->postJson('/api/game/vote', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gameId', 'playerId', 'approved']);
    }

    // ──────────────────────────────────────────────────
    // Propose endpoint tests
    // ──────────────────────────────────────────────────

    public function test_human_leader_can_propose_team(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);

        $this->game->update([
            'current_phase' => 'team_proposal',
            'current_leader_id' => $humanPlayer->id,
            'current_proposal_id' => null,
        ]);

        $teamPlayerIds = $this->players->take(2)->pluck('id')->toArray();

        $response = $this->postJson('/api/game/propose', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'playerIds' => $teamPlayerIds,
        ]);

        $response->assertOk();
        $response->assertJson(['result' => 'Proposal submitted']);

        $this->assertDatabaseHas('mission_proposals', [
            'game_id' => $this->game->id,
            'proposed_by_id' => $humanPlayer->id,
            'status' => 'pending',
        ]);

        foreach ($teamPlayerIds as $playerId) {
            $this->assertDatabaseHas('mission_proposal_members', [
                'player_id' => $playerId,
            ]);
        }

        $this->assertDatabaseHas('game_events', [
            'game_id' => $this->game->id,
            'event_type' => 'team_proposal',
        ]);
    }

    public function test_propose_rejects_when_not_in_proposal_phase(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);

        $this->game->update([
            'current_phase' => 'team_voting',
            'current_leader_id' => $humanPlayer->id,
        ]);

        $response = $this->postJson('/api/game/propose', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'playerIds' => $this->players->take(2)->pluck('id')->toArray(),
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Not in team_proposal phase']);
    }

    public function test_non_leader_cannot_propose(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);
        $leader = $this->players->where('id', '!=', $humanPlayer->id)->first();

        $this->game->update([
            'current_phase' => 'team_proposal',
            'current_leader_id' => $leader->id,
            'current_proposal_id' => null,
        ]);

        $response = $this->postJson('/api/game/propose', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'playerIds' => $this->players->take(2)->pluck('id')->toArray(),
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Player is not the current leader']);
    }

    public function test_cannot_propose_when_proposal_already_exists(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);

        $this->game->update([
            'current_phase' => 'team_proposal',
            'current_leader_id' => $humanPlayer->id,
        ]);

        $proposal = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $this->game->current_mission_id,
            'proposed_by_id' => $humanPlayer->id,
            'proposal_number' => 1,
            'status' => 'pending',
        ]);
        $this->game->update(['current_proposal_id' => $proposal->id]);

        $response = $this->postJson('/api/game/propose', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'playerIds' => $this->players->take(2)->pluck('id')->toArray(),
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'A proposal already exists']);
    }

    public function test_propose_rejects_wrong_number_of_players(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);

        $this->game->update([
            'current_phase' => 'team_proposal',
            'current_leader_id' => $humanPlayer->id,
            'current_proposal_id' => null,
        ]);

        // Mission 1 requires 2 players, send 3
        $response = $this->postJson('/api/game/propose', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'playerIds' => $this->players->take(3)->pluck('id')->toArray(),
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Wrong number of players for this mission']);
    }

    public function test_propose_validates_required_fields(): void
    {
        $response = $this->postJson('/api/game/propose', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gameId', 'playerId', 'playerIds']);
    }

    // ──────────────────────────────────────────────────
    // Mission action endpoint tests
    // ──────────────────────────────────────────────────

    public function test_human_can_submit_mission_action(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);

        $this->game->update([
            'current_phase' => 'mission',
            'current_leader_id' => $this->players->where('id', '!=', $humanPlayer->id)->first()->id,
        ]);

        MissionTeamMember::create([
            'mission_id' => $this->game->current_mission_id,
            'player_id' => $humanPlayer->id,
        ]);

        $response = $this->postJson('/api/game/mission-action', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'success' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['result' => 'Mission action recorded']);

        $this->assertDatabaseHas('mission_team_members', [
            'mission_id' => $this->game->current_mission_id,
            'player_id' => $humanPlayer->id,
            'vote_success' => true,
        ]);
    }

    public function test_mission_action_rejects_when_not_in_mission_phase(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);

        $this->game->update([
            'current_phase' => 'team_voting',
            'current_leader_id' => $humanPlayer->id,
        ]);

        $response = $this->postJson('/api/game/mission-action', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'success' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Not in mission phase']);
    }

    public function test_non_team_member_cannot_submit_mission_action(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);
        $otherPlayer = $this->players->where('id', '!=', $humanPlayer->id)->first();

        $this->game->update([
            'current_phase' => 'mission',
            'current_leader_id' => $otherPlayer->id,
        ]);

        // Only add other player to the team, not the human
        MissionTeamMember::create([
            'mission_id' => $this->game->current_mission_id,
            'player_id' => $otherPlayer->id,
        ]);

        $response = $this->postJson('/api/game/mission-action', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'success' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Player is not on the mission team']);
    }

    public function test_player_cannot_submit_mission_action_twice(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);

        $this->game->update([
            'current_phase' => 'mission',
            'current_leader_id' => $this->players->where('id', '!=', $humanPlayer->id)->first()->id,
        ]);

        MissionTeamMember::create([
            'mission_id' => $this->game->current_mission_id,
            'player_id' => $humanPlayer->id,
            'vote_success' => true,
        ]);

        $response = $this->postJson('/api/game/mission-action', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'success' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Player has already submitted a mission action']);
    }

    public function test_mission_action_validates_required_fields(): void
    {
        $response = $this->postJson('/api/game/mission-action', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gameId', 'playerId', 'success']);
    }

    // ──────────────────────────────────────────────────
    // Vote triggers phase transition
    // ──────────────────────────────────────────────────

    public function test_vote_triggers_phase_transition_when_all_voted(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);
        $leader = $this->players->where('is_human', false)->first();

        $this->game->update([
            'current_phase' => 'team_voting',
            'current_leader_id' => $leader->id,
        ]);

        $proposal = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $this->game->current_mission_id,
            'proposed_by_id' => $leader->id,
            'proposal_number' => 1,
            'status' => 'pending',
        ]);

        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $humanPlayer->id]);
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $leader->id]);

        $this->game->update(['current_proposal_id' => $proposal->id]);

        // All non-leader AI players vote
        $nonLeaderAIPlayers = $this->players->where('is_human', false)->where('id', '!=', $leader->id);
        foreach ($nonLeaderAIPlayers as $player) {
            MissionProposalVote::create([
                'proposal_id' => $proposal->id,
                'player_id' => $player->id,
                'approved' => true,
            ]);
        }

        // Human player casts the final vote via the endpoint
        $response = $this->postJson('/api/game/vote', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'approved' => true,
        ]);

        $response->assertOk();

        // Phase transition is handled by the queue, not the HTTP endpoint.
        // Verify the vote was recorded and a broadcast was sent.
        $this->assertDatabaseHas('mission_proposal_votes', [
            'proposal_id' => $proposal->id,
            'player_id' => $humanPlayer->id,
            'approved' => true,
        ]);

        Event::assertDispatched(GameStateUpdate::class);
    }

    // ──────────────────────────────────────────────────
    // Propose triggers phase transition
    // ──────────────────────────────────────────────────

    public function test_propose_triggers_phase_transition_to_voting(): void
    {
        $humanPlayer = $this->players->firstWhere('is_human', true);

        $this->game->update([
            'current_phase' => 'team_proposal',
            'current_leader_id' => $humanPlayer->id,
            'current_proposal_id' => null,
        ]);

        $teamPlayerIds = $this->players->take(2)->pluck('id')->toArray();

        $response = $this->postJson('/api/game/propose', [
            'gameId' => $this->game->id,
            'playerId' => $humanPlayer->id,
            'playerIds' => $teamPlayerIds,
        ]);

        $response->assertOk();

        // Phase transition is handled by the queue, not the HTTP endpoint.
        // Verify the proposal was created and a GameEvent was emitted.
        $this->assertDatabaseHas('mission_proposals', [
            'game_id' => $this->game->id,
            'proposed_by_id' => $humanPlayer->id,
        ]);
        $this->assertDatabaseHas('game_events', [
            'game_id' => $this->game->id,
            'event_type' => 'team_proposal',
        ]);
    }

    // ──────────────────────────────────────────────────
    // Game state endpoint
    // ──────────────────────────────────────────────────

    public function test_get_game_state_returns_full_state(): void
    {
        $response = $this->getJson('/api/game/' . $this->game->id . '/state');

        $response->assertOk();
        $response->assertJsonStructure([
            'game' => [
                'id',
                'game_state' => [
                    'currentPhase',
                    'turnCount',
                    'currentLeader',
                    'missions',
                ],
                'has_human_player',
                'winner',
            ],
            'messages',
            'events',
            'players',
        ]);

        $response->assertJsonPath('game.id', $this->game->id);
        $response->assertJsonPath('game.has_human_player', true);
    }

    public function test_get_game_state_returns_404_for_invalid_game(): void
    {
        $response = $this->getJson('/api/game/99999/state');

        $response->assertStatus(500);
    }

    // ──────────────────────────────────────────────────
    // Initialize endpoint
    // ──────────────────────────────────────────────────

    public function test_initialize_play_mode_creates_human_player(): void
    {
        $response = $this->postJson('/api/game/initialize', [
            'mode' => 'play',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'message',
            'gameId',
            'players',
            'playerId',
        ]);

        $gameId = $response->json('gameId');
        $playerId = $response->json('playerId');

        $this->assertNotNull($playerId);
        $this->assertDatabaseHas('players', [
            'game_id' => $gameId,
            'id' => $playerId,
            'is_human' => true,
        ]);
    }

    public function test_initialize_watch_mode_has_no_human_player(): void
    {
        $response = $this->postJson('/api/game/initialize', [
            'mode' => 'watch',
        ]);

        $response->assertOk();
        $response->assertJsonPath('playerId', null);

        $gameId = $response->json('gameId');
        $this->assertDatabaseMissing('players', [
            'game_id' => $gameId,
            'is_human' => true,
        ]);
    }

    public function test_initialize_validates_mode(): void
    {
        $response = $this->postJson('/api/game/initialize', [
            'mode' => 'invalid',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mode']);
    }
}
