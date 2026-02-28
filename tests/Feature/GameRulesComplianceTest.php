<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\Mission;
use App\Models\MissionProposal;
use App\Models\MissionProposalMember;
use App\Jobs\GameLoop;
use App\Services\GameSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GameRulesComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_game_transitions_with_team_proposals()
    {
        // Mock HTTP responses for OpenAI API
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I agree with this proposal.',
                                'reasoning' => 'Test reasoning',
                                'team_proposal' => 'Max,Riley',
                                'vote' => true,
                                'mission_action' => true,
                                'assassination_target' => 'Max'
                            ])
                        ]
                    ]
                ]]
            ], 200)
        ]);
        
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'team_proposal']);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players->first()->id]);

        // Mission 1 should require exactly 2 players
        $gameLoop = new GameLoop($game->id);
        
        // Create a proposal with correct team size
        $proposal = MissionProposal::create([
            'game_id' => $game->id,
            'mission_id' => $game->current_mission_id,
            'proposed_by_id' => $players->first()->id,
            'proposal_number' => 1,
            'status' => 'pending'
        ]);
        
        // Add 2 team members (correct size for mission 1)
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $players[0]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $players[1]->id]);

        $game->update(['current_proposal_id' => $proposal->id]);
        
        // The game should transition to voting
        $gameLoop->checkPhaseTransition($game->fresh());
        
        $game->refresh();
        $this->assertEquals('team_voting', $game->current_phase); // Should move to voting phase
        $this->assertEquals('pending', $proposal->fresh()->status); // Proposal should remain pending until voted on
    }

    public function test_evil_players_can_sabotage_missions()
    {
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'mission']);
        $players = Player::where('game_id', $game->id)->get();
        
        // Find evil player (assassin or minion)
        $evilPlayer = $players->whereIn('role', ['assassin', 'minion'])->first();
        $goodPlayer = $players->where('role', 'loyal_servant')->first();
        
        $mission = $game->currentMission;
        $mission->teamMembers()->create(['player_id' => $goodPlayer->id]);
        $mission->teamMembers()->create(['player_id' => $evilPlayer->id]);

        $gameLoop = new GameLoop($game->id);
        
        // Mock responses for mission cards - using conditional responses
        Http::fake(function ($request) use ($goodPlayer, $evilPlayer, $game) {
            // Check which player this is for based on the messages in the request
            $requestBody = json_decode($request->body(), true);
            $messages = $requestBody['messages'] ?? [];
            
            // Find if this is for the evil player by checking system messages
            $isEvilPlayer = false;
            foreach ($messages as $message) {
                if ($message['role'] === 'system' && 
                    (str_contains($message['content'], 'Your role is the Assassin') ||
                     str_contains($message['content'], 'Your role is Minion'))) {
                    $isEvilPlayer = true;
                    break;
                }
            }
            
            if ($isEvilPlayer) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'function_call' => [
                                'name' => 'game_response',
                                'arguments' => json_encode([
                                    'message' => 'This mission is doomed!',
                                    'reasoning' => 'Evil can sabotage',
                                    'team_proposal' => '',
                                    'vote' => true,
                                    'mission_action' => false,
                                    'assassination_target' => null
                                ])
                            ]
                        ]
                    ]]
                ]);
            } else {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'function_call' => [
                                'name' => 'game_response',
                                'arguments' => json_encode([
                                    'message' => 'I will do my best for the mission.',
                                    'reasoning' => 'Good players must play success',
                                    'team_proposal' => '',
                                    'vote' => true,
                                    'mission_action' => true,
                                    'assassination_target' => null
                                ])
                            ]
                        ]
                    ]]
                ]);
            }
        });

        // Process AI turns for mission
        $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
        
        // Ensure we know which player is which
        $this->assertCount(2, $eligiblePlayers, 'Should have 2 eligible players for mission');
        
        // Sort players to ensure consistent order - good player first, evil player second
        $eligiblePlayers = collect($eligiblePlayers)->sortBy(function($player) use ($goodPlayer) {
            return $player->id === $goodPlayer->id ? 0 : 1;
        })->values()->all();
        
        // Check the players and their votes
        foreach ($eligiblePlayers as $player) {
            if (!$player->is_human) {
                $gameLoop->processAIPlayerTurn($game->fresh(), $player);
            }
        }
        
        // Check mission votes were recorded
        $mission->refresh();
        $goodPlayerVote = $mission->teamMembers()->where('player_id', $goodPlayer->id)->first();
        $evilPlayerVote = $mission->teamMembers()->where('player_id', $evilPlayer->id)->first();
        
        $this->assertNotNull($goodPlayerVote->vote_success, 'Good player should have voted');
        $this->assertTrue($goodPlayerVote->vote_success, 'Good player should vote success');
        $this->assertNotNull($evilPlayerVote->vote_success, 'Evil player should have voted');
        $this->assertFalse($evilPlayerVote->vote_success, 'Evil player should vote fail');
        
        $gameLoop->checkPhaseTransition($game->fresh());
        
        $mission->refresh();
        $this->assertEquals('fail', $mission->status);
    }

    public function test_merlin_assassination_mechanic()
    {
        $game = GameSetupService::initializeGame(0);
        
        // Set up game state for assassination phase
        // Mark 3 missions as successful
        $missions = $game->missions()->take(3)->get();
        foreach ($missions as $mission) {
            $mission->update(['status' => 'success']);
        }
        
        $game->update(['current_phase' => 'assassination']);
        
        $players = Player::where('game_id', $game->id)->get();
        $merlin = $players->where('role', 'merlin')->first();
        $assassin = $players->where('role', 'assassin')->first();
        
        $game->update(['current_leader_id' => $assassin->id]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I believe ' . $merlin->name . ' is Merlin.',
                                'reasoning' => 'They knew too much',
                                'team_proposal' => '',
                                'vote' => true,
                                'mission_action' => true,
                                'assassination_target' => $merlin->name
                            ])
                        ]
                    ]
                ]]
            ], 200)
        ]);

        $gameLoop = new GameLoop($game->id);
        
        // Process assassination
        $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
        foreach ($eligiblePlayers as $player) {
            if (!$player->is_human) {
                $gameLoop->processAIPlayerTurn($game->fresh(), $player);
            }
        }
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        $this->assertEquals('debrief', $game->current_phase);
        $this->assertEquals('evil', $game->winner);
    }

    public function test_consecutive_vote_failures_trigger_evil_win()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I reject this team.',
                                'reasoning' => 'Too many failures',
                                'team_proposal' => '',
                                'vote' => false,
                                'mission_action' => true,
                                'assassination_target' => null
                            ])
                        ]
                    ]
                ]]
            ], 200)
        ]);
        
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'team_voting']);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players->first()->id]);
        
        // Set up 4 previous failures
        $mission = $game->currentMission;
        for ($i = 1; $i <= 4; $i++) {
            $proposal = MissionProposal::create([
                'game_id' => $game->id,
                'mission_id' => $mission->id,
                'proposed_by_id' => $players[$i-1]->id,
                'proposal_number' => $i,
                'status' => 'rejected'
            ]);
        }
        
        // Create 5th proposal
        $proposal = MissionProposal::create([
            'game_id' => $game->id,
            'mission_id' => $mission->id,
            'proposed_by_id' => $players->last()->id,
            'proposal_number' => 5,
            'status' => 'pending'
        ]);
        
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $players[0]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $players[1]->id]);
        
        $game->update(['current_proposal_id' => $proposal->id]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I reject this team.',
                                'reasoning' => 'Too many failures',
                                'team_proposal' => '',
                                'vote' => false,
                                'mission_action' => true,
                                'assassination_target' => null
                            ])
                        ]
                    ]
                ]]
            ], 200)
        ]);

        $gameLoop = new GameLoop($game->id);
        
        // All eligible players vote reject (5th consecutive failure)
        $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
        foreach ($eligiblePlayers as $player) {
            $gameLoop->processPlayerVote($game->fresh(), $player, false);
        }
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        $this->assertEquals('debrief', $game->current_phase);
        $this->assertEquals('evil', $game->winner);
    }

    public function test_good_victory_conditions()
    {
        $game = GameSetupService::initializeGame(0);
        
        // Set up 2 successful missions
        $missions = $game->missions()->take(2)->get();
        foreach ($missions as $mission) {
            $mission->update(['status' => 'success']);
        }
        
        $game->update(['current_phase' => 'mission', 'current_mission_id' => $game->missions[2]->id]);
        
        $players = Player::where('game_id', $game->id)->get();
        $goodPlayers = $players->whereIn('role', ['merlin', 'loyal_servant'])->take(2);
        
        $mission = $game->currentMission;
        foreach ($goodPlayers as $player) {
            $mission->teamMembers()->create(['player_id' => $player->id]);
        }

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'Success for the good!',
                                'reasoning' => 'We must succeed',
                                'team_proposal' => '',
                                'vote' => true,
                                'mission_action' => true,
                                'assassination_target' => null
                            ])
                        ]
                    ]
                ]]
            ], 200)
        ]);

        $gameLoop = new GameLoop($game->id);
        
        // Process mission
        $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
        foreach ($eligiblePlayers as $player) {
            if (!$player->is_human) {
                $gameLoop->processAIPlayerTurn($game->fresh(), $player);
            }
        }
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        $this->assertEquals('assassination', $game->current_phase); // Should trigger assassination phase
        
        // Count successful missions
        $successCount = $game->missions()->where('status', 'success')->count();
        $this->assertEquals(3, $successCount);
    }
}