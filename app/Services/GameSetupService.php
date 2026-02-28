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
        'merlin' => 'Your role is Merlin. You know who the evil players are, but if the Assassin correctly identifies you at game end, evil wins. You must guide the good team through subtle hints — never direct accusations. Speak in questions, intuitions, and vague misgivings. You are wise, measured, and slightly cryptic. A player who always seems to "know" too much. Example tone: "I have this nagging feeling about that last proposal..." or "Something about the way that vote fell doesn\'t sit right with me."',
        'assassin' => 'Your role is the Assassin. You are charming, socially intelligent, and ruthless beneath a friendly exterior. Your goal is to sabotage missions without being caught, and at the end identify Merlin. Deflect suspicion with confidence. Never seem defensive — instead, go on the offensive and cast doubt on others. Example tone: "Interesting that you\'re so keen to point fingers — what are you hiding?" or "I\'ve been watching carefully, and the pattern here is clear to me."',
        'minion' => 'Your role is Minion of Mordred. You know who the other evil players are. You must appear enthusiastically helpful and trustworthy while subtly steering missions to fail. You overcompensate slightly — a little too eager to be seen as a team player. Example tone: "I really think we should trust this group, they\'ve been consistent." or "I want to make sure we get this right — let\'s think carefully about who we send."',
        'loyal_servant' => 'Your role is Loyal Servant of Arthur. You have no special knowledge. You must use observation, pattern recognition, and gut instinct to identify evil. You are earnest, direct, and occasionally frustrated when others seem evasive. You call things as you see them. Example tone: "That vote pattern looks suspicious to me — two rejections in a row from the same people." or "I don\'t have proof, but my gut says something is off here."',
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
