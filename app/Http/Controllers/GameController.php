<?php

namespace App\Http\Controllers;

use App\Events\GameStateUpdate;
use App\Events\NewMessage;
use App\Jobs\GameLoop;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Message;
use App\Models\MissionProposal;
use App\Models\MissionProposalMember;
use App\Models\MissionProposalVote;
use App\Models\MissionTeamMember;
use App\Models\Player;
use App\Services\GameSetupService;
use App\Services\OpenAIService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function initialize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => 'required|string|in:play,watch',
            'role' => 'nullable|string|in:merlin,assassin,loyal_servant,minion',
        ]);

        $mode = $validated['mode'];
        $humanPlayers = $mode === 'play' ? 1 : 0;
        $preferredRole = $validated['role'] ?? null;

        try {
            $game = GameSetupService::initializeGame($humanPlayers, $preferredRole);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to initialize game',
                'error' => $e->getMessage(),
            ], 500);
        }

        // Start the game loop
        $initialDelay = (int) env('AI_THINKING_TIME_MIN', 2);
        GameLoop::dispatch($game->id)->delay(now()->addSeconds($initialDelay));

        return response()->json([
            'message' => 'Welcome to Avalon! The game will begin shortly.',
            'gameId' => $game->id,
            'players' => $game->players,
            'playerId' => $game->has_human_player ? $game->players->firstWhere('is_human')->id : null,
        ]);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gameId' => 'required|integer',
            'playerId' => 'required|integer',
            'content' => 'required|string',
        ]);

        $game = Game::findOrFail($validated['gameId']);
        $player = Player::findOrFail($validated['playerId']);

        $message = Message::create([
            'game_id' => $validated['gameId'],
            'player_id' => $validated['playerId'],
            'message_type' => 'public_chat',
            'content' => $validated['content'],
        ]);

        broadcast(new NewMessage($message));

        return response()->json([
            'result' => 'Chat message sent',
            'message' => $message,
        ]);
    }

    public function testAI(Request $request): JsonResponse
    {
        $gameId = $request->input('gameId');
        $playerId = $request->input('playerId');
        $runGameLoop = $request->input('runGameLoop');
        $forceWebsocketGamestate = $request->input('forceWebsocketGamestate');
        if ($runGameLoop) {
            if (! $gameId) {
                // Just get the latest game,  the game with the highest ID
                $gameId = Game::max('id');
            }
            GameLoop::dispatchSync($gameId);

            return response()->json(['message' => 'Game loop ran']);
        }

        if ($forceWebsocketGamestate) {
            if (! $gameId) {
                // Just get the latest game,  the game with the highest ID
                $gameId = Game::max('id');
            }

            $game = Game::with(['players', 'messages'])->find($gameId);
            broadcast(new GameStateUpdate($game));

            return response()->json(['message' => 'Game loop ran']);
        }

        $game = Game::with(['players', 'messages'])->find($gameId);
        if (! $game) {
            return response()->json(['error' => 'Game not found'], 400);
        }

        $players = $game->players;
        $player = $players->firstWhere('id', $playerId);

        if (! $player) {
            return response()->json(['error' => 'Player not found'], 400);
        }

        // Create a simple test message array
        $messages = [
            [
                'role' => 'system',
                'content' => "You are {$player->name}, playing as {$player->role} in Avalon. Give a quick greeting to the group.",
            ],
        ];

        // Get response from OpenAI
        $openAI = new OpenAIService;
        $response = $openAI->getChatResponse($messages);

        if (empty($response['message'])) {
            return response()->json(['error' => 'No response from AI'], 500);
        }

        // Create and broadcast the message
        $message = Message::create([
            'game_id' => $gameId,
            'player_id' => $playerId,
            'message_type' => 'public_chat',
            'content' => $response['message'],
        ]);

        broadcast(new NewMessage($message));

        return response()->json([
            'success' => true,
            'message' => $message,
            'aiResponse' => $response,
        ]);
    }

    public function getGameState($gameId): JsonResponse
    {
        try {
            $game = Game::with([
                'players',
                'messages' => fn ($q) => $q->orderBy('created_at', 'asc'),
                'currentMission.teamMembers.player',
                'currentProposal.teamMembers.player',
                'currentProposal.votes.player',
                'gameEvents',
                'missions' => function ($query) {
                    $query->orderBy('mission_number', 'asc')
                        ->with([
                            'teamMembers.player',
                            'proposals' => function ($q) {
                                $q->latest()->with(['teamMembers.player', 'votes']);
                            },
                        ]);
                },
            ])->findOrFail($gameId);

            return response()->json($game->renderFullGameState());
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch game state',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function vote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gameId' => 'required|integer',
            'playerId' => 'required|integer',
            'approved' => 'required|boolean',
        ]);

        $game = Game::with(['players', 'currentMission', 'currentProposal.votes'])->findOrFail($validated['gameId']);
        $player = Player::findOrFail($validated['playerId']);

        if ($game->current_phase !== 'team_voting') {
            return response()->json(['error' => 'Not in team_voting phase'], 422);
        }


        $alreadyVoted = $game->currentProposal->votes->where('player_id', $player->id)->isNotEmpty();
        if ($alreadyVoted) {
            return response()->json(['error' => 'Player has already voted'], 422);
        }

        $gameLoop = new GameLoop($game->id);
        $gameLoop->processPlayerVote($game, $player, $validated['approved']);

        broadcast(new GameStateUpdate($game->fresh()));

        return response()->json(['result' => 'Vote recorded']);
    }

    public function propose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gameId' => 'required|integer',
            'playerId' => 'required|integer',
            'playerIds' => 'required|array',
            'playerIds.*' => 'integer',
        ]);

        $game = Game::with(['players', 'currentMission.proposals'])->findOrFail($validated['gameId']);
        $player = Player::findOrFail($validated['playerId']);

        if ($game->current_phase !== 'team_proposal') {
            return response()->json(['error' => 'Not in team_proposal phase'], 422);
        }

        if ($game->current_leader_id !== $player->id) {
            return response()->json(['error' => 'Player is not the current leader'], 422);
        }

        if ($game->current_proposal_id !== null) {
            return response()->json(['error' => 'A proposal already exists'], 422);
        }

        if (count($validated['playerIds']) !== $game->currentMission->required_players) {
            return response()->json(['error' => 'Wrong number of players for this mission'], 422);
        }

        $proposalNumber = ($game->currentMission->proposals()->max('proposal_number') ?? 0) + 1;

        $proposal = MissionProposal::create([
            'game_id' => $game->id,
            'mission_id' => $game->currentMission->id,
            'proposed_by_id' => $player->id,
            'proposal_number' => $proposalNumber,
            'status' => 'pending',
        ]);

        $proposedPlayers = collect();
        foreach ($validated['playerIds'] as $playerId) {
            MissionProposalMember::create([
                'proposal_id' => $proposal->id,
                'player_id' => $playerId,
            ]);
            $proposedPlayers->push($game->players->firstWhere('id', $playerId));
        }

        $game->current_proposal_id = $proposal->id;
        $game->save();

        // Auto-approve the proposer's own vote
        MissionProposalVote::create([
            'proposal_id' => $proposal->id,
            'player_id' => $player->id,
            'approved' => true,
        ]);

        GameEvent::create([
            'game_id' => $game->id,
            'event_type' => 'team_proposal',
            'event_data' => [
                'proposed_by' => $player->name,
                'team' => $proposedPlayers->pluck('name')->values()->toArray(),
                'proposal_number' => $proposalNumber,
            ],
        ]);

        broadcast(new GameStateUpdate($game->fresh()));

        return response()->json(['result' => 'Proposal submitted']);
    }

    public function assassinate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gameId' => 'required|integer',
            'playerId' => 'required|integer',
            'targetPlayerId' => 'required|integer',
        ]);

        $game = Game::findOrFail($validated['gameId']);
        $player = Player::findOrFail($validated['playerId']);
        $target = Player::findOrFail($validated['targetPlayerId']);

        if ($game->current_phase !== 'assassination') {
            return response()->json(['error' => 'Not in assassination phase'], 422);
        }

        if ($player->role !== 'assassin') {
            return response()->json(['error' => 'Player is not the assassin'], 422);
        }

        if ($game->gameEvents()->where('event_type', 'assassination')->exists()) {
            return response()->json(['error' => 'Assassination already performed'], 422);
        }

        GameEvent::create([
            'game_id' => $game->id,
            'event_type' => 'assassination',
            'event_data' => [
                'assassin_target' => [
                    'player_name' => $target->name,
                    'player_id' => $target->id,
                    'player_role' => $target->role,
                ],
            ],
        ]);

        broadcast(new GameStateUpdate($game->fresh()));

        return response()->json(['result' => 'Assassination recorded']);
    }

    public function missionAction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gameId' => 'required|integer',
            'playerId' => 'required|integer',
            'success' => 'required|boolean',
        ]);

        $game = Game::with(['currentMission.teamMembers'])->findOrFail($validated['gameId']);
        $player = Player::findOrFail($validated['playerId']);

        if ($game->current_phase !== 'mission') {
            return response()->json(['error' => 'Not in mission phase'], 422);
        }

        $teamMember = $game->currentMission->teamMembers->where('player_id', $player->id)->first();
        if (!$teamMember) {
            return response()->json(['error' => 'Player is not on the mission team'], 422);
        }

        if ($teamMember->vote_success !== null) {
            return response()->json(['error' => 'Player has already submitted a mission action'], 422);
        }

        $teamMember->update(['vote_success' => $validated['success']]);

        broadcast(new GameStateUpdate($game->fresh()));

        return response()->json(['result' => 'Mission action recorded']);
    }
}
