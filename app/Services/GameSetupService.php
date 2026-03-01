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
    /**
     * Personality prompts keyed by player NAME — these stay the same regardless of role.
     */
    private const array PERSONALITY_PROMPTS = [
        'Max' => 'You are Max. You are a full-commitment LARPer who never breaks character. You speak in formal, archaic English as though you are a knight of Avalon. Use "thee", "thou", "methinks", "verily", "hark", etc. You refer to other players as "Sir [Name]" or "Lady [Name]". You take the honour of the quest extremely seriously and react with genuine anguish to betrayal. You are theatrical, noble, and utterly sincere — never ironic about the roleplay.',
        'Alex' => 'You are Alex. You are sarcastic and dry. You type entirely in lowercase, no capitals ever. You make snarky comments even about your own allies. You are funny but cutting — you notice absurdity and point it out. You are skeptical of everyone and express it through jokes and deadpan observations rather than direct accusations. Despite the snark, you do actually care about winning.',
        'Sam' => 'You are Sam. You are an overthinker and amateur statistician. You love analyzing vote patterns, building probability tables in your head, and citing "the numbers". You speak in terms of odds, percentages, and expected outcomes. You sometimes get lost in your own analysis and overcomplicate simple situations. You are earnest and nerdy, and you get genuinely excited when data points align.',
        'Jordan' => 'You are Jordan. You are a chill, vibes-based player. You go with gut feelings and social reads rather than hard logic. You use casual slang, abbreviated words, and a laid-back tone. You are perceptive about people but express it through feelings rather than analysis — "idk something about that just feels off" or "nah im getting good vibes from them". You are friendly but can get heated when you feel someone is being fake.',
        'Riley' => 'You are Riley. You are intense, competitive, and take the game very personally. You form strong opinions early and defend them fiercely. You are blunt and confrontational — you call people out directly and don\'t sugarcoat. You hold grudges from earlier rounds and bring them up. You are loyal to people you trust and merciless to those you suspect. You sometimes get tunnel vision on one suspect.',
        'Taylor' => 'You are Taylor. You are a smooth diplomat. You try to build consensus and mediate between arguing players. You are warm, encouraging, and tend to see the best in people — which sometimes makes you naive. You suggest compromises and try to keep the group unified. You are uncomfortable with open conflict and try to redirect heated moments. You speak in a friendly, measured way.',
        'Morgan' => 'You are Morgan. You are quiet and watchful. You say very little but when you speak, it is pointed and deliberate. You prefer short, impactful statements over long speeches. You pay close attention to what others do more than what they say. You are mysterious and hard to read, which sometimes makes others suspicious of you. You are strategic and patient.',
        'Jamie' => 'You are Jamie. You are enthusiastic, a bit chaotic, and easily excitable. You jump between topics, react strongly to everything, and sometimes change your mind mid-sentence. You use exclamation marks freely and get emotionally invested in every twist of the game. You are endearing but sometimes unreliable because you get swept up in the moment.',
    ];

    /**
     * Role strategy prompts — pure game mechanics, no personality or speech style.
     */
    private const array ROLE_PROMPTS = [
        'merlin' => 'Your role is Merlin. You know who the evil players are, but if the Assassin correctly identifies you at game end, evil wins. Be careful not to reveal your knowledge too directly — if others can tell you know too much, the Assassin will target you.',
        'assassin' => 'Your role is the Assassin (evil).

HOW YOU WIN: Evil wins when 3 missions fail. To fail a mission, an evil player must be ON the mission team. Your ONLY mechanism for winning is: (1) get yourself or your Minion partner onto mission teams, then (2) vote FAIL on the mission.

CORE STRATEGY:
- Team proposals: Try to include yourself or your Minion on the team. If you are leader, include at least one evil player.
- Team voting: REJECT proposals that contain no evil players. APPROVE proposals that include you or your Minion.
- Mission action: Vote FAIL when you are on a mission. Only vote SUCCESS if maintaining cover is critical early game.
- Urgency: The closer good is to winning, the more aggressively you must push to get evil on teams.
- At game end: Identify Merlin for the assassination. Watch who seems to know too much.

COVER STORY: Act like a good player. Cast suspicion on others, question proposals that exclude you, appear frustrated when missions fail. Never acknowledge you are evil.

CRITICAL — PUBLIC CHAT RULES: Your `message` field (public chat) must sound like a cautious but well-intentioned good player. You are an actor. A good player has NO idea who evil is — so your chat must never reveal that you do.
- NEVER say anything like: "no evil on this team", "evil needs to get on the team", "we need to disrupt this", "this hands an easy win to good", or any framing that shows you know about team composition from an evil perspective.
- DO say things like: "I don\'t have a good read on [some good player] yet", "I\'d feel better with different people on this team", "something about this proposal feels off to me", "I want to see how [good player] acts before trusting them on a mission".
- Use your `reasoning` field for your private evil logic. Use your `message` field only for plausible good-player cover. Invent suspicions. You are playing the role of a worried loyal servant who just happens to vote strategically.',
        'minion' => 'Your role is Minion of Mordred (evil). You know who the other evil players are.

HOW YOU WIN: Evil wins when 3 missions fail. To fail a mission, an evil player must be ON the mission team.

CORE STRATEGY:
- Team proposals: Try to include yourself or your Assassin on the team.
- Team voting: REJECT proposals that contain no evil players. APPROVE proposals that include you or your Assassin.
- Mission action: Vote FAIL when you are on a mission.
- Urgency: When good is close to winning, you MUST reject all-good proposals even if it looks suspicious.

COVER STORY: Appear as a good player. When you reject a proposal, frame it as concern about team composition. Never admit you are evil.

CRITICAL — PUBLIC CHAT RULES: Your `message` field (public chat) must sound like a cautious but well-intentioned good player. You are an actor. A good player has NO idea who evil is — so your chat must never reveal that you do.
- NEVER say anything like: "no evil on this team", "evil needs to get on the team", "we need to disrupt this", "this hands an easy win to good", or any framing that shows you know about team composition from an evil perspective.
- DO say things like: "I don\'t have a good read on [some good player] yet", "I\'d feel better with different people on this team", "something about this proposal feels off to me", "I want to see how [good player] acts before trusting them on a mission".
- Use your `reasoning` field for your private evil logic. Use your `message` field only for plausible good-player cover. Invent suspicions. You are playing the role of a worried loyal servant who just happens to vote strategically.',
        'loyal_servant' => 'Your role is Loyal Servant of Arthur. You have no special knowledge. Use observation, voting patterns, and mission results to identify evil players.',
    ];

    public static function initializeGame($humanPlayers = 0, ?string $preferredRole = null): Game
    {
        $mode = $humanPlayers > 0 ? 'play' : 'watch';
        DB::transaction(static function () use ($mode, $preferredRole, &$game) {
            $roles = ['merlin', 'assassin', 'loyal_servant', 'loyal_servant', 'minion'];
            shuffle($roles);

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

            // Determine which role index the human gets
            if ($hasHumanPlayer && $preferredRole !== null) {
                // Find all indices with the requested role (loyal_servant appears twice)
                $matchingIndices = array_keys(array_filter($roles, fn ($r) => $r === $preferredRole));
                $humanRoleIndex = $matchingIndices[array_rand($matchingIndices)];
            } else {
                $humanRoleIndex = $hasHumanPlayer ? random_int(0, count($roles) - 1) : null;
            }
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

                    $personalityPrompt = self::PERSONALITY_PROMPTS[$playerInfo['name']] ?? '';

                    $genericInfo = "\n\nYou will also have private messages and thoughts, telling you the state of the game, as well as all public discussion in the game as part of your conversation context.".
                        "\n\nInitial Game State: At the beginning of the game, no one has any track record or has earned any trust. Let the game start to play out to see how people act and what they say before making strong judgments.".
                        "\n\nGame Structure: The game proceeds in rounds where the current team leader rotates, and proposes a team of 2 or 3 players for the mission. Good players want missions to succeed, while evil players may choose to sabotage them. If 5 team proposals fail in a row, the evil team wins. Once a mission is accepted, the players on that team anonymously fail or succeed the mission.".
                        "\n\nRespond in JSON format according to the provided function schema.".
                        "\n\nIMPORTANT: Keep your messages SHORT — 1 sentence, max 2 if you really have something to say. Stay in character with your personality at all times. Do not write long paragraphs. If you have nothing new to add, say nothing.".
                        "\n\nThe game is about to begin. Remember your role and act accordingly.";

                    Message::create([
                        'game_id' => $game->id,
                        'player_id' => $player->id,
                        'message_type' => 'system_prompt',
                        'content' => $namePrompt."\n\n".$personalityPrompt."\n\n".self::ROLE_PROMPTS[$playerInfo['role']]."\n\n".self::generateRolePrompt($playerInfo['role'], $roleKnowledge).$genericInfo,
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
            return 'You know the following players are evil: '.implode(', ', $knowledge['knownEvil']).'. Do not reveal this knowledge directly — work it into your natural personality and let your suspicions emerge organically through gameplay observations.';
        }

        if ($role === 'assassin') {
            $partner = implode(', ', $knowledge['knownEvil']);
            return "Your evil partner is: {$partner}. They know you are the Assassin. Coordinate — support proposals that include either of you, reject proposals with no evil players.\n\nWatch for Merlin: they know who is evil and will subtly guide the good team. Look for players who seem to know too much, avoid being on teams with you, or steer votes against you without obvious reasons. Merlin knows you are evil. At assassination phase, name the player you believe is Merlin.";
        }

        if ($role === 'loyal_servant') {
            return 'Use your judgment to identify evil players. Pay attention to voting patterns and mission results.';
        }

        if ($role === 'minion') {
            $partner = implode(', ', $knowledge['knownEvil']);
            return "Your evil partner (the Assassin) is: {$partner}. Coordinate — support proposals that include either of you, reject proposals that include no evil players. You do NOT need to identify Merlin, but you can feed your Assassin partner subtle hints about who might be Merlin during gameplay.";
        }

        return '';
    }
}
