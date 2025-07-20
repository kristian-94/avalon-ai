<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\Mission;
use App\Models\MissionProposal;
use App\Models\MissionProposalMember;
use App\Models\MissionTeamMember;
use App\Models\Message;
use App\Jobs\GameLoop;
use App\Services\GameSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GameStateTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_setup_to_team_proposal_transition()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I understand.',
                                'reasoning' => 'Acknowledging',
                                'team_proposal' => 'Max,Riley',
                                'vote' => true,
                                'mission_action' => true,
                                'assassination_target' => null
                            ])
                        ]
                    ]
                ]]
            ], 200)
        ]);
        
        $game = GameSetupService::initializeGame(0);
        $players = Player::where('game_id', $game->id)->get();
        
        $this->assertEquals('setup', $game->current_phase);
        
        // Create initial messages for all players to trigger transition
        foreach ($players as $player) {
            Message::create([
                'game_id' => $game->id,
                'player_id' => $player->id,
                'message_type' => 'public_chat',
                'content' => 'Initial greeting',
            ]);
        }
        
        $gameLoop = new GameLoop($game->id);
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        $this->assertEquals('team_proposal', $game->current_phase);
        $this->assertNotNull($game->current_leader_id);
        $this->assertEquals(1, $game->current_mission_id);
    }

    public function test_team_proposal_to_voting_transition()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I propose a team.',
                                'reasoning' => 'Leading',
                                'team_proposal' => 'Max,Riley',
                                'vote' => true,
                                'mission_action' => true,
                                'assassination_target' => null
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

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I propose this team.',
                                'reasoning' => 'They seem trustworthy',
                                'team_proposal' => $players[0]->name . ',' . $players[1]->name,
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
        
        // Create proposal
        $proposal = MissionProposal::create([
            'game_id' => $game->id,
            'mission_id' => $game->current_mission_id,
            'proposed_by_id' => $game->current_leader_id,
            'proposal_number' => 1,
            'status' => 'pending'
        ]);
        
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $players[0]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $players[1]->id]);
        
        $game->update(['current_proposal_id' => $proposal->id]);
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        $this->assertEquals('team_voting', $game->current_phase);
    }

    public function test_voting_to_mission_transition_on_approval()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I vote yes.',
                                'reasoning' => 'Approving',
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
        
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'team_voting']);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players->first()->id]);
        
        $mission = $game->currentMission;
        
        $proposal = MissionProposal::create([
            'game_id' => $game->id,
            'mission_id' => $mission->id,
            'proposed_by_id' => $game->current_leader_id,
            'proposal_number' => 1,
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
                                'message' => 'I approve this team.',
                                'reasoning' => 'Good choices',
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
        
        // Process votes - majority approves
        $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
        foreach ($eligiblePlayers as $index => $player) {
            $gameLoop->processPlayerVote($game->fresh(), $player, $index < 3); // 3 approve, 1 reject
        }
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        $mission->refresh();
        
        $this->assertEquals('mission', $game->current_phase);
    }

    public function test_voting_back_to_team_proposal_on_rejection()
    {
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'team_voting']);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players[0]->id]);
        
        $mission = $game->currentMission;
        
        // Create first rejected proposal
        $proposal1 = MissionProposal::create([
            'game_id' => $game->id,
            'mission_id' => $mission->id,
            'proposed_by_id' => $players[0]->id,
            'proposal_number' => 1,
            'status' => 'rejected'
        ]);
        
        // Create current proposal
        $proposal = MissionProposal::create([
            'game_id' => $game->id,
            'mission_id' => $mission->id,
            'proposed_by_id' => $players[0]->id,
            'proposal_number' => 2,
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
                                'reasoning' => 'Not trustworthy',
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
        
        // Process votes - majority rejects
        $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
        foreach ($eligiblePlayers as $index => $player) {
            $gameLoop->processPlayerVote($game->fresh(), $player, $index >= 3); // 2 approve, 3 reject
        }
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        
        $this->assertEquals('team_proposal', $game->current_phase);
        $this->assertNotEquals($players[0]->id, $game->current_leader_id);
        $this->assertEquals('rejected', $proposal->fresh()->status);
    }

    public function test_mission_to_next_team_proposal_after_completion()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'Success!',
                                'reasoning' => 'Mission complete',
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
        
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'mission']);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players[0]->id]);
        
        $mission = $game->currentMission;
        
        // Create approved proposal and team members
        $proposal = MissionProposal::create([
            'game_id' => $game->id,
            'mission_id' => $mission->id,
            'proposed_by_id' => $game->current_leader_id,
            'proposal_number' => 1,
            'status' => 'approved'
        ]);
        
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $players[0]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $players[1]->id]);
        
        MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $players[0]->id]);
        MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $players[1]->id]);
        
        $game->update(['current_proposal_id' => $proposal->id]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'Mission success!',
                                'reasoning' => 'For Arthur!',
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
            $teamMember = $mission->teamMembers()->where('player_id', $player->id)->first();
            if ($teamMember) {
                $teamMember->update(['vote_success' => true]);
            }
        }
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        $mission->refresh();
        
        $this->assertEquals('team_proposal', $game->current_phase);
        $this->assertEquals(2, $game->current_mission_id);
        $this->assertEquals('success', $mission->status);
    }

    public function test_mission_phase_transitions_maintain_game_state()
    {
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'mission', 'current_mission_id' => $game->missions[1]->id]);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players[0]->id]);
        
        $mission = $game->currentMission;
        
        // Find specific roles
        $merlin = $players->where('role', 'merlin')->first();
        $loyalServant = $players->where('role', 'loyal_servant')->first();
        $assassin = $players->where('role', 'assassin')->first();
        
        // Create approved proposal and team members
        $proposal = MissionProposal::create([
            'game_id' => $game->id,
            'mission_id' => $mission->id,
            'proposed_by_id' => $game->current_leader_id,
            'proposal_number' => 1,
            'status' => 'approved'
        ]);
        
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $merlin->id]);
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $loyalServant->id]);
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $assassin->id]);
        
        MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $merlin->id]);
        MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $loyalServant->id]);
        MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $assassin->id]);
        
        $game->update(['current_proposal_id' => $proposal->id]);

        // Verify all game state is preserved during transitions
        $originalGameId = $game->id;
        $originalMissionNumber = $mission->mission_number;
        
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'function_call' => [
                                'name' => 'game_response',
                                'arguments' => json_encode([
                                    'message' => 'For Arthur!',
                                    'reasoning' => 'Good must play success',
                                    'team_proposal' => '',
                                    'vote' => true,
                                    'mission_action' => true,
                                    'assassination_target' => null
                                ])
                            ]
                        ]
                    ]]
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'function_call' => [
                                'name' => 'game_response',
                                'arguments' => json_encode([
                                    'message' => 'I serve Arthur.',
                                    'reasoning' => 'Good must play success',
                                    'team_proposal' => '',
                                    'vote' => true,
                                    'mission_action' => true,
                                    'assassination_target' => null
                                ])
                            ]
                        ]
                    ]]
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'function_call' => [
                                'name' => 'game_response',
                                'arguments' => json_encode([
                                    'message' => 'This mission will fail!',
                                    'reasoning' => 'Evil can sabotage',
                                    'team_proposal' => '',
                                    'vote' => true,
                                    'mission_action' => false,
                                    'assassination_target' => null
                                ])
                            ]
                        ]
                    ]]
                ])
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
        $mission->refresh();
        
        // Verify state integrity
        $this->assertEquals($originalGameId, $game->id);
        $this->assertEquals('fail', $mission->status);
        
        // Count failed missions
        $failedMissions = $game->missions()->where('status', 'fail')->count();
        $this->assertGreaterThanOrEqual(1, $failedMissions);
        
        // Verify all players still exist and have their roles
        $this->assertEquals(5, $game->players()->count());
        $this->assertEquals('merlin', $merlin->fresh()->role);
        $this->assertEquals('assassin', $assassin->fresh()->role);
    }
}