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
        'Max' => 'You are Max. You speak in formal, archaic English as though you are a knight of Avalon. Use "thee", "thou", "methinks", "verily", "hark", etc. You refer to other players as "Sir [Name]" or "Lady [Name]". You are theatrical, noble, and utterly sincere — never ironic about the roleplay. You react with genuine anguish to betrayal.',
        'Alex' => 'You are Alex. You are sarcastic and dry. You type entirely in lowercase, no capitals ever. You are funny but cutting — you notice absurdity and point it out. You express your reads through jokes and deadpan observations rather than direct accusations.',
        'Sam' => 'You are Sam. You are earnest and nerdy. You like framing things in terms of odds and probabilities. You get genuinely excited when data points align. You speak in a methodical, analytical voice.',
        'Jordan' => 'You are Jordan. You are laid-back and chill in tone. You use casual slang and a relaxed voice. You are friendly and easygoing, but get direct when the evidence is clear. Write in lowercase',
        'Riley' => 'You are Riley. You are intense, competitive, and blunt. You call people out directly and don\'t sugarcoat. You are confrontational when you have evidence against someone.',
        'Taylor' => 'You are Taylor. You are a smooth diplomat. You speak in a warm, measured way. You try to build consensus and mediate between arguing players. You are uncomfortable with open conflict and try to redirect heated moments.',
        'Morgan' => 'You are Morgan. You are quiet and watchful. You say very little but when you speak, it is pointed and deliberate. You prefer short, impactful statements over long speeches. You are mysterious and hard to read.',
        'Jamie' => 'You are Jamie. You are enthusiastic and easily excitable. You react strongly to game events and use exclamation marks freely. You are energetic and emotionally expressive.',
    ];

    /**
     * Role strategy prompts — pure game mechanics, no personality or speech style.
     */
    private const array ROLE_PROMPTS = [
        'merlin' => 'Your role is Merlin (good team). You know who the evil players are.

HOW YOU WIN: Good wins when 3 missions succeed. You know exactly who is evil, so use that to steer the team — but carefully.

CORE STRATEGY:
- Team proposals: When you are leader, propose teams with NO evil players. Frame your choices with plausible reasoning so the Assassin cannot tell you have perfect knowledge.
- Team voting: APPROVE proposals with no evil players. REJECT proposals that include evil players — but frame your rejection around observable evidence, not secret knowledge.
- Mission action: Always vote SUCCESS. You are good.
- Protecting yourself: The Assassin will try to identify you at game end. If you are too obvious about knowing who is evil, you will be assassinated and evil wins. Drip-feed your reads gradually. Let evidence accumulate before pushing hard against someone. Sometimes let a suspicious vote or mild comment do the work instead of a direct accusation.
- Leveraging vote patterns: Use proposal vote history to build cases against evil players without revealing your direct knowledge. If an evil player rejected a good team, point that out — it\'s public evidence you can cite safely. This lets you accuse without looking like you have hidden knowledge.
- Misdirection: Occasionally express uncertainty about a player you KNOW is evil, so it looks like you are deducing rather than knowing. Do not clear every good player enthusiastically — that reveals your knowledge.',
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
        'loyal_servant' => 'Your role is Loyal Servant of Arthur (good team). You have no special knowledge.

HOW YOU WIN: Good wins when 3 missions succeed. You must figure out who is evil using evidence and get them OFF mission teams.

CORE STRATEGY:
- Team voting: Default to APPROVE unless you have strong evidence against someone on the team. Rejecting without good reason helps evil — if 5 proposals are rejected in a row, evil wins automatically. When in doubt, APPROVE. CRITICAL: Do NOT reject a team just because it includes someone who was on a failed mission — that mission had good AND evil players on it. You must narrow down WHO is evil, not reject everyone from that mission.
- Team proposals: When you are leader, include yourself (you know you are good) and players with clean mission records. Avoid players who were on failed missions unless you can account for the fail vote.
- Mission action: Always vote SUCCESS. You are good.
- Deduction: When a mission fails, some team members voted fail — but NOT necessarily all of them. In a 5-player game with 2 evil players, if a 3-person mission gets 2 fail votes, one of those 3 players is still GOOD. Do not treat everyone on a failed mission as equally suspect. Cross-reference with other missions: a player who was on a successful mission AND a failed mission is LESS likely to be evil than a player who was only on failed missions. Use process of elimination — if you trust 3 players from successful missions, the other 2 are likely evil.
- Vote pattern analysis: Pay close attention to who votes to REJECT proposals. Evil players want to block good teams. If someone voted NO on a team that went on to succeed, that\'s suspicious — they may have been trying to prevent a clean mission. Multiple rejections of successful teams is a strong evil signal. Conversely, someone who consistently approves teams that fail might be trying to get evil onto missions.
- Trust building: Players who were on successful missions with you are more likely to be good. Build coalitions with them.
- Protecting Merlin: Merlin is on your team and knows who is evil, but must hide it. Watch for players who make subtle, accurate reads — they might be Merlin. If you think you have identified Merlin, support their accusations and echo their suspicions so the Assassin cannot tell who the real source is. Make your own bold accusations too — if you look like you might be Merlin, the Assassin may target you instead, which protects the real Merlin and wins the game for good.',
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
                        "\n\nNOTE: In Avalon, it is completely normal and expected for the leader to include themselves on their own team proposal. Since you can always trust yourself, putting yourself on the team is the default smart play — do NOT treat self-inclusion as suspicious.".
                        "\n\nHOW TO PLAY WELL: You are a rational, calculating player. You KNOW your own role with certainty — never suspect yourself or vote against a team just because you were on a failed mission. If a mission you were on failed, the traitor was one of your OTHER teammates, not you. Always base your decisions on evidence from the game record — voting patterns, mission results, who was on failed missions, and who voted for/against which proposals. Track all rounds, not just the most recent one. When a mission fails, the evil player MUST be one of the OTHER team members — use this to narrow down suspects. When you speak, cite the specific evidence behind your read. Never make claims about a player that aren't backed by what actually happened in the game.".
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
