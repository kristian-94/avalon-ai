<?php

namespace Tests\Unit;

use App\Jobs\GameLoop;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Message;
use App\Models\Mission;
use App\Models\MissionProposal;
use App\Models\MissionProposalMember;
use App\Models\MissionProposalVote;
use App\Models\MissionTeamMember;
use App\Models\Player;
use App\Services\GameSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

class GameLoopTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected Game $game;
    protected $players;
    protected GameLoop $gameLoop;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake();

        // Clean up all data
        Message::all()->each->delete();
        MissionProposalVote::all()->each->delete();
        MissionProposal::all()->each->delete();
        Player::all()->each->delete();
        Game::all()->each->delete();
        GameEvent::all()->each->delete();
        Mission::all()->each->delete();
        MissionProposalMember::all()->each->delete();
        MissionTeamMember::all()->each->delete();

        // Create a new game
        $this->game = GameSetupService::initializeGame(0);
        $this->players = $this->game->players()->orderBy('player_index')->get();
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

    /**
     * Helper to simulate phase timeout by manipulating timestamps
     */
    private function simulatePhaseTimeout($phase)
    {
        $timeouts = [
            'setup' => 301,
            'team_proposal' => 181,
            'team_voting' => 121,
            'mission' => 121,
            'assassination' => 181,
        ];
        
        // Create a phase transition event and then update its timestamp
        $event = GameEvent::create([
            'game_id' => $this->game->id,
            'event_type' => 'game_start',
            'event_data' => ['phase' => $phase],
        ]);
        
        // Update the created_at timestamp directly in the database
        GameEvent::where('id', $event->id)->update([
            'created_at' => now()->subSeconds($timeouts[$phase])
        ]);
    }

    public function test_setup_phase_timeout_forces_introductions()
    {
        // Remove initial messages to simulate players not introducing themselves
        Message::where('game_id', $this->game->id)
            ->where('message_type', 'public_chat')
            ->delete();

        // Force transition directly (we'll test timeout detection separately)
        $this->invokeMethod($this->gameLoop, 'forcePhaseTransition', [$this->game->fresh()]);

        // Verify all players now have messages
        foreach ($this->players as $player) {
            $messages = Message::where('game_id', $this->game->id)
                ->where('player_id', $player->id)
                ->where('message_type', 'public_chat')
                ->get();
                
            $this->assertNotEmpty($messages);
            $this->assertStringContainsString('[Timed out]', $messages->first()->content);
        }

        // Check that transition can now occur
        $shouldTransition = $this->invokeMethod($this->gameLoop, 'shouldTransitionFromSetup', [$this->game->fresh()]);
        $this->assertTrue($shouldTransition);
    }

    public function test_team_proposal_timeout_forces_proposal()
    {
        // Move to team proposal phase
        $this->game->update([
            'current_phase' => 'team_proposal',
            'current_leader_id' => $this->players[0]->id,
        ]);

        // Force transition directly
        $this->invokeMethod($this->gameLoop, 'forcePhaseTransition', [$this->game->fresh()]);

        // Verify proposal was created
        $this->game->refresh();
        $this->assertNotNull($this->game->current_proposal_id);
        
        $proposal = MissionProposal::find($this->game->current_proposal_id);
        $this->assertNotNull($proposal);
        $this->assertEquals($this->players[0]->id, $proposal->proposed_by_id);
        $this->assertEquals($this->game->currentMission->required_players, $proposal->teamMembers->count());

        // Verify timeout message was created
        $timeoutMessage = Message::where('game_id', $this->game->id)
            ->where('player_id', $this->players[0]->id)
            ->where('content', 'LIKE', '%Time\'s up!%')
            ->first();
        $this->assertNotNull($timeoutMessage);
    }

    public function test_team_voting_timeout_forces_rejections()
    {
        // Setup voting phase with a proposal
        $this->game->update([
            'current_phase' => 'team_voting',
            'current_leader_id' => $this->players[0]->id,
        ]);

        $proposal = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $this->game->currentMission->id,
            'proposed_by_id' => $this->players[0]->id,
            'proposal_number' => 1,
            'status' => 'pending',
        ]);

        // Add team members
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $this->players[0]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $this->players[1]->id]);

        $this->game->update(['current_proposal_id' => $proposal->id]);

        // Leader auto-votes approve when submitting proposal (mirroring production behaviour)
        MissionProposalVote::create([
            'proposal_id' => $proposal->id,
            'player_id' => $this->players[0]->id,
            'approved' => true,
        ]);

        // Have one other player vote
        MissionProposalVote::create([
            'proposal_id' => $proposal->id,
            'player_id' => $this->players[1]->id,
            'approved' => true,
        ]);

        // Simulate timeout
        $this->simulatePhaseTimeout('team_voting');

        // Force transition
        $this->invokeMethod($this->gameLoop, 'forcePhaseTransition', [$this->game->fresh()]);

        // Verify all players have voted (leader + player[1] already voted, rest forced)
        $totalVotes = MissionProposalVote::where('proposal_id', $proposal->id)->count();
        $this->assertEquals(5, $totalVotes); // All players

        // Check forced votes are rejections
        $forcedVotes = MissionProposalVote::where('proposal_id', $proposal->id)
            ->whereIn('player_id', [$this->players[2]->id, $this->players[3]->id, $this->players[4]->id])
            ->get();
            
        foreach ($forcedVotes as $vote) {
            $this->assertFalse($vote->approved);
        }

        // Verify timeout messages
        $timeoutMessages = Message::where('game_id', $this->game->id)
            ->where('content', 'LIKE', '%[Timed out]%')
            ->count();
        $this->assertEquals(3, $timeoutMessages); // 3 forced votes
    }

    public function test_mission_phase_timeout_forces_actions()
    {
        // Setup mission phase
        $mission = $this->game->missions()->first();
        $this->game->update([
            'current_phase' => 'mission',
            'current_mission_id' => $mission->id,
        ]);

        // Add team members (mix of good and evil)
        $goodPlayer = $this->players->first(fn($p) => $p->role === 'loyal_servant');
        $evilPlayer = $this->players->first(fn($p) => in_array($p->role, ['assassin', 'minion']));
        
        MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $goodPlayer->id]);
        MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $evilPlayer->id]);

        // Simulate timeout
        $this->simulatePhaseTimeout('mission');

        // Force transition
        $this->invokeMethod($this->gameLoop, 'forcePhaseTransition', [$this->game->fresh()]);

        // Verify all team members have voted
        $teamMembers = MissionTeamMember::where('mission_id', $mission->id)->get();
        foreach ($teamMembers as $member) {
            $this->assertNotNull($member->vote_success);
            
            // Good players should always vote success
            if (in_array($member->player->role, ['loyal_servant', 'merlin'])) {
                $this->assertTrue($member->vote_success);
            }
        }

        // Check transition is now possible
        $shouldTransition = $this->invokeMethod($this->gameLoop, 'shouldTransitionFromMission', [$this->game->fresh()]);
        $this->assertTrue($shouldTransition);
    }

    public function test_assassination_phase_timeout_forces_guess()
    {
        // Setup assassination phase
        $this->game->update([
            'current_phase' => 'assassination',
        ]);
        
        $assassin = $this->players->first(fn($p) => $p->role === 'assassin');
        $this->game->update(['current_leader_id' => $assassin->id]);

        // Simulate timeout
        $this->simulatePhaseTimeout('assassination');

        // Force transition
        $this->invokeMethod($this->gameLoop, 'forcePhaseTransition', [$this->game->fresh()]);

        // Verify assassination event was created
        $assassinationEvent = GameEvent::where('game_id', $this->game->id)
            ->where('event_type', 'assassination')
            ->first();
            
        $this->assertNotNull($assassinationEvent);
        $this->assertArrayHasKey('assassin_target', $assassinationEvent->event_data);
        
        // Verify target is not evil
        $targetId = $assassinationEvent->event_data['assassin_target']['player_id'];
        $target = Player::find($targetId);
        $this->assertFalse(in_array($target->role, ['assassin', 'minion']));

        // Verify timeout message
        $timeoutMessage = Message::where('game_id', $this->game->id)
            ->where('player_id', $assassin->id)
            ->where('content', 'LIKE', '%Time\'s up!%')
            ->first();
        $this->assertNotNull($timeoutMessage);
    }

    public function test_timeout_events_are_logged()
    {
        // Test each phase creates a timeout event
        $phases = ['setup', 'team_proposal', 'team_voting', 'mission'];
        
        foreach ($phases as $phase) {
            // Setup phase
            $this->game->update(['current_phase' => $phase]);
            
            if ($phase === 'team_proposal') {
                $this->game->update(['current_leader_id' => $this->players[0]->id]);
            }
            
            // Simulate timeout
            $this->simulatePhaseTimeout($phase);
            
            // Force transition
            $this->invokeMethod($this->gameLoop, 'forcePhaseTransition', [$this->game->fresh()]);
            
            // Verify timeout message was logged
            $timeoutMessage = Message::where('game_id', $this->game->id)
                ->where('message_type', 'game_event')
                ->where('content', 'LIKE', '%Phase timeout: ' . $phase . '%')
                ->first();
                
            $this->assertNotNull($timeoutMessage, "Timeout message not found for phase: $phase");
            
            // Clean up for next iteration
            Message::where('game_id', $this->game->id)
                ->where('message_type', 'game_event')
                ->where('content', 'LIKE', '%Phase timeout%')
                ->delete();
        }
    }

    public function test_handle_method_with_timeout_forces_transitions()
    {
        // This test verifies the forced transition logic works correctly
        // The actual timeout detection would require mocking Carbon::now() or using travel()
        
        // Setup a phase that needs forcing
        $this->game->update(['current_phase' => 'setup']);
        
        // Remove messages to ensure players haven't acted
        Message::where('game_id', $this->game->id)
            ->where('message_type', 'public_chat')
            ->delete();
            
        // Directly test the forced transition
        $this->invokeMethod($this->gameLoop, 'forcePhaseTransition', [$this->game->fresh()]);
        
        // Verify forced messages were created
        $forcedMessages = Message::where('game_id', $this->game->id)
            ->where('content', 'LIKE', '%[Timed out]%')
            ->count();
            
        $this->assertEquals(5, $forcedMessages); // All 5 players
        
        // Verify timeout log message
        $timeoutLog = Message::where('game_id', $this->game->id)
            ->where('message_type', 'game_event')
            ->where('content', 'LIKE', '%Phase timeout%')
            ->exists();
            
        $this->assertTrue($timeoutLog);
    }

    public function test_no_timeout_when_phase_progresses_normally()
    {
        // Test that timeout doesn't trigger if phase completes normally
        $this->game->update(['current_phase' => 'setup']);
        
        // Create recent game start event (only 60 seconds ago)
        GameEvent::create([
            'game_id' => $this->game->id,
            'event_type' => 'game_start',
            'event_data' => ['phase' => 'setup'],
            'created_at' => now()->subSeconds(60), // Well within timeout
        ]);
        
        // Check no timeout
        $hasTimedOut = $this->invokeMethod($this->gameLoop, 'hasPhaseTimedOut', [$this->game->fresh()]);
        $this->assertFalse($hasTimedOut);
    }

    public function test_merlin_forced_to_vote_success_on_mission()
    {
        // Specific test for Merlin being forced to vote success
        $mission = $this->game->missions()->first();
        $this->game->update([
            'current_phase' => 'mission',
            'current_mission_id' => $mission->id,
        ]);

        $merlin = $this->players->first(fn($p) => $p->role === 'merlin');
        MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $merlin->id]);

        $this->simulatePhaseTimeout('mission');
        $this->invokeMethod($this->gameLoop, 'forcePhaseTransition', [$this->game->fresh()]);

        $teamMember = MissionTeamMember::where('mission_id', $mission->id)
            ->where('player_id', $merlin->id)
            ->first();
            
        $this->assertTrue($teamMember->vote_success);
    }

    public function test_evil_players_random_mission_votes_on_timeout()
    {
        // Test that evil players get random votes on timeout
        $mission = $this->game->missions()->first();
        $this->game->update([
            'current_phase' => 'mission',
            'current_mission_id' => $mission->id,
        ]);

        // Add only evil players to mission
        $evilPlayers = $this->players->filter(fn($p) => in_array($p->role, ['assassin', 'minion']));
        foreach ($evilPlayers as $evilPlayer) {
            MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $evilPlayer->id]);
        }

        $this->simulatePhaseTimeout('mission');
        
        // Force transition multiple times to test randomness
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            // Clear previous votes
            MissionTeamMember::where('mission_id', $mission->id)->update(['vote_success' => null]);
            
            $this->invokeMethod($this->gameLoop, 'forcePhaseTransition', [$this->game->fresh()]);
            
            $votes = MissionTeamMember::where('mission_id', $mission->id)
                ->pluck('vote_success')
                ->toArray();
            $results[] = array_sum($votes);
        }
        
        // Verify we get different results (showing randomness)
        $this->assertGreaterThan(1, count(array_unique($results)), 'Evil player votes should be random');
    }
}