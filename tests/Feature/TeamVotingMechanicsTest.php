<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\Mission;
use App\Models\MissionProposal;
use App\Models\MissionProposalMember;
use App\Models\MissionProposalVote;
use App\Jobs\GameLoop;
use App\Services\GameSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamVotingMechanicsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Default HTTP mock for OpenAI API
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I cast my vote.',
                                'reasoning' => 'My decision',
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
    }

    public function test_majority_approval_advances_to_mission()
    {
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'team_voting']);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players[0]->id]);
        
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

        $gameLoop = new GameLoop($game->id);
        
        // Process votes - 3 approve, 1 reject (leader doesn't vote)
        $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
        foreach ($eligiblePlayers as $index => $player) {
            $gameLoop->processPlayerVote($game->fresh(), $player, $index < 3);
        }
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        $proposal->refresh();

        $this->assertEquals('mission', $game->current_phase);
        $this->assertEquals('approved', $proposal->status);
        
        // Verify votes were recorded
        $votes = MissionProposalVote::where('proposal_id', $proposal->id)->get();
        $this->assertEquals(4, $votes->count()); // Leader doesn't vote
        $this->assertEquals(3, $votes->where('approved', true)->count());
        $this->assertEquals(1, $votes->where('approved', false)->count());
    }

    public function test_majority_rejection_returns_to_proposal()
    {
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'team_voting']);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players[0]->id]);
        
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
        
        // Process votes - 1 approve, 3 reject
        $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
        foreach ($eligiblePlayers as $index => $player) {
            $gameLoop->processPlayerVote($game->fresh(), $player, $index < 1);
        }
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        $proposal->refresh();

        $this->assertEquals('team_proposal', $game->current_phase);
        $this->assertEquals('rejected', $proposal->status);
        
        // Leader should have changed
        $this->assertEquals($players[1]->id, $game->current_leader_id);
    }

    public function test_tied_vote_defaults_to_rejection()
    {
        // Create a game with 4 players for tie scenario
        $game = GameSetupService::initializeGame(0);
        
        // Remove one player to have 4 players
        $players = Player::where('game_id', $game->id)->get();
        $players->last()->delete();
        
        $game->update(['current_phase' => 'team_voting']);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players[0]->id]);
        
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

        $gameLoop = new GameLoop($game->id);
        
        // Process votes - 1 approve, 2 reject (tie goes to rejection)
        $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
        $voteCount = 0;
        foreach ($eligiblePlayers as $player) {
            $gameLoop->processPlayerVote($game->fresh(), $player, $voteCount < 1);
            $voteCount++;
        }
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();
        $proposal->refresh();

        $this->assertEquals('team_proposal', $game->current_phase);
        $this->assertEquals('rejected', $proposal->status);
    }

    public function test_fifth_consecutive_rejection_triggers_evil_victory()
    {
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'team_voting']);
        $players = Player::where('game_id', $game->id)->get();
        
        $mission = $game->currentMission;
        
        // Create 4 rejected proposals
        for ($i = 1; $i <= 4; $i++) {
            $proposal = MissionProposal::create([
                'game_id' => $game->id,
                'mission_id' => $mission->id,
                'proposed_by_id' => $players[($i-1) % 5]->id,
                'proposal_number' => $i,
                'status' => 'rejected'
            ]);
        }
        
        // Create 5th proposal
        $game->update(['current_leader_id' => $players[4]->id]);
        
        $proposal = MissionProposal::create([
            'game_id' => $game->id,
            'mission_id' => $mission->id,
            'proposed_by_id' => $game->current_leader_id,
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
                                'message' => 'I reject this proposal.',
                                'reasoning' => '5th rejection',
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
        
        // All eligible players vote reject
        $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
        foreach ($eligiblePlayers as $player) {
            $gameLoop->processPlayerVote($game->fresh(), $player, false);
        }
        
        $gameLoop->checkPhaseTransition($game->fresh());

        $game->refresh();

        $this->assertEquals('debrief', $game->current_phase);
        $this->assertEquals('evil', $game->winner);
    }

    public function test_vote_counting_with_different_team_sizes()
    {
        // Test with mission 2 (3 players) and mission 3 (2 players)
        foreach ([2 => 3, 3 => 2] as $missionNum => $teamSize) {
            $game = GameSetupService::initializeGame(0);
            $game->update([
                'current_phase' => 'team_voting',
                'current_mission_id' => $game->missions->where('mission_number', $missionNum)->first()->id
            ]);
            
            $players = Player::where('game_id', $game->id)->get();
            $game->update(['current_leader_id' => $players[0]->id]);
            
            $mission = $game->currentMission;
            $this->assertEquals($teamSize, $mission->required_players);
            
            $proposal = MissionProposal::create([
                'game_id' => $game->id,
                'mission_id' => $mission->id,
                'proposed_by_id' => $game->current_leader_id,
                'proposal_number' => 1,
                'status' => 'pending'
            ]);
            
            // Add correct number of team members
            for ($i = 0; $i < $teamSize; $i++) {
                MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $players[$i]->id]);
            }
            
            $game->update(['current_proposal_id' => $proposal->id]);

            $gameLoop = new GameLoop($game->id);
            
            // Process votes - 3 approve, 1 reject
            $eligiblePlayers = $gameLoop->getEligiblePlayers($game->fresh());
            foreach ($eligiblePlayers as $index => $player) {
                $gameLoop->processPlayerVote($game->fresh(), $player, $index < 3);
            }
            
            $gameLoop->checkPhaseTransition($game->fresh());

            $game->refresh();
            $proposal->refresh();

            $this->assertEquals('mission', $game->current_phase, "Mission {$missionNum} should advance to mission phase");
            $this->assertEquals('approved', $proposal->status, "Mission {$missionNum} proposal should be approved");
            $this->assertEquals($teamSize, $proposal->teamMembers->count(), "Mission {$missionNum} should have {$teamSize} team members");
            
            // Reset for next iteration
            $game->delete();
        }
    }

    public function test_players_cannot_vote_twice()
    {
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'team_voting']);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players[0]->id]);
        
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

        $gameLoop = new GameLoop($game->id);
        
        // Player 1 votes once
        $gameLoop->processPlayerVote($game->fresh(), $players[1], true);
        
        // Same player tries to vote again
        $gameLoop->processPlayerVote($game->fresh(), $players[1], false);

        // Should only have one vote recorded
        $votes = MissionProposalVote::where('proposal_id', $proposal->id)
                                  ->where('player_id', $players[1]->id)
                                  ->get();
        
        $this->assertEquals(1, $votes->count());
        $this->assertTrue($votes->first()->approved);
    }

    public function test_vote_results_are_immediately_visible()
    {
        $game = GameSetupService::initializeGame(0);
        $game->update(['current_phase' => 'team_voting']);
        $players = Player::where('game_id', $game->id)->get();
        $game->update(['current_leader_id' => $players[0]->id]);
        
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

        $gameLoop = new GameLoop($game->id);
        
        // Cast some votes
        $gameLoop->processPlayerVote($game->fresh(), $players[1], true);
        $gameLoop->processPlayerVote($game->fresh(), $players[2], false);

        // Verify votes are recorded and visible
        $proposal->refresh();
        $votes = $proposal->votes;
        
        $this->assertEquals(2, $votes->count());
        
        $approveVote = $votes->where('approved', true)->first();
        $rejectVote = $votes->where('approved', false)->first();
        
        $this->assertEquals($players[1]->id, $approveVote->player_id);
        $this->assertEquals($players[2]->id, $rejectVote->player_id);
    }
}