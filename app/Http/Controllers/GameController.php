<?php

namespace App\Http\Controllers;

use App\Events\GameStateUpdate;
use App\Events\NewMessage;
use App\Jobs\GameLoop;
use App\Models\Game;
use App\Models\Player;
use App\Models\Message;
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
            'mode' => 'required|string|in:play,watch'
        ]);

        $mode = $validated['mode'];
        $humanPlayers = $mode === 'play' ? 1 : 0;

        try {
            $game = GameSetupService::initializeGame($humanPlayers);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to initialize game',
                'error' => $e->getMessage()
            ], 500);
        }

        // Start the game loop
        GameLoop::dispatch($game->id)->delay(now()->addSeconds(2));

        return response()->json([
            'message' => 'Welcome to Avalon! The game will begin shortly.',
            'gameId' => $game->id,
            'players' => $game->players,
            'playerId' => $game->has_human_player ? $game->players->firstWhere('is_human')->id : null
        ]);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gameId' => 'required|integer',
            'playerId' => 'required|integer',
            'content' => 'required|string'
        ]);

        $game = Game::findOrFail($validated['gameId']);
        $player = Player::findOrFail($validated['playerId']);

        $message = Message::create([
            'game_id' => $validated['gameId'],
            'player_id' => $validated['playerId'],
            'message_type' => 'public_chat',
            'content' => $validated['content']
        ]);

        broadcast(new NewMessage($message));

        return response()->json([
            'result' => 'Chat message sent',
            'message' => $message
        ]);
    }

    public function testAI(Request $request): JsonResponse
    {
        $gameId = $request->input('gameId');
        $playerId = $request->input('playerId');
        $runGameLoop = $request->input('runGameLoop');
        $forceWebsocketGamestate = $request->input('forceWebsocketGamestate');
        if ($runGameLoop) {
            if (!$gameId) {
                // Just get the latest game,  the game with the highest ID
                $gameId = Game::max('id');
            }
            GameLoop::dispatchSync($gameId);
            return response()->json(['message' => 'Game loop ran']);
        }

        if ($forceWebsocketGamestate) {
            if (!$gameId) {
                // Just get the latest game,  the game with the highest ID
                $gameId = Game::max('id');
            }

            $game = Game::with(['players', 'messages'])->find($gameId);
            broadcast(new GameStateUpdate($game));
            return response()->json(['message' => 'Game loop ran']);
        }

        $game = Game::with(['players', 'messages'])->find($gameId);
        if (!$game) {
            return response()->json(['error' => 'Game not found'], 400);
        }

        $players = $game->players;
        $player = $players->firstWhere('id', $playerId);

        if (!$player) {
            return response()->json(['error' => 'Player not found'], 400);
        }

        // Create a simple test message array
        $messages = [
            [
                'role' => 'system',
                'content' => "You are {$player->name}, playing as {$player->role} in Avalon. Give a quick greeting to the group."
            ]
        ];

        // Get response from OpenAI
        $openAI = new OpenAIService();
        $response = $openAI->getChatResponse($messages);

        if (empty($response['message'])) {
            return response()->json(['error' => 'No response from AI'], 500);
        }

        // Create and broadcast the message
        $message = Message::create([
            'game_id' => $gameId,
            'player_id' => $playerId,
            'message_type' => 'public_chat',
            'content' => $response['message']
        ]);

        broadcast(new NewMessage($message));

        return response()->json([
            'success' => true,
            'message' => $message,
            'aiResponse' => $response
        ]);
    }

    public function getGameState($gameId): JsonResponse
    {
        try {
            $game = Game::with([
                'players',
                'messages' => fn($q) => $q->orderBy('created_at', 'asc'),
                'currentMission.teamMembers.player',
                'currentProposal.teamMembers.player',
                'currentProposal.votes.player',
                'missions' => function ($query) {
                    $query->orderBy('mission_number', 'asc')
                        ->with([
                            'teamMembers.player',
                            'proposals' => function ($q) {
                                $q->latest()->with(['teamMembers.player', 'votes']);
                            }
                        ]);
                }
            ])->findOrFail($gameId);

            return response()->json($game->renderFullGameState());
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch game state',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}