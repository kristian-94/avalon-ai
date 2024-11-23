<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Message;
use App\Models\Mission;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

class GameSetupService
{
    private const array ROLE_PROMPTS = [
        'merlin' => 'Your role is Merlin in a game of Avalon. You have magical insight into who the evil players are, but must be subtle in helping the good team win. If you\'re too obvious, the Assassin might identify you and evil will win. Speak wisely.',
        'assassin' => 'You are the Assassin in a game of Avalon. You must deceive the good players and try to make missions fail. At the end of the game, if good has won 3 missions, you\'ll have one chance to identify Merlin to win.',
        'minion' => 'You are a Minion of Mordred in Avalon. You know who the other evil players are, but must be careful not to reveal them too soon. Work with your team to make missions fail and deceive the good players.',
        'loyal_servant' => 'You are a Loyal Servant of Arthur in Avalon. You don\'t have any special knowledge, but must use logic and observation to identify the evil players and ensure the mission succeeds.'
    ];

    public static function initializeGame($humanPlayers = 0): Game
    {
        $mode = $humanPlayers > 0 ? 'play' : 'watch';
        DB::transaction(function () use ($mode, &$game) {
            $roles = ['merlin', 'assassin', 'loyal_servant', 'loyal_servant', 'minion'];

            // 1. Create the game with structured data
            $game = Game::create([
                'has_human_player' => $mode === 'play',
                'started_at' => now(),
                'current_phase' => 'setup',
                'turn_count' => 0
            ]);

            // Create the 5 missions
            $missionRequirements = [2, 3, 2, 3, 2];
            foreach ($missionRequirements as $index => $required) {
                Mission::create([
                    'game_id' => $game->id,
                    'mission_number' => $index + 1,
                    'required_players' => $required,
                    'status' => 'pending',
                    'success_votes' => 0,
                    'fail_votes' => 0
                ]);
            }

            // Set the current mission
            $firstMission = $game->missions()->first();
            $game->current_mission_id = $firstMission->id;
            $game->save();

            // 2. Create players
            $hasHumanPlayer = $mode === 'play';
            $humanRoleIndex = $hasHumanPlayer ? random_int(0, count($roles) - 1) : null;
            $aiNames = ['Max', 'Alex', 'Sam', 'Jordan', 'Riley', 'Taylor', 'Morgan', 'Jamie'];

            $indexedPlayersToRoles = [];

            foreach ($roles as $index => $role) {
                $isHuman = $hasHumanPlayer && $index === $humanRoleIndex;
                $playerName = $isHuman ? 'Kristian' : $aiNames[$index];

                $player = Player::create([
                    'game_id' => $game->id,
                    'player_index' => $index,
                    'name' => $playerName,
                    'role' => $role,
                    'is_human' => $isHuman,
                    'role_knowledge' => [] // Will update after all players are created
                ]);

                $indexedPlayersToRoles[$player->id] = [
                    'role' => $role,
                    'name' => $playerName
                ];
            }

            // 3. Update role knowledge and create initial messages
            foreach ($indexedPlayersToRoles as $playerId => $playerInfo) {
                $player = Player::find($playerId);
                $roleKnowledge = self::generateRoleKnowledge(
                    $playerInfo['role'],
                    $player->player_index,
                    $roles,
                    $indexedPlayersToRoles
                );

                $player->update(['role_knowledge' => $roleKnowledge]);

                if (!$player->is_human) {
                    $namePrompt = 'Your name is ' . $playerInfo['name'] . '. ';
                    Message::create([
                        'game_id' => $game->id,
                        'player_id' => $player->id,
                        'message_type' => 'system_prompt',
                        'content' => $namePrompt . self::ROLE_PROMPTS[$playerInfo['role']] . "\n\n" .
                            self::generateRolePrompt($playerInfo['role'], $roleKnowledge, $indexedPlayersToRoles) .
                            "\n\nThe game is about to begin. Remember your role and act accordingly." .
                            "\n\nYou will also have private thoughts, telling you the state of the game, as well as all public discussion in the game as part of your conversation context." .
                            "\n\nRespond in JSON format according to the provided function schema."
                    ]);
                }
            }

            // 4. Create game start event
            GameEvent::create([
                'game_id' => $game->id,
                'event_type' => 'game_start',
                'player_id' => null,
                'event_data' => [
                    'mode' => $mode,
                    'playerCount' => count($roles),
                    'hasHumanPlayer' => $mode === 'play'
                ]
            ]);
        });

        return $game;
    }

    private static function generateRoleKnowledge(string $role, int $playerIndex, array $allRoles, array $indexedPlayersToRoles): array
    {
        $knowledge = [
            'knownRoles' => [],
            'knownEvil' => []
        ];

        $indexedPlayersToZero = array_values($indexedPlayersToRoles);

        // Find the current player's name
        $currentPlayerName = $indexedPlayersToZero[$playerIndex]['name'];

        foreach ($indexedPlayersToRoles as $id => $info) {
            // Skip if this is the current player
            if ($info['name'] === $currentPlayerName) {
                continue;
            }

            // Merlin knows all evil players
            if ($role === 'merlin' && ($info['role'] === 'assassin' || $info['role'] === 'minion')) {
                $knowledge['knownEvil'][] = $info['name'];
                $knowledge['knownRoles'][$info['name']] = $info['role'];
            }

            // Evil players know other evil players
            if (($role === 'assassin' || $role === 'minion') &&
                ($info['role'] === 'assassin' || $info['role'] === 'minion')) {
                $knowledge['knownEvil'][] = $info['name'];
                $knowledge['knownRoles'][$info['name']] = $info['role'];
            }
        }

        return $knowledge;
    }

    private static function generateRolePrompt(string $role, array $knowledge): string
    {
        return match ($role) {
            'merlin' => 'You know the following players are evil: ' .
                implode(', ', $knowledge['knownEvil']) . '.',
            'assassin' => 'You must deceive the good players and try to identify Merlin. Watch for players who seem to have special knowledge about who is evil. Merlin knows who you are. You know the other evil players are: ' .
                implode(', ', $knowledge['knownEvil']) . '.',
            'loyal_servant' => 'Use your judgment to identify evil players. Pay attention to voting patterns and mission results.',
            'minion' => 'You know the other evil players are: ' .
                implode(', ', $knowledge['knownEvil']) . '.',
            default => '',
        };
    }

}