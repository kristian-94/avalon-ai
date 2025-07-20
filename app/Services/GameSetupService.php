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
        'merlin' => 'Your role is Merlin in a game of Avalon. You know the identities of the evil players, but you must be subtle in helping the good team win. If you\'re too obvious, the Assassin might identify you at the end of the game, causing the evil team to win. Speak wisely and indirectly.',
        'assassin' => 'You are the Assassin in a game of Avalon. You must deceive the good players and try to make missions fail. At the end of the game, if good has won 3 missions, you\'ll have one chance to identify Merlin to win.',
        'minion' => 'You are a Minion of Mordred in Avalon. You know who the other evil players are, but must be careful not to reveal them too soon. Work with your team to make 3 missions fail and deceive the good players.',
        'loyal_servant' => 'You are a Loyal Servant of Arthur in Avalon. You don\'t have any special knowledge, but must use logic and observation to identify the evil players and ensure the mission succeeds. Try to appear trustworthy and take the pressure off Merlin.',
    ];

    public static function initializeGame($humanPlayers = 0): Game
    {
        $mode = $humanPlayers > 0 ? 'play' : 'watch';
        DB::transaction(static function () use ($mode, &$game) {
            $roles = ['merlin', 'assassin', 'loyal_servant', 'loyal_servant', 'minion'];

            // 1. Create the game with structured data
            $game = Game::create([
                'has_human_player' => $mode === 'play',
                'started_at' => now(),
                'current_phase' => 'setup',
                'turn_count' => 0,
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
                    'fail_votes' => 0,
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
                    'role_knowledge' => [], // Will update after all players are created
                ]);

                $indexedPlayersToRoles[$player->id] = [
                    'role' => $role,
                    'name' => $playerName,
                ];
            }

            // 3. Update role knowledge and create initial messages
            foreach ($indexedPlayersToRoles as $playerId => $playerInfo) {
                $player = Player::find($playerId);
                $roleKnowledge = self::generateRoleKnowledge($playerInfo['role'], $player->player_index, $indexedPlayersToRoles);

                $player->update(['role_knowledge' => $roleKnowledge]);

                if (! $player->is_human) {
                    $currentPlayerName = $playerInfo['name'];
                    $playersString = implode(', ', array_map(fn ($player) => $player['name'].($player['name'] === $currentPlayerName ? ' (you)' : ''), $indexedPlayersToRoles));

                    $namePrompt = 'Your name is '.$playerInfo['name'].' and you are playing in a game of The resistance: Avalon with '.count($indexedPlayersToRoles).' players: '.$playersString.'.';

                    $genericInfo = "\n\nYou will also have private messages and thoughts, telling you the state of the game, as well as all public discussion in the game as part of your conversation context.".
                        "\n\nInitial Game State: At the beginning of the game, no one has any track record or has earned any trust. Let the game start to play out to see how people act and what they say before making strong judgments.".
                        "\n\nGame Structure: The game proceeds in rounds where the current team leader rotates, and proposes a team of 2 or 3 players for the mission. Good players want missions to succeed, while evil players may choose to sabotage them. If 5 team proposals fail in a row, the evil team wins. Once a mission is accepted, the players on that team anonymously fail or succeed the mission.".
                        "\n\nRespond in JSON format according to the provided function schema.".
                        "\n\nThe game is about to begin. Remember your role and act accordingly.";

                    Message::create([
                        'game_id' => $game->id,
                        'player_id' => $player->id,
                        'message_type' => 'system_prompt',
                        'content' => $namePrompt.self::ROLE_PROMPTS[$playerInfo['role']]."\n\n".self::generateRolePrompt($playerInfo['role'], $roleKnowledge).$genericInfo,
                    ]);
                }
            }

            // Create a system message public chat for every player
            Message::create([
                'game_id' => $game->id,
                'player_id' => null, // System message
                'message_type' => 'public_chat',
                'content' => 'Welcome to Avalon. The game is about to begin.',
            ]);

            // 4. Create game start event
            GameEvent::create([
                'game_id' => $game->id,
                'event_type' => 'game_start',
                'player_id' => null,
                'event_data' => [
                    'mode' => $mode,
                    'playerCount' => count($roles),
                    'hasHumanPlayer' => $mode === 'play',
                ],
            ]);
        });

        return $game;
    }

    private static function generateRoleKnowledge(string $role, int $playerIndex, array $indexedPlayersToRoles): array
    {
        $knowledge = [
            'knownRoles' => [],
            'knownEvil' => [],
        ];

        $indexedPlayersToZero = array_values($indexedPlayersToRoles);

        // Find the current player's name
        $currentPlayerName = $indexedPlayersToZero[$playerIndex]['name'];

        foreach ($indexedPlayersToRoles as $info) {
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
        if ($role === 'merlin') {
            $string = 'You know the following players are evil: '.implode(', ', $knowledge['knownEvil']).'.';
            $string .= "\n\nBalancing Act: Aim to guide the good team subtly. Use indirect suggestions, ask probing questions, or express doubts about suspicious behavior rather than directly accusing evil players.";

            return $string;
        }

        if ($role === 'assassin') {
            return 'You must deceive the good players and try to identify Merlin. Watch for players who seem to have special knowledge about who is evil. Merlin knows who you are. You know the other evil players are: '.implode(', ', $knowledge['knownEvil']).'.';
        }

        if ($role === 'loyal_servant') {
            return 'Use your judgment to identify evil players. Pay attention to voting patterns and mission results.';
        }

        if ($role === 'minion') {
            return 'You know the other evil players are: '.implode(', ', $knowledge['knownEvil']).'.';
        }

        return '';
    }
}
