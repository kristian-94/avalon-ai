<?php

namespace App\Http\Controllers;

use App\Events\NewMessage;
use App\Models\Game;
use App\Models\Player;
use App\Models\Message;
use App\Models\GameEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameController extends Controller
{
    private const ROLE_PROMPTS = [
        'merlin' => 'You are Merlin in a game of Avalon. You have magical insight into who the evil players are, but must be subtle in helping the good team win. If you\'re too obvious, the Assassin might identify you and evil will win. Speak wisely.',
        'assassin' => 'You are the Assassin in a game of Avalon. You must deceive the good players and try to make missions fail. At the end of the game, if good has won 3 missions, you\'ll have one chance to identify Merlin to win.',
        'minion' => 'You are a Minion of Mordred in Avalon. You know who the other evil players are, but must be careful not to reveal them too soon. Work with your team to make missions fail and deceive the good players.',
        'loyal_servant' => 'You are a Loyal Servant of Arthur in Avalon. You don\'t have any special knowledge, but must use logic and observation to identify the evil players and ensure the mission succeeds.'
    ];

    public function initialize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => 'required|string|in:play,spectate'
        ]);

        $mode = $validated['mode'];

        $game = null;

        try {
            DB::transaction(function () use ($mode, &$game) {
                $roles = ['merlin', 'assassin', 'loyal_servant', 'loyal_servant', 'minion'];
                // 1. Create the game
                $game = Game::create([
                    'has_human_player' => $mode === 'play',
                    'started_at' => now(),
                    'game_state' => [
                        'currentPhase' => 'setup',
                        'turnCount' => 0
                    ]
                ]);

                // 2. Create players and their initial messages
                $indexedPlayersToRoles = [];

                foreach ($roles as $index => $role) {
                    $isHuman = $mode === 'play' &&
                        $role === 'loyal_servant' &&
                        random_int(1, count(array_filter($roles, fn($r) => $r === 'loyal_servant'))) === 1;

                    $roleKnowledge = $this->generateRoleKnowledge($role, $index, $roles);

                    $player = Player::create([
                        'game_id' => $game->id,
                        'player_index' => $index,
                        'name' => $isHuman ? 'Human' : "Agent " . ($index + 1),
                        'role' => $role,
                        'is_human' => $isHuman,
                        'role_knowledge' => $roleKnowledge
                    ]);

                    // Create initial message for AI players
                    if (!$isHuman) {
                        Message::create([
                            'game_id' => $game->id,
                            'player_id' => $player->id,
                            'message_type' => 'private_thought',
                            'content' => self::ROLE_PROMPTS[$role] . "\n\n" .
                                $this->generateRolePrompt($role, $roleKnowledge) .
                                "\n\nThe game is about to begin. Remember your role and act accordingly."
                        ]);
                    }

                    $indexedPlayersToRoles[$player->id] = $role;
                }

                // Create additional role-specific messages
                $evilPlayers = array_filter($indexedPlayersToRoles, fn($r) => $r !== 'loyal_servant' && $r !== 'merlin');
                $players = Player::where('game_id', $game->id)->get();

                foreach ($players as $player) {
                    $message = '';
                    switch ($player->role) {
                        case 'merlin':
                            $evilPlayerIndices = $players->whereIn('role', ['assassin', 'minion'])
                                ->pluck('player_index')
                                ->map(fn($i) => "Player " . ($i + 1))
                                ->join(', ');
                            $message = "The evil players are: {$evilPlayerIndices}.";
                            break;
                        case 'assassin':
                        case 'minion':
                            $otherEvilIndices = $players->whereIn('role', ['assassin', 'minion'])
                                ->where('id', '!=', $player->id)
                                ->pluck('player_index')
                                ->map(fn($i) => "Player " . ($i + 1))
                                ->join(', ');
                            $message = "The other evil players are: {$otherEvilIndices}.";
                            break;
                    }

                    if ($message) {
                        Message::create([
                            'game_id' => $game->id,
                            'player_id' => $player->id,
                            'message_type' => 'private_thought',
                            'content' => $message
                        ]);
                    }
                }

                // 3. Create game start event
                GameEvent::create([
                    'game_id' => $game->id,
                    'event_type' => 'game_start',
                    'player_id' => null, // No player associated with game start
                    'event_data' => [
                        'mode' => $mode,
                        'playerCount' => count($roles),
                        'hasHumanPlayer' => $mode === 'play'
                    ]
                ]);

                // 4. Return game data
                return [
                    'game' => $game->fresh(),
                    'players' => $players->map(function($player) {
                        return [
                            'id' => $player->id,
                            'is_human' => $player->is_human,
                            'name' => $player->name
                        ];
                    })
                ];
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to initialize game',
                'error' => $e->getMessage()
            ], 500);
        }

//        ProcessAITurn::dispatch($game->id)->delay(now()->addSeconds(2));
        // will loop and dispatch the next turn until the game is over

        return response()->json([
            'message' => 'Welcome to Avalon! The game will begin shortly.',
            'gameId' => $game->id,
            'players' => $game->players,
            'playerId' => $game->players->firstWhere('is_human')->id
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
    private function generateRoleKnowledge(string $role, int $playerIndex, array $allRoles): array
    {
        $knowledge = [
            'knownRoles' => [],
            'knownEvil' => []
        ];

        switch ($role) {
            case 'merlin':
                foreach ($allRoles as $i => $r) {
                    if ($r === 'assassin') {
                        $knowledge['knownEvil'][] = $i;
                        $knowledge['knownRoles'][$i] = $r;
                    }
                }
                break;
            case 'assassin':
                // Assassin knows other evil players (none in basic game)
                break;
            case 'loyal_servant':
                // Loyal servants know nothing
                break;
        }

        return $knowledge;
    }

    private function generateRolePrompt(string $role, array $knowledge): string
    {
        switch ($role) {
            case 'merlin':
                return 'You know the following players are evil: ' .
                    collect($knowledge['knownEvil'])->map(fn($i) => "Player " . ($i + 1))->join(', ') . '.';
            case 'assassin':
                return 'You must deceive the good players and try to identify Merlin. Watch for players who seem to have special knowledge about who is evil. Merlin knows who you are. You know the other evil players are: ' .
                    collect($knowledge['knownEvil'])->map(fn($i) => "Player " . ($i + 1))->join(', ') . '.';
            case 'loyal_servant':
                return 'Use your judgment to identify evil players. Pay attention to voting patterns and mission results.';
            case 'minion':
                return 'You know the other evil players are: ' .
                    collect($knowledge['knownEvil'])->map(fn($i) => "Player " . ($i + 1))->join(', ') . '.';
            default:
                return '';
        }
    }
}