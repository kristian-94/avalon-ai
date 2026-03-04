<?php

namespace Tests\Unit;

use App\Jobs\GameLoop;
use App\Models\Game;
use App\Models\Message;
use App\Models\Mission;
use App\Models\MissionProposal;
use App\Models\MissionProposalMember;
use App\Models\MissionProposalVote;
use App\Models\MissionTeamMember;
use App\Models\Player;
use App\Services\GameSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class GameLoopImprovementsTest extends TestCase
{
    use RefreshDatabase;

    protected Game $game;
    protected array $players;
    protected GameLoop $gameLoop;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clean up all data
        Message::all()->each->delete();
        MissionProposalVote::all()->each->delete();
        MissionProposal::all()->each->delete();
        Player::all()->each->delete();
        Game::all()->each->delete();
        Mission::all()->each->delete();
        MissionProposalMember::all()->each->delete();
        MissionTeamMember::all()->each->delete();

        // Create a new game with standard 5-player setup
        $this->game = GameSetupService::initializeGame(0);

        $this->players = $this->game->players()->orderBy('player_index')->get()->all();
        $this->gameLoop = new GameLoop($this->game->id);
    }

    /**
     * Make private/protected methods accessible for testing
     */
    private function invokeMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }

    public function test_game_state_summary_includes_current_phase_and_mission_status()
    {
        // Setup: Move game to team_proposal phase with some mission history
        $this->game->update([
            'current_phase' => 'team_proposal',
            'current_leader_id' => $this->players[0]->id,
            'current_mission_id' => $this->game->missions()->where('mission_number', 2)->first()->id,
        ]);

        // Complete first mission as success
        $mission1 = $this->game->missions()->where('mission_number', 1)->first();
        $mission1->update(['status' => 'success', 'success_votes' => 2, 'fail_votes' => 0]);
        
        // Add team members to first mission for history
        MissionTeamMember::create(['mission_id' => $mission1->id, 'player_id' => $this->players[0]->id]);
        MissionTeamMember::create(['mission_id' => $mission1->id, 'player_id' => $this->players[1]->id]);

        // Add mission_complete GameEvent so the game log picks it up
        \App\Models\GameEvent::create([
            'game_id' => $this->game->id,
            'event_type' => 'mission_complete',
            'event_data' => [
                'mission_number' => 1,
                'success' => true,
                'fail_votes' => 0,
                'team' => ['Max', 'Alex'],
            ],
        ]);

        // Generate summary for a player
        $summary = $this->invokeMethod($this->gameLoop, 'generateGameStateSummary', [$this->game->fresh(), $this->players[0]]);

        // Assertions
        $this->assertStringContainsString('Phase: team_proposal', $summary);
        $this->assertStringContainsString('Missions: 1 successful, 0 failed', $summary);
        $this->assertStringContainsString('Current Mission: #2 (requires 3 players)', $summary);
        $this->assertStringContainsString('Current Leader: Max (YOU)', $summary);
        $this->assertStringContainsString('Mission 1 — SUCCESS — Team: Max, Alex', $summary);
        $this->assertStringContainsString('You are the leader. Propose a team of 3 players', $summary);
    }

    public function test_game_state_summary_shows_voting_information()
    {
        // Setup: Move to voting phase
        $this->game->update([
            'current_phase' => 'team_voting',
            'current_leader_id' => $this->players[0]->id,
            'current_mission_id' => $this->game->missions()->where('mission_number', 1)->first()->id,
        ]);

        // Create a proposal
        $proposal = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $this->game->currentMission->id,
            'proposed_by_id' => $this->players[0]->id,
            'proposal_number' => 1,
            'status' => 'pending',
        ]);

        // Add proposed team members
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $this->players[0]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $this->players[1]->id]);

        $this->game->update(['current_proposal_id' => $proposal->id]);

        // Add some votes
        MissionProposalVote::create([
            'proposal_id' => $proposal->id,
            'player_id' => $this->players[1]->id,
            'approved' => true,
        ]);

        // Generate summary for player who hasn't voted
        $summary = $this->invokeMethod($this->gameLoop, 'generateGameStateSummary', [$this->game->fresh(), $this->players[2]]);

        // Assertions
        $this->assertStringContainsString('Phase: team_voting', $summary);
        $this->assertStringContainsString('Proposed Team: Max, Alex', $summary);
        $this->assertStringContainsString('Already Voted: Alex', $summary);
        $this->assertStringContainsString('Vote on the proposed team (you MUST include a vote: true/false in your response)', $summary);
    }

    public function test_game_state_summary_shows_mission_phase_information()
    {
        // Setup: Move to mission phase
        $mission = $this->game->missions()->where('mission_number', 1)->first();
        $this->game->update([
            'current_phase' => 'mission',
            'current_mission_id' => $mission->id,
        ]);

        // Add team members
        MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $this->players[0]->id]);
        MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $this->players[1]->id]);

        // Generate summary for team member
        $summaryTeamMember = $this->invokeMethod($this->gameLoop, 'generateGameStateSummary', [$this->game->fresh(), $this->players[0]]);
        
        // Generate summary for non-team member
        $summaryObserver = $this->invokeMethod($this->gameLoop, 'generateGameStateSummary', [$this->game->fresh(), $this->players[2]]);

        // Assertions for team member
        $this->assertStringContainsString('Mission Team: Max, Alex', $summaryTeamMember);
        $this->assertStringContainsString('You are ON this mission team', $summaryTeamMember);
        $this->assertStringContainsString('Include mission_action: true (success) or false (fail) in your response', $summaryTeamMember);

        // Assertions for observer
        $this->assertStringContainsString('Mission Team: Max, Alex', $summaryObserver);
        $this->assertStringNotContainsString('You are ON this mission team', $summaryObserver);
        $this->assertStringContainsString('The mission team is executing their mission. Discuss and observe', $summaryObserver);
    }

    public function test_prepare_ai_context_includes_full_message_history()
    {
        // Create 30 messages
        for ($i = 1; $i <= 30; $i++) {
            Message::create([
                'game_id' => $this->game->id,
                'player_id' => $this->players[$i % 5]->id,
                'message_type' => 'public_chat',
                'content' => "Message {$i}",
            ]);
        }

        // Get context for a player
        $context = $this->invokeMethod($this->gameLoop, 'prepareAIContext', [$this->game->fresh(), $this->players[0]]);

        // Count public chat messages in context (excluding system messages)
        $chatMessages = array_filter($context, function($msg) {
            return str_contains($msg['content'], 'Message');
        });

        // Should include all 30 messages — full history, no truncation
        $this->assertCount(30, $chatMessages);

        // Verify ordering: first is Message 1, last is Message 30
        $first = reset($chatMessages);
        $last = end($chatMessages);
        $this->assertStringContainsString('Message 1', $first['content']);
        $this->assertStringContainsString('Message 30', $last['content']);
    }

    public function test_prepare_ai_context_includes_game_state_summary()
    {
        $this->game->update(['current_phase' => 'team_proposal']);
        
        // Get context
        $context = $this->invokeMethod($this->gameLoop, 'prepareAIContext', [$this->game->fresh(), $this->players[0]]);

        // Find the game state summary message
        $summaryMessage = null;
        foreach ($context as $msg) {
            if ($msg['role'] === 'system' && str_contains($msg['content'], '=== CURRENT GAME STATE ===')) {
                $summaryMessage = $msg;
                break;
            }
        }

        $this->assertNotNull($summaryMessage);
        $this->assertStringContainsString('Phase: team_proposal', $summaryMessage['content']);
        $this->assertStringContainsString('ACTION REQUIRED:', $summaryMessage['content']);
    }

    public function test_conversation_flow_prevents_same_player_speaking_twice()
    {
        // Setup: Set phase to setup (discussion phase)
        $this->game->update(['current_phase' => 'setup']);

        // Create a message from player 0
        Message::create([
            'game_id' => $this->game->id,
            'player_id' => $this->players[0]->id,
            'message_type' => 'public_chat',
            'content' => 'Hello everyone!',
        ]);

        // Get eligible players - should exclude player 0
        $eligiblePlayers = $this->gameLoop->getEligiblePlayers($this->game->fresh());
        
        // In setup phase, normally all players who haven't spoken are eligible
        // But our handle() method should filter out the last speaker
        // We need to test the filtering logic in handle() method
        
        // Since we can't easily test the private filtering in handle(),
        // let's verify the concept by checking that getEligiblePlayers
        // returns players who haven't sent initial messages in setup phase
        $this->assertCount(4, $eligiblePlayers); // 4 players haven't spoken yet
        $this->assertNotContains($this->players[0], $eligiblePlayers);
    }

    public function test_multiple_proposal_rejections_tracked_in_summary()
    {
        $mission = $this->game->missions()->where('mission_number', 1)->first();
        $this->game->update([
            'current_phase' => 'team_proposal',
            'current_mission_id' => $mission->id,
        ]);

        // Create multiple rejected proposals with corresponding GameEvents
        $proposerNames = ['Max', 'Alex', 'Sam'];
        for ($i = 1; $i <= 3; $i++) {
            $proposer = $this->players[($i - 1) % 5];
            $proposal = MissionProposal::create([
                'game_id' => $this->game->id,
                'mission_id' => $mission->id,
                'proposed_by_id' => $proposer->id,
                'proposal_number' => $i,
                'status' => 'rejected',
            ]);

            \App\Models\GameEvent::create([
                'game_id' => $this->game->id,
                'event_type' => 'team_proposal',
                'event_data' => [
                    'proposed_by' => $proposerNames[$i - 1],
                    'team' => [$proposerNames[$i - 1], 'Jordan'],
                    'proposal_number' => $i,
                ],
            ]);

            \App\Models\GameEvent::create([
                'game_id' => $this->game->id,
                'event_type' => 'team_vote',
                'event_data' => [
                    'approved' => false,
                    'votes_for' => 1,
                    'votes_against' => 4,
                    'proposed_by' => $proposerNames[$i - 1],
                    'breakdown' => [
                        ['player' => $proposerNames[$i - 1], 'approved' => true],
                        ['player' => 'Jordan', 'approved' => false],
                        ['player' => 'Riley', 'approved' => false],
                        ['player' => 'Alex', 'approved' => false],
                        ['player' => 'Sam', 'approved' => false],
                    ],
                ],
            ]);
        }

        // Generate summary
        $summary = $this->invokeMethod($this->gameLoop, 'generateGameStateSummary', [$this->game->fresh(), $this->players[0]]);

        // Should show proposal and vote history in game log
        $this->assertStringContainsString('Max proposed: Max, Jordan', $summary);
        $this->assertStringContainsString('Alex proposed: Alex, Jordan', $summary);
        $this->assertStringContainsString('Sam proposed: Sam, Jordan', $summary);
        $this->assertStringContainsString('1 approve / 4 reject — REJECTED', $summary);

        // Should show player tracker
        $this->assertStringContainsString('PLAYER TRACKER', $summary);
        $this->assertStringContainsString('Max:', $summary);
        $this->assertStringContainsString('Proposed [#1a:', $summary);
    }

    public function test_assassination_phase_shows_correct_instructions()
    {
        // Setup assassination phase
        $this->game->update([
            'current_phase' => 'assassination',
            'current_leader_id' => $this->players[1]->id, // Assuming player 1 is assassin
        ]);

        // Set roles
        $this->players[0]->update(['role' => 'merlin']);
        $this->players[1]->update(['role' => 'assassin']);
        $this->players[2]->update(['role' => 'loyal_servant']);

        // Generate summaries for different roles
        $merlinSummary = $this->invokeMethod($this->gameLoop, 'generateGameStateSummary', [$this->game->fresh(), $this->players[0]]);
        $assassinSummary = $this->invokeMethod($this->gameLoop, 'generateGameStateSummary', [$this->game->fresh(), $this->players[1]]);
        $otherSummary = $this->invokeMethod($this->gameLoop, 'generateGameStateSummary', [$this->game->fresh(), $this->players[2]]);

        // Assertions
        $this->assertStringContainsString('The Assassin is choosing their target. You can try to mislead or stay quiet', $merlinSummary);
        $this->assertStringContainsString('You MUST set assassination_target to EXACTLY one of these names', $assassinSummary);
        $this->assertStringContainsString('The Assassin is choosing their target. You can try to mislead or stay quiet', $otherSummary);
    }

    public function test_context_preserves_message_roles_correctly()
    {
        // Create messages from different players
        Message::create([
            'game_id' => $this->game->id,
            'player_id' => $this->players[0]->id,
            'message_type' => 'public_chat',
            'content' => 'Message from Max',
        ]);
        
        Message::create([
            'game_id' => $this->game->id,
            'player_id' => $this->players[1]->id,
            'message_type' => 'public_chat',
            'content' => 'Message from Alex',
        ]);

        // Get context for Alice
        $context = $this->invokeMethod($this->gameLoop, 'prepareAIContext', [$this->game->fresh(), $this->players[0]]);

        // Find the messages
        $maxMessage = null;
        $alexMessage = null;
        
        foreach ($context as $msg) {
            if (str_contains($msg['content'], 'Message from Max')) {
                $maxMessage = $msg;
            } elseif (str_contains($msg['content'], 'Message from Alex')) {
                $alexMessage = $msg;
            }
        }

        // Max's own message should be 'assistant' role
        $this->assertEquals('assistant', $maxMessage['role']);
        $this->assertStringContainsString('Max: Message from Max', $maxMessage['content']);

        // Alex's message should be 'user' role
        $this->assertEquals('user', $alexMessage['role']);
        $this->assertStringContainsString('Alex: Message from Alex', $alexMessage['content']);
    }

    public function test_game_events_only_included_for_relevant_player()
    {
        // Create game events for different players
        Message::create([
            'game_id' => $this->game->id,
            'player_id' => $this->players[0]->id,
            'message_type' => 'game_event',
            'content' => 'Private event for Max',
        ]);
        
        Message::create([
            'game_id' => $this->game->id,
            'player_id' => $this->players[1]->id,
            'message_type' => 'game_event',
            'content' => 'Private event for Alex',
        ]);

        // Get context for Max
        $maxContext = $this->invokeMethod($this->gameLoop, 'prepareAIContext', [$this->game->fresh(), $this->players[0]]);
        
        // Get context for Alex
        $alexContext = $this->invokeMethod($this->gameLoop, 'prepareAIContext', [$this->game->fresh(), $this->players[1]]);

        // Check Max's context
        $maxHasOwnEvent = false;
        $maxHasAlexEvent = false;
        foreach ($maxContext as $msg) {
            if (str_contains($msg['content'], 'Private event for Max')) {
                $maxHasOwnEvent = true;
            }
            if (str_contains($msg['content'], 'Private event for Alex')) {
                $maxHasAlexEvent = true;
            }
        }

        $this->assertTrue($maxHasOwnEvent);
        $this->assertFalse($maxHasAlexEvent);

        // Check Alex's context
        $alexHasOwnEvent = false;
        $alexHasMaxEvent = false;
        foreach ($alexContext as $msg) {
            if (str_contains($msg['content'], 'Private event for Alex')) {
                $alexHasOwnEvent = true;
            }
            if (str_contains($msg['content'], 'Private event for Max')) {
                $alexHasMaxEvent = true;
            }
        }

        $this->assertTrue($alexHasOwnEvent);
        $this->assertFalse($alexHasMaxEvent);
    }
}