<?php

namespace Tests\Unit;

use App\Facades\Agent;
use App\Jobs\GameLoop;
use App\Models\GameEvent;
use App\Models\Mission;
use App\Models\MissionProposalMember;
use App\Models\MissionTeamMember;
use App\Services\GameSetupService;
use App\Services\BasicAgentService;
use Tests\TestCase;
use App\Models\Game;
use App\Models\Player;
use App\Models\Message;
use App\Models\MissionProposal;
use App\Models\MissionProposalVote;
use App\Events\GameStateUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class GameLoopTest extends TestCase
{
    use RefreshDatabase;

    protected Game $game;
    protected array $players;
    protected GameLoop $gameLoop;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([GameStateUpdate::class]);

        Message::all()->each->delete();
        MissionProposalVote::all()->each->delete();
        MissionProposal::all()->each->delete();
        Player::all()->each->delete();
        Game::all()->each->delete();
        GameEvent::all()->each->delete();
        Mission::all()->each->delete();
        MissionProposalMember::all()->each->delete();
        MissionTeamMember::all()->each->delete();

        $this->game = GameSetupService::initializeGame(0);
        $this->players = Player::where('game_id', $this->game->id)->get()->all();
        $this->assertCount(5, Message::all());
        $this->assertCount(5, Player::all());
        $this->assertCount(1, GameEvent::all());
        $this->assertCount(5, Mission::all());
        $this->assertCount(0, MissionProposalVote::all());
        $this->assertCount(0, MissionProposal::all());
        $this->assertCount(0, MissionProposalMember::all());
        $this->assertCount(0, MissionTeamMember::all());
        $this->assertCount(5, $this->players);
        $this->assertSame(null, $this->game->current_leader_id);
        $this->assertNull($this->game->currentLeader);

        $this->gameLoop = new GameLoop($this->game->id);
    }

    public function test_phase_transition_from_setup_to_team_proposal()
    {
        $this->assertFalse($this->gameLoop->shouldTransitionFromSetup($this->game));

        // Create initial messages for all players
        foreach ($this->players as $player) {
            Message::create([
                'game_id' => $this->game->id,
                'player_id' => $player->id,
                'message_type' => 'public_chat',
                'content' => 'Initial greeting'
            ]);
        }

        $this->assertTrue($this->gameLoop->shouldTransitionFromSetup($this->game));
        $this->gameLoop->checkPhaseTransition($this->game->fresh());

        $this->game->refresh();
        $this->assertEquals('team_proposal', $this->game->current_phase);

        // Verify transition messages were created for all players
        foreach ($this->players as $player) {
            $this->assertDatabaseHas('messages', [
                'game_id' => $this->game->id,
                'player_id' => $player->id,
                'message_type' => 'game_event',
                'content' => 'Initial introductions are complete. The game is now moving to the team proposal phase.'
            ]);

            // Verify each player got appropriate instructions
            $isLeader = $this->game->current_leader_id === $player->id;
            if ($isLeader) {
                $this->assertDatabaseHas('messages', [
                    'game_id' => $this->game->id,
                    'player_id' => $player->id,
                    'message_type' => 'game_event',
                    'content' => "You are the leader for this round. You need to propose a team of " .
                        $this->game->currentMission->required_players . " players for the mission. Who do you trust?"
                ]);
            }
        }

        Event::assertDispatched(GameStateUpdate::class);
    }

    public function test_phase_transition_from_team_proposal_to_team_voting()
    {
        $this->game->update([
            'current_phase' => 'team_proposal',
            'current_leader_id' => $this->players[0]->id
        ]);

        $proposal = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $this->game->current_mission_id,
            'proposed_by_id' => $this->game->current_leader_id,
            'proposal_number' => 1,
            'status' => 'pending'
        ]);

        $this->game->update(['current_proposal_id' => $proposal->id]);

        $this->gameLoop->checkPhaseTransition($this->game->fresh());

        $this->game->refresh();
        $this->assertEquals('team_voting', $this->game->current_phase);

        // Verify transition messages
        foreach ($this->players as $player) {
            $this->assertDatabaseHas('messages', [
                'game_id' => $this->game->id,
                'player_id' => $player->id,
                'message_type' => 'game_event',
                'content' => 'The team has been proposed and now needs to be voted on.'
            ]);
        }

        Event::assertDispatched(GameStateUpdate::class);
    }

    public function test_phase_transition_from_team_voting_to_mission_on_approval()
    {
        $this->game->update([
            'current_phase' => 'team_voting',
            'current_leader_id' => $this->players[0]->id,
        ]);
        $oldLeaderId = $this->game->current_leader_id;
        $this->assertEquals(1, $this->game->current_mission_id);

        $proposal = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $this->game->current_mission_id,
            'proposed_by_id' => $this->game->current_leader_id,
            'proposal_number' => 1,
            'status' => 'pending'
        ]);

        $this->game->update(['current_proposal_id' => $proposal->id]);

        $this->assertCount(0, MissionProposalVote::all());
        // Create approval votes from all eligible players
        $eligiblePlayers = $this->gameLoop->getEligiblePlayers($this->game->fresh());
        $this->assertCount(4, $eligiblePlayers);
        foreach ($eligiblePlayers as $player) {
            // Each player votes privately.
            $this->gameLoop->processPlayerVote($this->game->fresh(), $player, true);
        }
        $this->assertCount(4, MissionProposalVote::all(), 'The team leader should not vote.');

        $this->assertEquals('pending', $proposal->fresh()->status);
        $this->game->refresh();
        $this->assertEquals('team_voting', $this->game->current_phase);
        $this->gameLoop->checkPhaseTransition($this->game->fresh());
        $this->game->refresh();
        $this->assertEquals('mission', $this->game->current_phase);
        $this->assertEquals(1, $this->game->current_mission_id, 'we are now doing the actual mission, finished voting');
        $this->assertEquals($oldLeaderId, $this->game->currentLeader->id);
        $this->assertEquals('approved', $proposal->fresh()->status);

        // Verify mission team was created
        foreach ($proposal->teamMembers as $teamMember) {
            $this->assertDatabaseHas('mission_team_members', [
                'mission_id' => $this->game->current_mission_id,
                'player_id' => $teamMember->player_id
            ]);
        }

        Event::assertDispatched(GameStateUpdate::class);
    }

    public function test_phase_transition_from_team_voting_to_team_proposal_and_next_leader_on_disapproval()
    {
        $this->game->update([
            'current_phase' => 'team_voting',
            'current_leader_id' => $this->players[0]->id,
        ]);
        $oldLeaderId = $this->game->current_leader_id;
        $this->assertEquals(1, $this->game->current_mission_id);

        $proposal = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $this->game->current_mission_id,
            'proposed_by_id' => $this->game->current_leader_id,
            'proposal_number' => 1,
            'status' => 'pending'
        ]);

        $this->game->update(['current_proposal_id' => $proposal->id]);
        $this->assertCount(1, MissionProposal::all());

        $this->assertCount(0, MissionProposalVote::all());
        // Create approval votes from all eligible players
        $eligiblePlayers = $this->gameLoop->getEligiblePlayers($this->game->fresh());
        $this->assertCount(4, $eligiblePlayers);
        foreach ($eligiblePlayers as $index => $player) {
            // Each player votes privately, this time the vote is split, fails.
            $this->gameLoop->processPlayerVote($this->game->fresh(), $player, $index < 2);
        }
        $this->assertCount(4, MissionProposalVote::all(), 'The team leader should not vote.');

        $this->assertEquals(2, MissionProposalVote::where('approved', true)->count());
        $this->assertEquals(2, MissionProposalVote::where('approved', false)->count());

        $this->assertEquals('pending', $proposal->fresh()->status);
        $this->game->refresh();
        $this->assertEquals('team_voting', $this->game->current_phase);

        // Setup complete.
        $this->gameLoop->checkPhaseTransition($this->game->fresh());
        $this->game->refresh();
        $this->assertEquals('team_proposal', $this->game->current_phase);
        $this->assertEquals(1, $this->game->current_mission_id, 'Still on mission one.');
        $this->assertNotEquals($oldLeaderId, $this->game->currentLeader->id);
        $this->assertEquals('rejected', $proposal->fresh()->status);
        $this->assertEquals(1, $proposal->proposal_number, 'That was the first proposal.');

        $this->assertCount(1, MissionProposal::all(), 'need the next leader to propose next team');

        // Verify mission team was created
        foreach ($proposal->teamMembers as $teamMember) {
            $this->assertDatabaseHas('mission_team_members', [
                'mission_id' => $this->game->current_mission_id,
                'player_id' => $teamMember->player_id
            ]);
        }

        Event::assertDispatched(GameStateUpdate::class);
    }

    public function test_phase_transition_from_mission_to_team_proposal_mission_success()
    {
        $this->game->update([
            'current_phase' => 'mission',
            'current_leader_id' => $this->players[0]->id,
        ]);
        $oldLeaderId = $this->game->current_leader_id;
        $this->assertEquals(1, $this->game->current_mission_id);

        $mission = $this->game->missions()->where('id', $this->game->current_mission_id)->first();

        $proposal = MissionProposal::create(['game_id' => $this->game->id, 'mission_id' => $mission->id, 'proposed_by_id' => $this->game->current_leader_id, 'proposal_number' => 1, 'status' => 'pending']);

        $proposalMember1 = MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $this->players[0]->id]);
        $proposalMember2 = MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $this->players[1]->id]);
        $this->assertCount(2, $proposal->teamMembers);

        $this->game->update(['current_proposal_id' => $proposal->id]);
        $this->assertCount(0, MissionProposalVote::all());

        $this->assertNotNull($this->players[0]->id);
        $this->assertNotNull($this->players[1]->id);
        $this->assertNotNull($this->players[2]->id);
        $this->assertNotNull($this->players[3]->id);
        $this->assertNotNull($this->players[4]->id);

        // Set up 3 successful votes, 1 fail vote
        $this->gameLoop->processPlayerVote($this->game->fresh(), $this->players[0], true);
        $this->gameLoop->processPlayerVote($this->game->fresh(), $this->players[1], true);
        $this->gameLoop->processPlayerVote($this->game->fresh(), $this->players[2], true);
        $this->gameLoop->processPlayerVote($this->game->fresh(), $this->players[3], false);

        $missionTeamMember1 = MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $this->players[0]->id]);
        $missionTeamMember2 = MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $this->players[1]->id]);

        // Do the equivalent of get eligible players and processAIPlayerTurn
        $eligiblePlayers = $this->gameLoop->getEligiblePlayers($this->game->fresh());
        $this->assertCount(2, $eligiblePlayers, 'Only two players are on the mission team.');
        foreach ($eligiblePlayers as $index => $player) {
            // Each player votes privately.
            $teamMember = $this->game->currentMission->teamMembers()->where('player_id', $player->id)->first();
            if ($teamMember) {
                $teamMember->update(['vote_success' => true]);
            }
        }

        // Setup complete. Do what the game loop would do.
        $this->gameLoop->checkPhaseTransition($this->game->fresh());
        // Verify transition

        $this->game->refresh();
        $this->assertEquals('team_proposal', $this->game->current_phase);
        $this->assertNotNull($this->game->current_leader_id);
        $this->assertNotEquals($oldLeaderId, $this->game->current_leader_id);

        // Verify mission success messages
        foreach ($this->players as $player) {
            $this->assertDatabaseHas('messages', [
                'game_id' => $this->game->id,
                'player_id' => $player->id,
                'message_type' => 'game_event',
                'content' => "Mission 1 succeeded"
            ]);
        }

        Event::assertDispatched(GameStateUpdate::class);
    }

    public function test_game_ends_when_evil_wins_three_missions()
    {
        // Start with 2 failed missions. Then set up the 3rd mission to fail.
        for ($i = 1; $i <= 2; $i++) {
            $mission = $this->game->missions()->where('mission_number', $i)->first();
            $mission->update(['status' => 'fail']);
        }

        $mission = $this->game->missions()->where('mission_number', 3)->first();

        $this->game->update([
            'current_phase' => 'mission',
            'current_leader_id' => $this->players[0]->id,
            'current_mission_id' => $mission->id
        ]);
        $oldLeaderId = $this->game->current_leader_id;

        $proposal = MissionProposal::create(['game_id' => $this->game->id, 'mission_id' => $mission->id, 'proposed_by_id' => $this->game->current_leader_id, 'proposal_number' => 1, 'status' => 'pending']);

        $proposalMember1 = MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $this->players[0]->id]);
        $proposalMember2 = MissionProposalMember::create(['proposal_id' => $proposal->id, 'player_id' => $this->players[1]->id]);
        $this->assertCount(2, $proposal->teamMembers);

        $this->game->update(['current_proposal_id' => $proposal->id]);
        $this->assertCount(0, MissionProposalVote::all());

        $this->assertNotNull($this->players[0]->id);
        $this->assertNotNull($this->players[1]->id);
        $this->assertNotNull($this->players[2]->id);
        $this->assertNotNull($this->players[3]->id);
        $this->assertNotNull($this->players[4]->id);

        // Set up 3 successful votes, 1 fail vote
        $this->gameLoop->processPlayerVote($this->game->fresh(), $this->players[0], true);
        $this->gameLoop->processPlayerVote($this->game->fresh(), $this->players[1], true);
        $this->gameLoop->processPlayerVote($this->game->fresh(), $this->players[2], true);
        $this->gameLoop->processPlayerVote($this->game->fresh(), $this->players[3], false);

        $missionTeamMember1 = MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $this->players[0]->id]);
        $missionTeamMember2 = MissionTeamMember::create(['mission_id' => $mission->id, 'player_id' => $this->players[1]->id]);

        // Do the equivalent of get eligible players and processAIPlayerTurn
        $eligiblePlayers = $this->gameLoop->getEligiblePlayers($this->game->fresh());
        $this->assertCount(2, $eligiblePlayers, 'Only two players are on the mission team.');
        foreach ($eligiblePlayers as $index => $player) {
            // Each player votes privately.
            $teamMember = $this->game->currentMission->teamMembers()->where('player_id', $player->id)->first();
            $teamMember->update(['vote_success' => $index === 0]); // First player votes success, second player votes fail.
        }

        // Setup complete.

        // Process the last mission result
        $this->gameLoop->checkPhaseTransition($this->game->fresh());

        $this->game->refresh();
        $this->assertEquals('evil', $this->game->winner);
        $this->assertEquals($oldLeaderId, $this->game->current_leader_id, 'Leader should not change after game ends.');

        // Verify game end messages
        foreach ($this->players as $player) {
            $playerMessages = $player->messages()->pluck('content')->toArray();
            $lastMessage = end($playerMessages);

            $string = '';
            if ($player->role === 'merlin') {
                $string = 'You are Merlin and the evil team has won. You were unable to identify all the evil players. ';
            }

            // Everyone should see the game end message with all roles.
            $this->assertEquals($string . "The game has ended. The minions of Mordred have won. Max was merlin. Alex was an assassin. Sam was a loyal_servant. Jordan was a loyal_servant. Riley was a minion.", $lastMessage);
        }

        Event::assertDispatched(GameStateUpdate::class);
    }

    public function test_evil_wins_when_assassin_correctly_identifies_merlin()
    {
        // Set the game state to assassination phase
        $this->game->update([
            'current_phase' => 'assassination',
            'current_leader_id' => $this->players[1]->id // Assuming player 1 is assassin
        ]);

        // Find Merlin and Assassin
        $merlin = $this->game->players()->firstWhere('role', 'merlin');
        $assassin = $this->game->players()->firstWhere('role', 'assassin');

        $eligiblePlayers = $this->gameLoop->getEligiblePlayers($this->game);
        $this->assertCount(1, $eligiblePlayers); // Only the assassin is eligible to
        foreach ($eligiblePlayers as $player) {
            if (!$player->is_human) {
                $this->gameLoop->processAIPlayerTurn($this->game, $player);
            }
        }
        $this->game->refresh();
        $this->assertEquals(null, $this->game->winner);
        $this->gameLoop->checkPhaseTransition($this->game);

        // Verify game state
        $this->game->refresh();
        $this->assertEquals('evil', $this->game->winner);
        $this->assertEquals('assassination', $this->game->current_phase);

        $assassination_event = $this->game->gameEvents()->where('event_type', 'assassination')->first();
        $assassin_target = $assassination_event->event_data['assassin_target']['player_id'];
        $this->assertEquals($merlin->id, $assassin_target);

        // Verify end game messages
        foreach ($this->players as $player) {
            $playerMessages = $player->messages()->pluck('content')->toArray();
            $lastMessage = array_pop($playerMessages);
            $secondToLastMessage = array_pop($playerMessages);

            $string = '';
            if ($player->role === 'merlin') {
                $string = 'You are Merlin and were too obvious. The evil team has won by killing you. ';
            }

            // Everyone should see the game end message with all roles.
            $this->assertEquals($string . "The game has ended. The minions of Mordred have won. The Assassin was able to identify Merlin. Max was merlin. Alex was an assassin. Sam was a loyal_servant. Jordan was a loyal_servant. Riley was a minion.", $lastMessage, 'wrong message for role ' . $player->role);
        }

        Event::assertDispatched(GameStateUpdate::class);
    }

    public function test_good_wins_when_assassin_fails_to_identify_merlin()
    {
        // Set the game state to assassination phase
        $this->game->update([
            'current_phase' => 'assassination',
            'current_leader_id' => $this->players[1]->id // Assuming player 1 is assassin
        ]);

        $loyalServant = $this->game->players()->firstWhere('role', 'loyal_servant');

        // Mock the Agent facade
        Agent::shouldReceive('getChatResponse')
            ->once()
            ->andReturn([
                'message' => "I think Sam is Merlin",
                'reasoning' => 'Test reasoning',
                'team_proposal' => '',
                'vote' => true,
                'mission_action' => true,
                'assassination_target' => $loyalServant->name
            ]);

        // Find Merlin and Assassin
        $merlin = $this->game->players()->firstWhere('role', 'merlin');
        $assassin = $this->game->players()->firstWhere('role', 'assassin');

        $eligiblePlayers = $this->gameLoop->getEligiblePlayers($this->game);
        $this->assertCount(1, $eligiblePlayers); // Only the assassin is eligible to
        foreach ($eligiblePlayers as $player) {
            if (!$player->is_human) {
                $this->gameLoop->processAIPlayerTurn($this->game, $player);
            }
        }
        $this->game->refresh();
        $this->assertEquals(null, $this->game->winner);
        $this->gameLoop->checkPhaseTransition($this->game);

        // Verify game state
        $this->game->refresh();
        $this->assertEquals('good', $this->game->winner);
        $this->assertEquals('assassination', $this->game->current_phase);

        // Verify assassination event targeted wrong player
        $assassination_event = $this->game->gameEvents()->where('event_type', 'assassination')->first();
        $assassin_target = $assassination_event->event_data['assassin_target']['player_id'];
        $this->assertNotEquals($merlin->id, $assassin_target);
        $this->assertNotNull($assassin_target);

        // Verify end game messages
        foreach ($this->players as $player) {
            $playerMessages = $player->messages()->pluck('content')->toArray();
            $lastMessage = array_pop($playerMessages);
            $secondToLastMessage = array_pop($playerMessages);

            $string = '';
            if ($player->role === 'merlin') {
                $string = 'You are Merlin and the good team has won. You were able to identify all the evil players. ';
            }

            // Everyone should see the game end message with all roles
            $this->assertEquals($string . "The game has ended. The loyal servants have won. The Assassin was unable to identify Merlin. Max was merlin. Alex was an assassin. Sam was a loyal_servant. Jordan was a loyal_servant. Riley was a minion.", $lastMessage, 'wrong message for role ' . $player->role);
        }

        Event::assertDispatched(GameStateUpdate::class);
    }

    public function test_assassination_phase_begins_after_good_wins_three_missions()
    {
        // Helper function to identify evil players (assassin and minion)
        $isEvil = fn($player) => in_array($player->role, ['assassin', 'minion']);

        // Set up first successful mission
        $mission1 = $this->game->missions()->where('mission_number', 1)->first();
        $proposal1 = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $mission1->id,
            'proposed_by_id' => $this->players[0]->id,
            'proposal_number' => 1,
            'status' => 'pending'
        ]);

        // Create team members for first mission
        MissionProposalMember::create(['proposal_id' => $proposal1->id, 'player_id' => $this->players[0]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal1->id, 'player_id' => $this->players[1]->id]);

        $this->game->update(['current_proposal_id' => $proposal1->id]);

        // Process votes - evil players vote approve strategically
        foreach ($this->players as $player) {
            $this->gameLoop->processPlayerVote($this->game->fresh(), $player, true);
        }

        // Create mission team members and their votes (all success)
        MissionTeamMember::create(['mission_id' => $mission1->id, 'player_id' => $this->players[0]->id, 'vote_success' => true]);
        MissionTeamMember::create(['mission_id' => $mission1->id, 'player_id' => $this->players[1]->id, 'vote_success' => true]);
        $mission1->update(['status' => 'success']);

        // Set up second failed mission
        $mission2 = $this->game->missions()->where('mission_number', 2)->first();
        $proposal2 = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $mission2->id,
            'proposed_by_id' => $this->players[1]->id,
            'proposal_number' => 1,
            'status' => 'pending'
        ]);

        // Create team members for second mission (requires 3 players)
        MissionProposalMember::create(['proposal_id' => $proposal2->id, 'player_id' => $this->players[1]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal2->id, 'player_id' => $this->players[2]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal2->id, 'player_id' => $this->players[4]->id]); // Include minion

        $this->game->update(['current_proposal_id' => $proposal2->id]);

        // Process votes
        foreach ($this->players as $player) {
            $this->gameLoop->processPlayerVote($this->game->fresh(), $player, !$isEvil($player)); // Evil players reject
        }

        // Create mission team members and their votes (one evil player fails it)
        MissionTeamMember::create(['mission_id' => $mission2->id, 'player_id' => $this->players[1]->id, 'vote_success' => true]);
        MissionTeamMember::create(['mission_id' => $mission2->id, 'player_id' => $this->players[2]->id, 'vote_success' => true]);
        MissionTeamMember::create(['mission_id' => $mission2->id, 'player_id' => $this->players[4]->id, 'vote_success' => false]);
        $mission2->update(['status' => 'fail']);

        // Set up third successful mission
        $mission3 = $this->game->missions()->where('mission_number', 3)->first();
        $proposal3 = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $mission3->id,
            'proposed_by_id' => $this->players[2]->id,
            'proposal_number' => 1,
            'status' => 'pending'
        ]);

        // Create team members for third mission (all good players)
        MissionProposalMember::create(['proposal_id' => $proposal3->id, 'player_id' => $this->players[0]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal3->id, 'player_id' => $this->players[2]->id]);

        $this->game->update(['current_proposal_id' => $proposal3->id]);

        // Process votes
        foreach ($this->players as $player) {
            $this->gameLoop->processPlayerVote($this->game->fresh(), $player, true); // Everyone approves
        }

        // Create mission team members and their votes
        MissionTeamMember::create(['mission_id' => $mission3->id, 'player_id' => $this->players[0]->id, 'vote_success' => true]);
        MissionTeamMember::create(['mission_id' => $mission3->id, 'player_id' => $this->players[2]->id, 'vote_success' => true]);
        $mission3->update(['status' => 'success']);

        // Set up fourth failed mission
        $mission4 = $this->game->missions()->where('mission_number', 4)->first();
        $proposal4 = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $mission4->id,
            'proposed_by_id' => $this->players[3]->id,
            'proposal_number' => 1,
            'status' => 'pending'
        ]);

        // Create team members for fourth mission (includes assassin)
        MissionProposalMember::create(['proposal_id' => $proposal4->id, 'player_id' => $this->players[1]->id]); // Assassin
        MissionProposalMember::create(['proposal_id' => $proposal4->id, 'player_id' => $this->players[2]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal4->id, 'player_id' => $this->players[3]->id]);

        $this->game->update(['current_proposal_id' => $proposal4->id]);

        // Process votes
        foreach ($this->players as $player) {
            $this->gameLoop->processPlayerVote($this->game->fresh(), $player, true);
        }

        // Create mission team members and their votes (assassin fails it)
        MissionTeamMember::create(['mission_id' => $mission4->id, 'player_id' => $this->players[1]->id, 'vote_success' => false]);
        MissionTeamMember::create(['mission_id' => $mission4->id, 'player_id' => $this->players[2]->id, 'vote_success' => true]);
        MissionTeamMember::create(['mission_id' => $mission4->id, 'player_id' => $this->players[3]->id, 'vote_success' => true]);
        $mission4->update(['status' => 'fail']);

        // Set up fifth successful mission to trigger assassination phase
        $mission5 = $this->game->missions()->where('mission_number', 5)->first();
        $oldLeaderId = $this->players[4]->id;

        $this->game->update([
            'current_phase' => 'mission',
            'current_leader_id' => $oldLeaderId,
            'current_mission_id' => $mission5->id
        ]);

        $proposal5 = MissionProposal::create([
            'game_id' => $this->game->id,
            'mission_id' => $mission5->id,
            'proposed_by_id' => $oldLeaderId,
            'proposal_number' => 1,
            'status' => 'pending'
        ]);

        // Create team members for fifth mission (all good players)
        MissionProposalMember::create(['proposal_id' => $proposal5->id, 'player_id' => $this->players[0]->id]);
        MissionProposalMember::create(['proposal_id' => $proposal5->id, 'player_id' => $this->players[3]->id]);

        $this->game->update(['current_proposal_id' => $proposal5->id]);

        // Process final mission votes - evil players try to prevent but are outvoted
        foreach ($this->players as $player) {
            $this->gameLoop->processPlayerVote($this->game->fresh(), $player, !$isEvil($player));
        }

        // Create mission team members and their votes
        MissionTeamMember::create(['mission_id' => $mission5->id, 'player_id' => $this->players[0]->id]);
        MissionTeamMember::create(['mission_id' => $mission5->id, 'player_id' => $this->players[3]->id]);

        // Have the team members vote for success
        $eligiblePlayers = $this->gameLoop->getEligiblePlayers($this->game->fresh());
        $this->assertCount(2, $eligiblePlayers);
        foreach ($eligiblePlayers as $player) {
            $teamMember = $this->game->currentMission->teamMembers()->where('player_id', $player->id)->first();
            $teamMember->update(['vote_success' => true]);
        }

        // Process the mission result which should trigger assassination phase
        $this->gameLoop->checkPhaseTransition($this->game->fresh());

        // Assertions
        $this->game->refresh();
        $this->assertEquals('assassination', $this->game->current_phase);
        $this->assertEquals($oldLeaderId, $this->game->current_leader_id);
        $this->assertNull($this->game->winner);

        // Verify mission results
        $this->assertEquals('success', $mission1->fresh()->status);
        $this->assertEquals('fail', $mission2->fresh()->status);
        $this->assertEquals('success', $mission3->fresh()->status);
        $this->assertEquals('fail', $mission4->fresh()->status);
        $this->assertEquals('success', $mission5->fresh()->status);

        // Verify game messages for each player
        foreach ($this->players as $player) {
            $playerMessages = $player->messages()->pluck('content')->toArray();
            $lastMessage = end($playerMessages);

            if ($player->role === 'assassin') {
                $this->assertStringContainsString('The good team has won 3 missions. As the Assassin, you must now identify Merlin', $lastMessage);
            } elseif ($player->role === 'merlin') {
                $this->assertStringContainsString('The Assassination phase has begun. The Assassin will try to identify you', $lastMessage);
            } else {
                $this->assertStringContainsString('The Assassination phase has begun. The Assassin will try to identify Merlin', $lastMessage);
            }
        }

        Event::assertDispatched(GameStateUpdate::class);
    }

    public function test_complete_game_plays_automatically()
    {
        $this->game->refresh();

        $maxTurns = 20;
        $turnCount = 0;
        $gameEnded = false;
        $game = $this->game;

        $this->assertNotNull($this->game->currentLeader());

        while (!$gameEnded && $turnCount < $maxTurns) {
            $turnCount++;

            // Log current game state for debugging
            $this->game->refresh();
            $currentPhase = $this->game->current_phase;
            $currentMission = $this->game->currentMission?->mission_number ?? 'None';

            // Process game loop
            $eligiblePlayers = $this->gameLoop->getEligiblePlayers($game);
            foreach ($eligiblePlayers as $player) {
                if (!$player->is_human) {
                    $this->gameLoop->processAIPlayerTurn($game, $player);
                }
            }
            $game->refresh();
            if ($currentPhase !== 'setup') {
                $this->assertNotNull($game->currentLeader, "Leader should always be set after setup. It is turn $turnCount, phase $currentPhase, mission $currentMission");
                $this->assertIsString($game->currentLeader->name);
            }
            $this->gameLoop->checkPhaseTransition($game);
            // Finished game loop.

            // Refresh game state
            $this->game->refresh();

            // Check if game has ended
            $gameEnded = $this->game->winner !== null || $this->game->current_phase === 'game_over';

            // Optional: Add assertions about valid state transitions
            if (!$gameEnded) {
                $this->assertContains($this->game->current_phase, ['team_proposal', 'team_voting', 'mission', 'assassination']);
                if ($this->game->currentMission) {
                    $this->assertGreaterThanOrEqual(1, $this->game->currentMission->mission_number);
                    $this->assertLessThanOrEqual(5, $this->game->currentMission->mission_number);
                }
            }
        }

        $this->assertNotNull($this->game->winner, "Game should have a winner, game is still in current_phase: " . $this->game->current_phase . " and mission: " . $this->game->currentMission->mission_number);

        // Assert game completed within reasonable number of turns
        $this->assertLessThan($maxTurns, $turnCount, "Game did not complete within $maxTurns turns");

        // Assert game reached a valid end state
        $this->assertTrue($gameEnded, "Game should have reached an end state");

        // If game ended in assassination phase
        if ($this->game->current_phase === 'assassination') {
            $this->assertNotNull($this->game->currentMission);
            $successfulMissions = $this->game->missions()
                ->where('status', 'success')
                ->count();
            $this->assertEquals(3, $successfulMissions, "Should be exactly 3 successful missions before assassination");
        }

        // If game ended with a winner
        if ($this->game->winner) {
            $this->assertContains($this->game->winner, ['good', 'evil'], "Winner should be either good or evil");

            // Verify final game state based on winner
            if ($this->game->winner === 'good') {
                // Good team won through failed assassination
                $this->assertNotNull($this->game->assassinationTarget);
                $this->assertNotEquals(
                    $this->game->players()->where('role', 'merlin')->first()->id,
                    $this->game->assassinationTarget
                );
            } else {
                // Evil team won either through failed missions or successful assassination
                $failedMissions = $this->game->missions()
                    ->where('status', 'fail')
                    ->count();

                $successfulAssassination = $this->game->assassinationTarget ===
                    $this->game->players()->where('role', 'merlin')->first()->id;

                $this->assertTrue(
                    $failedMissions >= 3 || $successfulAssassination,
                    "Evil should win through either 3 failed missions or correct assassination"
                );
            }
        }
    }
}
