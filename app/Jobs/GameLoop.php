<?php

namespace App\Jobs;

use App\Events\GameStateUpdate;
use App\Events\NewMessage;
use App\Facades\Agent;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Message;
use App\Models\MissionProposal;
use App\Models\MissionProposalMember;
use App\Models\MissionProposalVote;
use App\Models\MissionTeamMember;
use App\Models\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GameLoop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $minThinkingTime;

    private int $maxThinkingTime;

    public function __construct(public int $gameId)
    {
        $this->minThinkingTime = (int) env('AI_THINKING_TIME_MIN', 2);
        $this->maxThinkingTime = (int) env('AI_THINKING_TIME_MAX', 5);
    }
    
    // Phase timeout settings (in seconds)
    private $phaseTimeouts = [
        'setup' => 300,            // 5 minutes for initial introductions
        'team_proposal' => 180,    // 3 minutes to propose a team
        'team_discussion' => 30,   // 30 seconds for team discussion before voting
        'team_voting' => 120,      // 2 minutes for everyone to vote
        'mission' => 120,          // 2 minutes for mission execution
        'evil_discussion' => 120,  // 2 minutes for evil team to discuss Merlin
        'assassination' => 180,    // 3 minutes for assassination
    ];

    public function handle(): void
    {
        $game = Game::with(['players', 'messages', 'gameEvents', 'currentMission', 'currentProposal'])->find($this->gameId);

        if (! $game || $game->ended_at !== null || in_array($game->current_phase, ['game_over', 'finished'])) {
            return;
        }

        // Discard stale jobs for games that haven't had any activity in over 2 hours
        if ($game->updated_at && $game->updated_at->lt(now()->subHours(2))) {
            Log::info("Game {$game->id} is stale (last activity: {$game->updated_at}), discarding job.");
            $game->update(['current_phase' => 'finished', 'ended_at' => now()]);
            return;
        }

        // Cost runaway protection: limit concurrent active games
        $maxActiveGames = (int) env('MAX_ACTIVE_GAMES', 2);
        $activeGames = Game::whereNull('ended_at')
            ->whereNotIn('current_phase', ['game_over', 'finished', 'debrief'])
            ->count();
        if ($activeGames > $maxActiveGames) {
            Log::warning("Game {$game->id} paused: {$activeGames} active games exceeds MAX_ACTIVE_GAMES={$maxActiveGames}");
            // Re-dispatch after a delay so it retries when other games finish
            self::dispatch($this->gameId)->delay(now()->addSeconds(30));
            return;
        }

        // Check for phase timeout FIRST
        if ($this->hasPhaseTimedOut($game)) {
            Log::warning("Game {$game->id} phase {$game->current_phase} has timed out");
            $this->forcePhaseTransition($game);
            $this->checkPhaseTransition($game->fresh());
            
            // Continue with next iteration
            self::dispatch($this->gameId)->delay(now()->addSeconds(1));
            return;
        }

        // Determine which players should act based on the current phase
        $eligiblePlayers = $this->getEligiblePlayers($game);
        $eligiblePlayers = $this->orderEligiblePlayers($game, $eligiblePlayers);

        // Process turns for all eligible AI players in this job
        $aiTurnsProcessed = 0;
        foreach ($eligiblePlayers as $player) {
            if (! $player->is_human) {
                $this->processAIPlayerTurn($game, $player);
                $aiTurnsProcessed++;
            } elseif ($game->current_phase === 'mission' && ! in_array($player->role, ['assassin', 'minion'])) {
                // Human good player on a mission always votes success — auto-submit so they don't have to click
                $teamMember = $game->currentMission?->teamMembers->firstWhere('player_id', $player->id);
                if ($teamMember && $teamMember->vote_success === null) {
                    $teamMember->update(['vote_success' => true]);
                    broadcast(new GameStateUpdate($game->fresh()));
                    $aiTurnsProcessed++; // counts as activity so the loop keeps moving
                }
            }
        }

        // Refresh game state before phase check — human API calls may have already transitioned
        $game = $game->fresh(['players', 'messages', 'gameEvents', 'currentMission', 'currentMission.teamMembers', 'currentProposal', 'currentProposal.teamMembers', 'currentProposal.votes', 'missions']);

        // Check if we need to transition to a new phase
        $this->checkPhaseTransition($game);

        // In debrief, keep polling so AI players respond when the human chats.
        // Only stop if no human messages in the last 5 minutes (human has left).
        if ($game->current_phase === 'debrief' && $aiTurnsProcessed === 0) {
            $lastHumanMessage = $game->messages()
                ->where('message_type', 'public_chat')
                ->whereHas('player', fn ($q) => $q->where('is_human', true))
                ->latest()
                ->first();

            $humanIdleMinutes = $lastHumanMessage
                ? $lastHumanMessage->created_at->diffInMinutes(now())
                : ($game->gameEvents()->where('event_type', 'game_end')->first()?->created_at?->diffInMinutes(now()) ?? 999);

            if ($humanIdleMinutes >= 5) {
                $this->concludeGame($game);
                return;
            }
        }

        // When waiting for human input (no AI work done), use a poll interval to avoid queue flooding.
        // Otherwise use the configured thinking time.
        $thinkingMs = random_int($this->minThinkingTime, $this->maxThinkingTime) * 1000;
        $delayMs = ($aiTurnsProcessed === 0) ? max(500, $thinkingMs) : max(200, $thinkingMs);

        self::dispatch($this->gameId)->delay(now()->addMilliseconds($delayMs));
    }

    public function concludeGame(Game $game): void
    {
        $game->update([
            'ended_at' => now(),
            'current_phase' => 'finished',
        ]);
        broadcast(new GameStateUpdate($game));
    }

    public function endGame(Game $game, string $winner, string $reason = ''): void
    {
        $game->update([
            // Don't set ended_at yet — debrief phase first
            'current_phase' => 'debrief',
            'winner' => $winner,
        ]);

        GameEvent::create([
            'game_id' => $game->id,
            'event_type' => 'game_end',
            'event_data' => ['winner' => $winner, 'reason' => $reason],
        ]);

        broadcast(new GameStateUpdate($game));

        // Build role reveal string
        $roleRevealString = $game->players->map(function ($player) {
            $connectingWord = match (true) {
                $player->role === 'merlin' => '',
                str_starts_with($player->role, 'a') || str_starts_with($player->role, 'e') || str_starts_with($player->role, 'i') || str_starts_with($player->role, 'o') || str_starts_with($player->role, 'u') => ' an',
                default => ' a'
            };

            return " {$player->name} was{$connectingWord} {$player->role}.";
        })->join('');

        $assassination_event = $game->gameEvents()->where('event_type', 'assassination')->first();
        $wonAfterAssassination = (bool) $assassination_event;
        $rejectedProposals = $game->currentMission->proposals()->where('status', 'rejected')->count();
        $wonAfter5FailedProposals = $rejectedProposals >= 5;

        // Create messages for each player
        foreach ($game->players as $player) {
            $message = match (true) {
                // Merlin's special messages
                $player->role === 'merlin' && $winner === 'good' => 'You are Merlin and the good team has won. You were able to identify all the evil players. ',
                $player->role === 'merlin' && $wonAfterAssassination => 'You are Merlin and were too obvious. The evil team has won by killing you. ',
                $player->role === 'merlin' && $wonAfter5FailedProposals => 'You are Merlin and the evil team has won. You were unable to stop the chaos of the team rejections. ',
                $player->role === 'merlin' => 'You are Merlin and the evil team has won. You were unable to identify all the evil players. ',
                default => ''
            };

            // Add game outcome message
            $message .= 'The game has ended. '.match (true) {
                $winner === 'good' && $wonAfterAssassination => 'The loyal servants have won. The Assassin was unable to identify Merlin.',
                $winner === 'good' => 'The loyal servants have won.',
                $wonAfterAssassination => 'The minions of Mordred have won. The Assassin was able to identify Merlin.',
                $wonAfter5FailedProposals => 'The minions of Mordred have won through team rejection chaos.',
                default => 'The minions of Mordred have won.'
            };

            Message::create([
                'game_id' => $game->id,
                'player_id' => $player->id,
                'message_type' => 'game_event',
                'content' => $message.$roleRevealString,
            ]);
        }
    }

    public function getEligiblePlayers(Game $game): array
    {
        return match ($game->current_phase) {
            'setup' => $game->players()
                ->where('is_human', false)
                ->whereDoesntHave('messages', function ($query) {
                    $query->where('message_type', 'public_chat');
                })
                ->get()
                ->all(),

            'team_proposal' => $game->players()
                ->where('id', $game->current_leader_id)
                ->get()
                ->all(),

            'team_voting' => (function () use ($game) {
                // Unvoted players always get a turn (to vote + optionally chat).
                // Voted players get one more chat turn — but only if they haven't
                // spoken since casting their vote. This prevents the rolling-cascade
                // where each new message re-qualifies everyone who voted earlier.
                $unvoted = $game->players()
                    ->where('is_human', false)
                    ->whereDoesntHave('proposalVotes', function ($query) use ($game) {
                        $query->where('proposal_id', $game->current_proposal_id);
                    })
                    ->get();

                $votedAndSilent = $game->players()
                    ->where('is_human', false)
                    ->whereHas('proposalVotes', function ($query) use ($game) {
                        $query->where('proposal_id', $game->current_proposal_id);
                    })
                    ->get()
                    ->filter(function ($player) use ($game) {
                        $vote = $player->proposalVotes()
                            ->where('proposal_id', $game->current_proposal_id)
                            ->first();
                        if (! $vote) {
                            return false;
                        }
                        // Eligible only if they haven't spoken since casting their vote
                        return ! $player->messages()
                            ->where('message_type', 'public_chat')
                            ->where('created_at', '>', $vote->created_at)
                            ->exists();
                    });

                return $unvoted->merge($votedAndSilent)->all();
            })(),

            'mission' => $game->currentMission
                ->teamMembers()
                ->whereNull('vote_success')
                ->with('player')
                ->get()
                ->pluck('player')
                ->all(),

            'team_discussion' => (function () use ($game) {
                // Each AI player speaks at most once per discussion phase.
                $phaseStartEvent = $game->gameEvents()
                    ->where('event_type', 'phase_start')
                    ->where('event_data->phase', 'team_discussion')
                    ->latest()->first();
                $since = $phaseStartEvent?->created_at ?? $game->started_at;

                return $game->players()
                    ->where('is_human', false)
                    ->whereDoesntHave('messages', function ($q) use ($since) {
                        $q->where('message_type', 'public_chat')
                          ->where('created_at', '>=', $since);
                    })
                    ->get()->all();
            })(),

            'evil_discussion' => (function () use ($game) {
                // All evil players who haven't spoken since the evil_discussion phase began
                $phaseStartEvent = $game->gameEvents()
                    ->where('event_type', 'phase_start')
                    ->where('event_data->phase', 'evil_discussion')
                    ->latest()
                    ->first();
                $since = $phaseStartEvent?->created_at ?? $game->started_at;

                return $game->players()
                    ->whereIn('role', ['assassin', 'minion'])
                    ->where('is_human', false)
                    ->whereDoesntHave('messages', function ($q) use ($since) {
                        $q->where('message_type', 'public_chat')
                            ->where('created_at', '>=', $since);
                    })
                    ->get()
                    ->all();
            })(),

            'assassination' => $game->players()->where('role', 'assassin')->get()->all(),

            'debrief' => (function () use ($game) {
                // Trigger: game_end event OR latest human message, whichever is more recent
                $gameEndEvent = $game->gameEvents()->where('event_type', 'game_end')->first();
                $gameEndTime = $gameEndEvent?->created_at ?? now();

                $lastHumanMessage = $game->messages()
                    ->where('message_type', 'public_chat')
                    ->whereHas('player', fn ($q) => $q->where('is_human', true))
                    ->latest()
                    ->first();

                $since = ($lastHumanMessage && $lastHumanMessage->created_at > $gameEndTime)
                    ? $lastHumanMessage->created_at
                    : $gameEndTime;

                return $game->players()
                    ->where('is_human', false)
                    ->whereDoesntHave('messages', function ($q) use ($since) {
                        $q->where('message_type', 'public_chat')
                            ->where('created_at', '>=', $since);
                    })
                    ->get()
                    ->all();
            })(),

            default => []
        };
    }

    /**
     * Order eligible players using name-mention weighting + rolling cooldown.
     * - Players mentioned by name in the last message get a higher draw weight (8×).
     * - The last 3 speakers get a graduated penalty so everyone gets heard:
     *     position 0 (last spoke):   ×0.1
     *     position 1 (2nd last):     ×0.35
     *     position 2 (3rd last):     ×0.65
     * Weights are scaled ×10 to keep integer arithmetic throughout.
     */
    private function orderEligiblePlayers(Game $game, array $players): array
    {
        if (count($players) <= 1) {
            return $players;
        }

        $recentMessages = $game->messages()
            ->where('message_type', 'public_chat')
            ->latest()
            ->take(3)
            ->get();

        // [most-recent speaker id, 2nd, 3rd] — may have duplicates (same person spoke twice)
        $recentSpeakerIds = $recentMessages->pluck('player_id')->toArray();
        $lastMessageText  = strtolower($recentMessages->first()?->content ?? '');

        // Cooldown factors by recency position (out of 10)
        $cooldownFactors = [1, 4, 7]; // positions 0, 1, 2

        $weighted = [];
        foreach ($players as $player) {
            // Base: 10; name-mention boost: 80 (8×)
            $weight = stripos($lastMessageText, strtolower($player->name)) !== false ? 80 : 10;

            // Apply the steepest cooldown found for this player in the last 3 messages
            $pos = array_search($player->id, $recentSpeakerIds);
            if ($pos !== false) {
                $weight = max(1, (int) ($weight * $cooldownFactors[$pos] / 10));
            }

            $weighted[] = ['player' => $player, 'weight' => $weight];
        }

        // Weighted random draw without replacement
        $ordered = [];
        while (! empty($weighted)) {
            $totalWeight = array_sum(array_column($weighted, 'weight'));
            $rand        = mt_rand(1, max(1, $totalWeight));
            $cumulative  = 0;
            foreach ($weighted as $i => $item) {
                $cumulative += $item['weight'];
                if ($rand <= $cumulative) {
                    $ordered[] = $item['player'];
                    array_splice($weighted, $i, 1);
                    break;
                }
            }
        }

        return $ordered;
    }

    public function processAIPlayerTurn(Game $game, Player $player): void
    {
        $messages = $this->prepareAIContext($game, $player);
        $successCount = $game->missions()->where('status', 'success')->count();
        $failCount    = $game->missions()->where('status', 'fail')->count();
        Log::info("AI turn: {$player->name} ({$player->role}) | phase={$game->current_phase} | missions S{$successCount}/F{$failCount}");
        $response = Agent::getChatResponse($messages);

        // Dump full API context for debugging
        if ($player->role === 'loyal_servant' || ($player->role === 'assassin' && $game->current_phase === 'assassination')) {
            $dumpDir = storage_path('app/ai-dumps');
            if (!is_dir($dumpDir)) {
                mkdir($dumpDir, 0755, true);
            }
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "{$dumpDir}/game{$game->id}_{$player->name}_{$game->current_phase}_{$timestamp}.json";
            file_put_contents($filename, json_encode([
                'game_id' => $game->id,
                'player' => $player->name,
                'role' => $player->role,
                'phase' => $game->current_phase,
                'messages' => $messages,
                'response' => $response,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // Track API usage
        if (! empty($response['_usage'])) {
            $game->increment('api_calls');
            $game->increment('total_tokens', $response['_usage']['total_tokens'] ?? 0);
            $game->increment('prompt_tokens', $response['_usage']['prompt_tokens'] ?? 0);
            $game->increment('completion_tokens', $response['_usage']['completion_tokens'] ?? 0);
        }

        // Bail only if completely empty
        $hasAction = isset($response['vote']) || isset($response['team_proposal'])
            || isset($response['mission_action']) || isset($response['assassination_target']);
        if (empty($response['message']) && !$hasAction) {
            Log::error('Empty AI response', ['game_id' => $game->id, 'player_id' => $player->id]);

            return;
        }

        // Handle vote if provided
        if ($game->current_phase === 'team_voting' && isset($response['vote'])) {
            $this->processPlayerVote($game, $player, $response['vote']);
        }

        // Handle mission action if provided
        if ($game->current_phase === 'mission' && isset($response['mission_action']) && $game->currentMission) {
            $teamMember = $game->currentMission->teamMembers()->where('player_id', $player->id)->first();
            if ($teamMember) {
                $teamMember->update(['vote_success' => $response['mission_action']]);
            }
        }

        // Handle team proposal
        if ($game->current_phase === 'team_proposal' && isset($response['team_proposal']) && $game->current_leader_id === $player->id && $game->currentProposal === null) {
            // Add team members to proposal (case-insensitive name match against loaded players)
            $allPlayers = $game->players;
            $proposedPlayers = collect(explode(',', $response['team_proposal']))
                ->map(fn ($name) => $allPlayers->first(fn ($p) => strtolower($p->name) === strtolower(trim($name))))
                ->filter();

            $required = $game->currentMission->required_players;
            if ($proposedPlayers->isEmpty()) {
                Log::warning("Game {$game->id}: {$player->name} proposed unrecognised player names, skipping: {$response['team_proposal']}");
            } elseif ($proposedPlayers->count() !== $required) {
                Log::warning("Game {$game->id}: {$player->name} proposed {$proposedPlayers->count()} players but mission requires {$required}, skipping");
            } else {
            $proposal = MissionProposal::create([
                'game_id' => $game->id,
                'mission_id' => $game->currentMission->id,
                'proposed_by_id' => $player->id,
                'proposal_number' => ($game->currentMission->proposals()->max('proposal_number') ?? 0) + 1,
                'status' => 'pending',
            ]);

            foreach ($proposedPlayers as $proposedPlayer) {
                MissionProposalMember::create([
                    'proposal_id' => $proposal->id,
                    'player_id' => $proposedPlayer->id,
                ]);
            }

            $game->current_proposal_id = $proposal->id;
            $game->save();

            // Auto-approve the proposer's own vote (same as controller does for human proposals)
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
                    'proposal_number' => $proposal->proposal_number,
                ],
            ]);
            } // end else (valid team size)
        }

        // Handle assassination
        if ($game->current_phase === 'assassination' && isset($response['assassination_target'])) {
            $targetName = trim($response['assassination_target'], " \t\n\r\0\x0B.");
            // Try exact match first, then case-insensitive fallback
            $targetPlayer = $game->players()->where('name', $targetName)->first()
                ?? $game->players->first(fn ($p) => strtolower($p->name) === strtolower($targetName));
            if (!$targetPlayer) {
                Log::error('Assassination target not found', ['target' => $targetName, 'game_id' => $game->id]);
                return;
            }

            GameEvent::create([
                'game_id' => $game->id,
                'event_type' => 'assassination',
                'event_data' => [
                    'assassin_target' => [
                        'player_name' => $response['assassination_target'],
                        'player_id' => $targetPlayer->id,
                        'player_role' => $targetPlayer->role,
                    ],
                ],
            ]);
        }

        // Create message only if non-empty
        if (!empty($response['message'])) {
            $message = Message::create([
                'game_id' => $game->id,
                'player_id' => $player->id,
                'message_type' => 'public_chat',
                'content' => $response['message'],
            ]);

            broadcast(new NewMessage($message));
        }
    }

    public function prepareAIContext(Game $game, Player $player): array
    {
        $messages = [];
        
        // Always start with the system prompt
        $systemPrompt = $game->messages()
            ->where('message_type', 'system_prompt')
            ->where('player_id', $player->id)
            ->first();
            
        if ($systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt->content,
            ];
        }
        
        // Add current game state summary
        $gameStateSummary = $this->generateGameStateSummary($game, $player);
        $messages[] = [
            'role' => 'system',
            'content' => $gameStateSummary,
        ];
        
        // Get all public chat — full history, cheap (~1k tokens for a full game)
        $recentChat = $game->messages()
            ->where('message_type', 'public_chat')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn ($msg) => [
                'role' => $msg->player_id === $player->id ? 'assistant' : 'user',
                'content' => ($msg->player?->name ?? 'System') . ': ' . $msg->content,
            ])
            ->values();

        // Get this player's own recent game_event prompts (last 5) for phase context
        $recentEvents = $game->messages()
            ->where('message_type', 'game_event')
            ->where('player_id', $player->id)
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get()
            ->reverse()
            ->map(fn ($msg) => [
                'role' => 'system',
                'content' => $msg->content,
            ])
            ->values();

        // Merge: events first (context), then chat (conversation)
        $recentMessages = collect($recentEvents)->concat($recentChat)->values();

        foreach ($recentMessages as $msg) {
            $messages[] = $msg;
        }
        
        return $messages;
    }
    
    private function generateGameStateSummary(Game $game, Player $player): string
    {
        $summary = "=== CURRENT GAME STATE ===\n";
        // Use player-specific phase markers so the AI schema can require actions
        // from the right players (leader must propose, team members must act, etc.)
        $effectivePhase = $game->current_phase;
        if ($game->current_phase === 'team_voting' && $game->currentProposal) {
            $hasVoted = $game->currentProposal->votes->where('player_id', $player->id)->isNotEmpty();
            if ($hasVoted) {
                $effectivePhase = 'team_voting_voted';
            }
        } elseif ($game->current_phase === 'team_proposal' && $game->current_leader_id === $player->id) {
            $effectivePhase = 'team_proposal_leader';
        } elseif ($game->current_phase === 'mission' && $game->currentMission) {
            $onMission = $game->currentMission->teamMembers->where('player_id', $player->id)->isNotEmpty();
            $hasActed = $onMission && $game->currentMission->teamMembers->where('player_id', $player->id)->whereNotNull('vote_success')->isNotEmpty();
            if ($onMission && !$hasActed) {
                $effectivePhase = 'mission_on_team';
            }
        } elseif ($game->current_phase === 'evil_discussion') {
            if (in_array($player->role, ['assassin', 'minion'])) {
                $effectivePhase = 'evil_discussion_evil';
            }
            // good players see the default 'evil_discussion' phase (no action required)
        } elseif ($game->current_phase === 'assassination' && $player->role === 'assassin') {
            $effectivePhase = 'assassination_assassin';
        }
        $summary .= "Phase: {$effectivePhase}\n";

        // Mission status
        $successfulMissions = $game->missions()->where('status', 'success')->count();
        $failedMissions = $game->missions()->where('status', 'fail')->count();
        $summary .= "Missions: {$successfulMissions} successful, {$failedMissions} failed\n";

        // Win condition summary
        $goodNeeds = max(0, 3 - $successfulMissions);
        $evilNeeds = max(0, 3 - $failedMissions);
        $summary .= "Win conditions: Good needs {$goodNeeds} more success(es). Evil needs {$evilNeeds} more failure(s).\n";

        // === GAME LOG — built from GameEvent records ===
        $events = $game->gameEvents()->orderBy('id')->get();
        $gameLog = $this->buildGameLog($events);
        if ($gameLog !== '') {
            $summary .= "\n=== GAME LOG ===\n" . $gameLog;
        }

        // === PLAYER TRACKER ===
        $playerTracker = $this->buildPlayerTracker($game, $events);
        if ($playerTracker !== '') {
            $summary .= "\n=== PLAYER TRACKER ===\n" . $playerTracker;
        }

        // === VOTE PATTERN ANALYSIS ===
        $votePatternAnalysis = $this->buildVotePatternAnalysis($game, $events, $player);
        if ($votePatternAnalysis !== '') {
            $summary .= "\n=== VOTE PATTERN ANALYSIS ===\n" . $votePatternAnalysis;
        }

        // === CURRENT SITUATION ===
        $summary .= "\n=== CURRENT SITUATION ===\n";
        if ($game->currentMission) {
            $summary .= "Current Mission: #{$game->currentMission->mission_number} (requires {$game->currentMission->required_players} players)\n";
            $rejectedCount = $game->currentMission->proposals()->where('status', 'rejected')->count();
            if ($rejectedCount > 0) {
                $remaining = 5 - $rejectedCount;
                if ($rejectedCount >= 3) {
                    $summary .= "🚨 DANGER: {$rejectedCount} proposals rejected this round! Only {$remaining} rejection(s) left before EVIL WINS AUTOMATICALLY. You should APPROVE the next reasonable team even if it's imperfect — a risky mission is better than handing evil a free win through rejections.\n";
                } else {
                    $summary .= "⚠️ Rejected proposals this round: {$rejectedCount}/5 — {$remaining} more rejection(s) and EVIL WINS AUTOMATICALLY.\n";
                }
            }
        }
        if ($game->current_leader_id) {
            $isLeader = $game->current_leader_id === $player->id;
            $leader = $game->players()->find($game->current_leader_id);
            if ($leader) {
                $summary .= "Current Leader: {$leader->name}" . ($isLeader ? " (YOU)" : "") . "\n";
            }
        }
        if ($game->current_phase === 'team_voting' && $game->currentProposal) {
            $proposedTeam = $game->currentProposal->teamMembers->pluck('player.name')->join(', ');
            $summary .= "Proposed Team: {$proposedTeam}\n";
            $votedPlayers = $game->currentProposal->votes->pluck('player.name')->join(', ');
            if ($votedPlayers) {
                $summary .= "Already Voted: {$votedPlayers}\n";
            }
        }
        if ($game->current_phase === 'mission' && $game->currentMission) {
            $missionTeam = $game->currentMission->teamMembers->pluck('player.name')->join(', ');
            $summary .= "Mission Team: {$missionTeam}\n";
            $onMission = $game->currentMission->teamMembers->where('player_id', $player->id)->isNotEmpty();
            if ($onMission) {
                $summary .= "You are ON this mission team.\n";
            }
        }

        // === YOUR ROLE (evil intel) ===
        $isEvil = in_array($player->role, ['assassin', 'minion']);
        if ($isEvil) {
            $knownEvilNames = array_keys($player->role_knowledge['knownRoles'] ?? []);
            $evilNames = array_merge([$player->name], $knownEvilNames);

            $summary .= "\n=== YOUR EVIL TEAM INTEL ===\n";
            $partnerNames = implode(' and ', $knownEvilNames);
            $summary .= "Evil players (you + partner): " . implode(', ', $evilNames) . "\n";
            $summary .= "REMINDER: {$partnerNames} is on YOUR side. You KNOW they are evil like you. NEVER genuinely suspect, doubt, or work against your partner. Your public chat should cast suspicion on GOOD players, not on {$partnerNames}. If you mention {$partnerNames} in chat, defend them or stay neutral — never build a case against your own teammate.\n";
            $summary .= "COVER AUDIT — before writing your message, ask: does this sound like something a clueless good player would say? Two patterns that instantly expose you: (1) directly lobbying for yourself or {$partnerNames} to be on a team without evidence-based reasoning that any good player would use, (2) expressing suspicion of players who have only been on successful missions while defending players who were on failed ones. If your draft message does either of these, rewrite it or say nothing.\n";

            if ($goodNeeds === 1) {
                $summary .= "⚠️ CRITICAL: Good team needs just 1 more success to win. You MUST prevent it.\n";
            } elseif ($goodNeeds === 2) {
                $summary .= "⚠️ URGENT: Good team needs 2 more successes. Increase pressure now.\n";
            }

            if (in_array($game->current_phase, ['team_voting']) && $game->currentProposal) {
                $proposedNames = $game->currentProposal->teamMembers->pluck('player.name')->toArray();
                $evilOnProposal = array_values(array_intersect($evilNames, $proposedNames));
                if (empty($evilOnProposal)) {
                    $summary .= "⛔ PROPOSED TEAM HAS NO EVIL PLAYERS (" . implode(', ', $proposedNames) . "). If this runs, you CANNOT sabotage it — it will succeed. You should REJECT this proposal (vote: false) unless rejecting would look too suspicious given the game state.\n";
                    $goodPlayers = array_values(array_diff($proposedNames, $evilNames));
                    $coverTarget = !empty($goodPlayers) ? $goodPlayers[0] : 'someone on the team';
                    $summary .= "Cover language for your public message: Express doubt about a specific player (e.g. '$coverTarget'), say you'd want different people, or claim the team feels rushed — NOT that evil is missing. Never mention evil team composition in public chat.\n";
                } else {
                    $summary .= "✓ Evil player(s) on proposed team: " . implode(', ', $evilOnProposal) . ". Approving this enables sabotage. In public chat, sound enthusiastic or neutral about the team — do NOT give away that you're happy about evil being included.\n";
                }
            }

            if ($game->current_phase === 'team_proposal' && $game->current_leader_id === $player->id) {
                $partner = implode(' or ', $knownEvilNames);
                $summary .= "👑 YOU ARE THE LEADER. Include yourself and/or {$partner} in your proposal. In public chat, frame your proposal as a confident or well-reasoned choice — do NOT hint that you're including yourself or your partner for evil purposes.\n";
            }

            if ($game->current_phase === 'mission' && $game->currentMission) {
                $missionNames = $game->currentMission->teamMembers->pluck('player.name')->toArray();
                $evilOnMission = array_values(array_intersect($evilNames, $missionNames));
                if (!empty($evilOnMission)) {
                    $missionDecision = $evilNeeds > 0 ? "FAIL this mission (mission_action: false) — evil needs {$evilNeeds} more failure(s)." : "Evil already has enough failures; consider success to maintain cover.";
                    $summary .= "Evil on mission: " . implode(', ', $evilOnMission) . ". Recommendation: {$missionDecision}\n";
                }
            }

            if (in_array($game->current_phase, ['evil_discussion', 'assassination'])) {
                $goodPlayers = $game->players->filter(fn ($p) => !in_array($p->role, ['assassin', 'minion']))->pluck('name')->values()->all();
                $summary .= "\n🎯 MERLIN HUNT: The good players who could be Merlin are: " . implode(', ', $goodPlayers) . ".\n";
                $summary .= "Merlin knows who is evil and has been guiding the good team all game. Look for players who:\n";
                $summary .= "  - Voted against proposals that included you or your partner\n";
                $summary .= "  - Seemed to know who was trustworthy without obvious reasons\n";
                $summary .= "  - Made accurate reads early, before evidence was clear\n";
                $summary .= "  - Steered the team away from evil players multiple times\n";
                if ($game->current_phase === 'evil_discussion') {
                    $summary .= "Discuss with your evil partner who you think Merlin is. Share your reasoning — the Assassin will make the final call.\n";
                } else {
                    // assassination phase — include partner's last discussion message if available
                    $phaseStartEvent = $game->gameEvents()
                        ->where('event_type', 'phase_start')
                        ->where('event_data->phase', 'evil_discussion')
                        ->latest()
                        ->first();
                    if ($phaseStartEvent) {
                        $partnerMessages = $game->messages()
                            ->whereHas('player', fn ($q) => $q->whereIn('role', ['assassin', 'minion'])->where('id', '!=', $player->id))
                            ->where('message_type', 'public_chat')
                            ->where('created_at', '>', $phaseStartEvent->created_at)
                            ->get();
                        if ($partnerMessages->isNotEmpty()) {
                            $summary .= "Your evil partner(s) said during the discussion:\n";
                            foreach ($partnerMessages as $msg) {
                                $summary .= "  {$msg->player->name}: \"{$msg->content}\"\n";
                            }
                        }
                    }
                    $summary .= "Now make your final decision — name the player you believe is Merlin.\n";
                }
            }
            $summary .= "============================\n";
        }

        // In debrief, reveal all roles
        if ($game->current_phase === 'debrief') {
            $summary .= "\n=== ALL ROLES REVEALED ===\n";
            foreach ($game->players as $p) {
                $you = $p->id === $player->id ? ' (YOU)' : '';
                $summary .= "  {$p->name}: {$p->role}{$you}\n";
            }
            $summary .= "Winner: " . ($game->winner === 'good' ? 'Good team' : 'Evil team') . "\n";
        }

        $summary .= "=========================\n";

        // Reasoning instruction
        $summary .= "\nIMPORTANT: Base your reasoning on the GAME LOG and PLAYER TRACKER above. Cite specific evidence (e.g. \"Alex was on Mission 1 which failed\" or \"Jordan voted against 2 approved proposals\"). Do NOT make claims about players that aren't supported by the game record.\n";
        $summary .= "DEDUCTION SHORTCUT: If a mission had fail votes equal to its team size, EVERY player on that team is confirmed evil — there are no good players to defend. Exclude them from all future teams and vote NO on any proposal containing them.\n";
        $summary .= "COUNTER-PROPAGANDA: Evil players routinely attack good players by saying things like 'they approved a failed mission' to deflect suspicion. Approving a team that later failed is NOT evidence of evil — a good player has no way to know in advance who is evil. Judge players by their MISSIONS (were they personally on a failed mission?) and their VOTES AGAINST successful teams, not by whether they approved a proposal that happened to include an evil player.\n";

        // Add phase-specific action reminder
        $summary .= "\nACTION REQUIRED: ";
        switch ($game->current_phase) {
            case 'setup':
                $summary .= "Introduce yourself and share initial thoughts.\n";
                break;
            case 'team_proposal':
                if ($game->current_leader_id === $player->id) {
                    $summary .= "You are the leader. Propose a team of {$game->currentMission->required_players} players.\n";
                } else {
                    $leader = $game->players()->find($game->current_leader_id);
                    $leaderName = $leader ? $leader->name : 'the leader';
                    $summary .= "Wait for {$leaderName} to propose a team. You can discuss.\n";
                }
                break;
            case 'team_discussion':
                if ($game->current_leader_id === $player->id) {
                    $leader = $game->players()->find($game->current_leader_id);
                    $summary .= "You are the leader. Open discussion is happening before you propose a team — listen to what others say, then you will pick ".($game->currentMission->required_players ?? '?')." players.\n";
                } else {
                    $leader = $game->players()->find($game->current_leader_id);
                    $leaderName = $leader ? $leader->name : 'the leader';
                    $summary .= "Open discussion before {$leaderName} proposes a team. Argue for or against players, share suspicions, try to influence the pick. No vote yet.\n";
                }
                break;
            case 'team_voting':
                $hasVoted = $game->currentProposal->votes->where('player_id', $player->id)->isNotEmpty();
                if (!$hasVoted) {
                    $summary .= "Vote on the proposed team (you MUST include a vote: true/false in your response).\n";
                } else {
                    $summary .= "You have voted. Wait for others to vote. You can discuss.\n";
                }
                break;
            case 'mission':
                $onMission = $game->currentMission->teamMembers->where('player_id', $player->id)->isNotEmpty();
                if ($onMission) {
                    $hasActed = $game->currentMission->teamMembers->where('player_id', $player->id)->whereNotNull('vote_success')->isNotEmpty();
                    if (!$hasActed) {
                        $summary .= "You are on the mission. Include mission_action: true (success) or false (fail) in your response.\n";
                    } else {
                        $summary .= "You have completed your mission action. Wait for others.\n";
                    }
                } else {
                    $summary .= "The mission team is executing their mission. Discuss and observe.\n";
                }
                break;
            case 'evil_discussion':
                if (in_array($player->role, ['assassin', 'minion'])) {
                    $summary .= "Discuss with your evil partner who you think Merlin is. Share your best read on who Merlin might be — name a specific suspect and your reasoning. Speak in character as if puzzling it out publicly.\n";
                } else {
                    $summary .= "The evil team is conferring before the assassination. You may try to mislead them or stay quiet.\n";
                }
                break;
            case 'assassination':
                if ($player->role === 'assassin') {
                    $goodNames = $game->players->filter(fn ($p) => !in_array($p->role, ['assassin', 'minion']))->pluck('name')->values()->all();
                    $summary .= "Choose who you think is Merlin. You MUST set assassination_target to EXACTLY one of these names: " . implode(', ', $goodNames) . ". Pick the one most likely to be Merlin based on your reasoning.\n";
                } else {
                    $summary .= "The Assassin is choosing their target. You can try to mislead or stay quiet.\n";
                }
                break;
            case 'debrief':
                $summary .= "The game is over and all roles are now public. React to what happened — talk about what you did, what you thought, what fooled you, or what gave someone away. Speak in past tense about decisions already made. Do not describe future actions or intentions.\n";
                break;
        }

        return $summary;
    }

    /**
     * Build a chronological game log from GameEvent records, grouped by round (mission number).
     */
    private function buildGameLog(\Illuminate\Support\Collection $events): string
    {
        $log = '';
        $currentRound = 0;
        $proposalCounter = 0; // proposals within a round

        foreach ($events as $event) {
            $data = $event->event_data;
            if ($event->event_type === 'team_proposal') {
                $missionNumber = $this->getMissionNumberForProposal($event, $events);
                if ($missionNumber !== $currentRound) {
                    $currentRound = $missionNumber;
                    $proposalCounter = 0;
                    $log .= "Round {$currentRound}:\n";
                }
                $proposalCounter++;
                $team = implode(', ', $data['team'] ?? []);
                $log .= "  {$data['proposed_by']} proposed: {$team}\n";
            } elseif ($event->event_type === 'team_vote') {
                $status = $data['approved'] ? 'APPROVED' : 'REJECTED';
                $log .= "  Vote: {$data['votes_for']} approve / {$data['votes_against']} reject — {$status}\n";
                // Per-player breakdown
                if (!empty($data['breakdown'])) {
                    $voteDetails = [];
                    foreach ($data['breakdown'] as $vote) {
                        $symbol = $vote['approved'] ? '✓' : '✗';
                        $voteDetails[] = "{$symbol} {$vote['player']}";
                    }
                    $log .= "    " . implode(', ', $voteDetails) . "\n";
                }
            } elseif ($event->event_type === 'mission_complete') {
                $status = $data['success'] ? 'SUCCESS' : 'FAILED';
                $failInfo = $data['fail_votes'] > 0 ? " ({$data['fail_votes']} fail vote" . ($data['fail_votes'] > 1 ? 's' : '') . ")" : '';
                $team = implode(', ', $data['team'] ?? []);
                $log .= "  Mission {$data['mission_number']} — {$status}{$failInfo} — Team: {$team}\n\n";
            } elseif ($event->event_type === 'assassination') {
                $target = $data['assassin_target']['player_name'] ?? 'unknown';
                $role = $data['assassin_target']['player_role'] ?? 'unknown';
                $log .= "Assassination: targeted {$target} (was {$role})\n";
            }
        }

        return $log;
    }

    /**
     * Determine mission number for a proposal event by finding the next mission_complete event after it.
     */
    private function getMissionNumberForProposal(GameEvent $proposalEvent, \Illuminate\Support\Collection $events): int
    {
        // Find the next mission_complete event after this proposal
        $nextMission = $events->first(fn ($e) => $e->id > $proposalEvent->id && $e->event_type === 'mission_complete');
        if ($nextMission) {
            return $nextMission->event_data['mission_number'] ?? 1;
        }
        // No mission_complete yet — this is the current round
        // Count completed missions + 1
        $completedMissions = $events->where('event_type', 'mission_complete')->count();
        return $completedMissions + 1;
    }

    /**
     * Build per-player tracker: mission participation, proposals made, vote history.
     */
    private function buildPlayerTracker(Game $game, \Illuminate\Support\Collection $events): string
    {
        $players = $game->players->pluck('name')->toArray();
        $tracker = '';

        // Collect data per player
        $missionResults = []; // player => ['#1 SUCCESS', '#2 FAILED']
        $proposals = [];      // player => ['#1: A,B → REJECTED']
        $votes = [];          // player => ['#1a: ✓', '#1b: ✗']

        // Track proposal labels: mission_number + letter (a, b, c...)
        $currentMission = 0;
        $proposalLetterIndex = 0;
        $proposalLabels = []; // event_id => '1a', '1b', etc.

        // First pass: assign labels to proposals
        foreach ($events as $event) {
            if ($event->event_type === 'team_proposal') {
                $missionNum = $this->getMissionNumberForProposal($event, $events);
                if ($missionNum !== $currentMission) {
                    $currentMission = $missionNum;
                    $proposalLetterIndex = 0;
                }
                $letter = chr(ord('a') + $proposalLetterIndex);
                $proposalLabels[$event->id] = "{$missionNum}{$letter}";
                $proposalLetterIndex++;
            }
        }

        // Second pass: collect per-player data
        $lastProposalEventId = null;
        foreach ($events as $event) {
            $data = $event->event_data;

            if ($event->event_type === 'team_proposal') {
                $lastProposalEventId = $event->id;
                $proposer = $data['proposed_by'];
                $team = implode(',', $data['team'] ?? []);
                // Status will be filled in by the next team_vote event
                if (!isset($proposals[$proposer])) {
                    $proposals[$proposer] = [];
                }
                $label = $proposalLabels[$event->id] ?? '?';
                $proposals[$proposer][] = ['label' => $label, 'team' => $team, 'status' => 'pending', 'event_id' => $event->id];
            } elseif ($event->event_type === 'team_vote') {
                $status = $data['approved'] ? 'APPROVED' : 'REJECTED';
                // Update the last proposal's status for the proposer
                $proposer = $data['proposed_by'] ?? null;
                if ($proposer && isset($proposals[$proposer])) {
                    $lastIdx = count($proposals[$proposer]) - 1;
                    if ($lastIdx >= 0) {
                        $proposals[$proposer][$lastIdx]['status'] = $status;
                    }
                }

                // Record each player's vote
                $label = $lastProposalEventId ? ($proposalLabels[$lastProposalEventId] ?? '?') : '?';
                if (!empty($data['breakdown'])) {
                    foreach ($data['breakdown'] as $vote) {
                        $pName = $vote['player'];
                        if (!isset($votes[$pName])) {
                            $votes[$pName] = [];
                        }
                        $symbol = $vote['approved'] ? '✓' : '✗';
                        $votes[$pName][] = "#{$label}: {$symbol}";
                    }
                }
            } elseif ($event->event_type === 'mission_complete') {
                $status = $data['success'] ? 'SUCCESS' : 'FAILED';
                $missionNum = $data['mission_number'];
                foreach ($data['team'] ?? [] as $pName) {
                    if (!isset($missionResults[$pName])) {
                        $missionResults[$pName] = [];
                    }
                    $missionResults[$pName][] = "#{$missionNum} {$status}";
                }
            }
        }

        foreach ($players as $name) {
            $parts = [];

            // Missions
            $missions = $missionResults[$name] ?? [];
            $parts[] = 'Missions [' . (empty($missions) ? 'none' : implode(', ', $missions)) . ']';

            // Proposals
            $props = $proposals[$name] ?? [];
            if (empty($props)) {
                $parts[] = 'Proposed [none]';
            } else {
                $propStrs = [];
                foreach ($props as $p) {
                    $propStrs[] = "#{$p['label']}: {$p['team']} → {$p['status']}";
                }
                $parts[] = 'Proposed [' . implode('; ', $propStrs) . ']';
            }

            // Votes
            $playerVotes = $votes[$name] ?? [];
            $parts[] = 'Votes [' . (empty($playerVotes) ? 'none' : implode(', ', $playerVotes)) . ']';

            $tracker .= "{$name}: " . implode(' | ', $parts) . "\n";
        }

        return $tracker;
    }

    /**
     * Analyze vote patterns against mission outcomes to surface suspicious behavior.
     */
    private function buildVotePatternAnalysis(Game $game, \Illuminate\Support\Collection $events, ?Player $currentPlayer = null): string
    {
        $completedMissions = $events->where('event_type', 'mission_complete');
        if ($completedMissions->isEmpty()) {
            return '';
        }

        $analysis = '';

        // For each completed mission, find the approved proposal's vote breakdown
        foreach ($completedMissions as $missionEvent) {
            $missionNum = $missionEvent->event_data['mission_number'] ?? null;
            $missionSuccess = $missionEvent->event_data['success'] ?? null;
            $missionTeam = $missionEvent->event_data['team'] ?? [];
            if ($missionNum === null || $missionSuccess === null) {
                continue;
            }

            // Find the APPROVED team_vote event that preceded this mission_complete
            $approvedVote = $events
                ->where('event_type', 'team_vote')
                ->where('id', '<', $missionEvent->id)
                ->filter(fn ($e) => ($e->event_data['approved'] ?? false) === true)
                ->last();

            if (!$approvedVote || empty($approvedVote->event_data['breakdown'])) {
                continue;
            }

            $breakdown = $approvedVote->event_data['breakdown'];

            if ($missionSuccess) {
                // Mission SUCCEEDED — flag players who voted to REJECT the proposal
                $rejecters = collect($breakdown)
                    ->filter(fn ($v) => !$v['approved'])
                    ->pluck('player')
                    ->toArray();

                foreach ($rejecters as $name) {
                    $analysis .= "⚠️ {$name} voted to REJECT the team for Mission {$missionNum} which SUCCEEDED — why block a good team?\n";
                }
            } else {
                // Mission FAILED — flag players who voted to APPROVE the proposal
                // NOTE: approving a team that later failed is weak evidence — a good player has no way
                // to know in advance that an evil player is on a team. Evil players routinely exploit
                // this by loudly accusing good players of being suspicious for approving a failed team.
                // Do NOT treat "approved a failed mission" as strong evidence of evil by itself.
                $approvers = collect($breakdown)
                    ->filter(fn ($v) => $v['approved'])
                    ->pluck('player')
                    ->toArray();

                foreach ($approvers as $name) {
                    // Only flag non-team-members as suspicious approvers (team members might just trust themselves)
                    if (!in_array($name, $missionTeam)) {
                        $analysis .= "ℹ️ {$name} approved the team for Mission {$missionNum} which FAILED. This is weak evidence on its own — good players can't detect evil without information. Treat with caution.\n";
                    }
                }
            }
        }

        // Add mission deduction hints for failed missions
        $failedMissions = $completedMissions->filter(fn ($e) => ($e->event_data['success'] ?? true) === false);
        if ($failedMissions->isNotEmpty()) {
            $allPlayers = $game->players->pluck('name')->toArray();
            $successfulMissions = $completedMissions->filter(fn ($e) => ($e->event_data['success'] ?? false) === true);

            // Count how many failed missions each player appeared on
            $failedMissionCount = [];
            foreach ($allPlayers as $name) {
                $count = $failedMissions->filter(fn ($e) => in_array($name, $e->event_data['team'] ?? []))->count();
                if ($count > 0) {
                    $failedMissionCount[$name] = $count;
                }
            }

            // Players who were ONLY on successful missions are likely good
            $onlySuccessPlayers = [];
            $onFailedMission = [];
            foreach ($allPlayers as $name) {
                $wasOnFailed = $failedMissions->contains(fn ($e) => in_array($name, $e->event_data['team'] ?? []));
                $wasOnSuccess = $successfulMissions->contains(fn ($e) => in_array($name, $e->event_data['team'] ?? []));
                if ($wasOnFailed) {
                    $onFailedMission[] = $name;
                } elseif ($wasOnSuccess) {
                    $onlySuccessPlayers[] = $name;
                }
            }

            // Flag players on EVERY failed mission — very strong evil indicator
            $totalFailed = $failedMissions->count();
            if ($totalFailed >= 2) {
                foreach ($failedMissionCount as $name => $count) {
                    if ($name === $currentPlayer?->name) continue; // don't flag yourself
                    if ($count === $totalFailed) {
                        $analysis .= "🚨 HIGH SUSPICION: {$name} was on ALL {$totalFailed} failed missions. Very likely evil — exclude from future teams.\n";
                    } elseif ($count >= 2) {
                        $analysis .= "⚠️ SUSPICIOUS: {$name} appeared on {$count} of {$totalFailed} failed missions. Worth excluding unless you have strong reason to trust them.\n";
                    }
                }
            }

            if (!empty($onlySuccessPlayers)) {
                $analysis .= "✅ Players only on SUCCESSFUL missions (likely good): " . implode(', ', $onlySuccessPlayers) . "\n";
            }

            foreach ($failedMissions as $missionEvent) {
                $team = $missionEvent->event_data['team'] ?? [];
                $failVotes = $missionEvent->event_data['fail_votes'] ?? 0;
                $missionNum = $missionEvent->event_data['mission_number'] ?? '?';
                $goodOnTeam = count($team) - $failVotes;
                if ($failVotes === count($team)) {
                    // ALL votes were fail — every player on this team is confirmed evil
                    $analysis .= "🚨 CONFIRMED EVIL: Mission {$missionNum} had {$failVotes} fail vote(s) for ALL " . count($team) . " players (" . implode(', ', $team) . "). Every player on this mission sabotaged it — they are ALL evil. Exclude them from all future missions and vote down any proposal that includes them.\n";
                } elseif ($goodOnTeam > 0) {
                    $analysis .= "📊 Mission {$missionNum} had {$failVotes} fail vote(s) among " . count($team) . " players (" . implode(', ', $team) . ") — {$goodOnTeam} of them are still GOOD. Not everyone on this team is evil.\n";
                }
            }
        }

        return $analysis;
    }

    public function checkPhaseTransition(Game $game): void
    {
        $needsTransition = match ($game->current_phase) {
            'setup' => $this->shouldTransitionFromSetup($game),
            'team_proposal' => $this->shouldTransitionFromTeamProposal($game),
            'team_discussion' => $this->shouldTransitionFromTeamDiscussion($game),
            'team_voting' => $this->shouldTransitionFromTeamVoting($game),
            'mission' => $this->shouldTransitionFromMission($game),
            'evil_discussion' => $this->shouldTransitionFromEvilDiscussion($game),
            'assassination' => $game->gameEvents()->where('event_type', 'assassination')->exists(),
            'debrief' => $this->shouldTransitionFromDebrief($game),
            default => false
        };

        if ($needsTransition) {
            $this->transitionToNextPhase($game);
        }
    }

    public function processPlayerVote(Game $game, Player $player, bool $approved): void
    {
        if (! $game->currentProposal) {
            return;
        }

        // Check if player has already voted
        $existingVote = MissionProposalVote::where('proposal_id', $game->currentProposal->id)
            ->where('player_id', $player->id)
            ->first();
            
        if ($existingVote) {
            return;
        }

        MissionProposalVote::create([
            'proposal_id' => $game->currentProposal->id,
            'player_id' => $player->id,
            'approved' => $approved,
        ]);
    }

    public function shouldTransitionFromSetup(Game $game): bool
    {
        // Transition when all AI players have sent their initial messages.
        // The human player is not required to speak before the game begins.
        $aiPlayerCount = $game->players()->where('is_human', false)->count();
        $aiMessageCount = $game->messages()
            ->where('message_type', 'public_chat')
            ->whereHas('player', fn ($q) => $q->where('is_human', false))
            ->distinct('player_id')
            ->count();

        return $aiMessageCount >= $aiPlayerCount;
    }

    public function shouldTransitionFromTeamProposal(Game $game): bool
    {
        // Transition if there's a current proposal
        return $game->current_proposal_id !== null;
    }

    private function shouldTransitionFromTeamDiscussion(Game $game): bool
    {
        // Transition early once every AI player has spoken once this phase.
        $phaseStartEvent = $game->gameEvents()
            ->where('event_type', 'phase_start')
            ->where('event_data->phase', 'team_discussion')
            ->latest()->first();
        $since = $phaseStartEvent?->created_at ?? $game->started_at;

        $aiCount = $game->players()->where('is_human', false)->count();
        $aiSpoken = $game->messages()
            ->where('message_type', 'public_chat')
            ->where('created_at', '>=', $since)
            ->whereHas('player', fn ($q) => $q->where('is_human', false))
            ->distinct('player_id')
            ->count('player_id');

        return $aiSpoken >= $aiCount;
    }

    public function shouldTransitionFromTeamVoting(Game $game): bool
    {
        if (! $game->currentProposal) {
            return false;
        }

        // Transition if all players have voted on the current proposal
        $totalVotes = $game->currentProposal->votes()->count();
        $totalPlayers = $game->players()->count();

        return $totalVotes >= $totalPlayers;
    }

    public function shouldTransitionFromMission(Game $game): bool
    {
        if (! $game->currentMission) {
            return false;
        }

        // Transition if all team members have submitted their mission votes
        $teamSize = $game->currentMission->teamMembers()->count();
        $submittedVotes = $game->currentMission->teamMembers()
            ->whereNotNull('vote_success')
            ->count();

        return $teamSize > 0 && $submittedVotes >= $teamSize;
    }

    private function shouldTransitionFromEvilDiscussion(Game $game): bool
    {
        // Transition once all evil AI players have spoken at least once since the phase began
        $phaseStartEvent = $game->gameEvents()
            ->where('event_type', 'phase_start')
            ->where('event_data->phase', 'evil_discussion')
            ->latest()
            ->first();
        $since = $phaseStartEvent?->created_at ?? $game->started_at;

        $evilAiCount = $game->players()
            ->whereIn('role', ['assassin', 'minion'])
            ->where('is_human', false)
            ->count();

        $evilAiSpoken = $game->messages()
            ->where('message_type', 'public_chat')
            ->where('created_at', '>=', $since)
            ->whereHas('player', fn ($q) => $q->whereIn('role', ['assassin', 'minion'])->where('is_human', false))
            ->distinct('player_id')
            ->count();

        return $evilAiSpoken >= $evilAiCount;
    }

    private function shouldTransitionFromDebrief(Game $game): bool
    {
        return false; // Debrief runs indefinitely — players can keep chatting after the game
    }

    public function transitionToNextPhase(Game $game): void
    {
        $previousPhase = $game->current_phase;

        // Determine next phase
        $game->current_phase = match ($previousPhase) {
            'setup' => 'team_proposal', // Skip discussion on the very first proposal — no game history yet
            'mission' => 'team_discussion',
            'team_discussion' => 'team_proposal',
            'team_proposal' => 'team_voting',
            'team_voting' => $this->determineNextPhaseAfterVoting($game),
            'evil_discussion' => 'assassination',
            'assassination' => 'debrief',
            'debrief' => 'finished',
            default => $game->current_phase
        };

        if ($previousPhase === 'team_voting') {
            $game->current_proposal_id = null;
            $totalVotes = $game->currentProposal->votes()->count();
            $playerCount = $game->players()->count(); // All players vote, including the leader

            if ($totalVotes >= $playerCount) {
                $allVotes = $game->currentProposal->votes()->with('player')->get();
                $approvalVotes = $allVotes->where('approved', true)->count();
                $rejectionVotes = $allVotes->where('approved', false)->count();
                $voteApproved = $approvalVotes > ($playerCount / 2); // Strict majority required

                if ($voteApproved) {
                    // If approved, move team members to mission (idempotent)
                    foreach ($game->currentProposal->teamMembers as $member) {
                        MissionTeamMember::firstOrCreate([
                            'mission_id' => $game->currentMission->id,
                            'player_id' => $member->player_id,
                        ]);
                    }
                    $game->current_phase = 'mission';

                    $proposal = $game->currentProposal;
                    $proposal->status = 'approved';
                    $proposal->save();
                } else {
                    $proposal = $game->currentProposal;
                    $proposal->status = 'rejected';
                    $proposal->save();
                }

                $game->current_proposal_id = null;
                $game->save();

                GameEvent::create([
                    'game_id' => $game->id,
                    'event_type' => 'team_vote',
                    'event_data' => [
                        'approved' => $voteApproved,
                        'votes_for' => $approvalVotes,
                        'votes_against' => $rejectionVotes,
                        'proposed_by' => $proposal->proposedBy->name,
                        'breakdown' => $allVotes->map(fn ($v) => [
                            'player' => $v->player->name,
                            'approved' => (bool) $v->approved,
                        ])->values()->toArray(),
                    ],
                ]);

                broadcast(new GameStateUpdate($game));

                if (!$voteApproved) {
                    // If there are 5 rejected proposals in a row, the game ends
                    $rejectedProposals = $game->currentMission->proposals()->where('status', 'rejected')->count();
                    if ($rejectedProposals >= 5) {
                        $this->endGame($game, 'evil', '5 proposals rejected');

                        return;
                    }
                    // Move to next leader for new proposal
                    $game->current_phase = 'team_proposal';
                    $game->current_leader_id = $this->getNextLeader($game);
                    $game->save();
                }
            }
        } else {
            if ($previousPhase === 'debrief') {
                $this->concludeGame($game);
                return;
            }

            if ($previousPhase === 'assassination') {
                $assassination_event = $game->gameEvents()->where('event_type', 'assassination')->first();
                if (!$assassination_event) {
                    Log::error('Assassination transition with no event', ['game_id' => $game->id]);
                    return;
                }
                $assassin_target = $assassination_event->event_data['assassin_target']['player_id'];
                $merlin = $game->players()->where('role', 'merlin')->first();

                if ($assassin_target === $merlin->id) {
                    $this->endGame($game, 'evil', 'Merlin was assassinated');
                } else {
                    $this->endGame($game, 'good', 'Assassin missed Merlin');
                }

                return;
            }

            if ($previousPhase === 'evil_discussion') {
                // Hand off to the assassin for the final decision
                $assassin = $game->players()->where('role', 'assassin')->first();
                if ($assassin) {
                    $game->current_leader_id = $assassin->id;
                    $game->save();
                }
            }

            if ($previousPhase === 'team_proposal') {
                $proposalid = $game->current_proposal_id;
                $proposal = MissionProposal::find($proposalid); // Get the current proposal directly, don't use relation because it's out of date.
                // Game proposal is already set. But now we changed game state.
                assert($proposal !== null);
                assert($proposal->status === 'pending');
            } elseif ($previousPhase === 'mission' || $previousPhase === 'setup') {
                // We're transitioning after a mission, set up the next mission
                if ($previousPhase === 'mission') {
                    $currentMission = $game->currentMission;
                    $requiredVotes = $currentMission->teamMembers()->count();

                    $failVotes = $currentMission->teamMembers()->where('vote_success', false)->count();
                    $missionSucceeded = $failVotes === 0; // If there are any fail votes, the mission fails.

                    $currentMission->status = $missionSucceeded ? 'success' : 'fail';
                    $currentMission->success_votes = $requiredVotes - $failVotes;
                    $currentMission->fail_votes = $failVotes;
                    $currentMission->save();

                    GameEvent::create([
                        'game_id' => $game->id,
                        'event_type' => 'mission_complete',
                        'event_data' => [
                            'mission_number' => $currentMission->mission_number,
                            'success' => $missionSucceeded,
                            'fail_votes' => $failVotes,
                            'team' => $currentMission->teamMembers->pluck('player.name')->values()->toArray(),
                            'breakdown' => $currentMission->teamMembers->map(fn ($m) => [
                                'player' => $m->player->name,
                                'success' => (bool) $m->vote_success,
                            ])->values()->toArray(),
                        ],
                    ]);

                    // Check if game should end
                    $successfulMissions = $game->missions()->where('status', 'success')->count();

                    if ($successfulMissions >= 3) {
                        $game->current_phase = 'evil_discussion';
                        $game->save();

                        // Log phase_start so we can anchor the discussion window
                        GameEvent::create([
                            'game_id' => $game->id,
                            'event_type' => 'phase_start',
                            'event_data' => ['phase' => 'evil_discussion'],
                        ]);
                    }
                }

                $failedMissions = $game->missions()->where('status', 'fail')->count();
                $successfulMissions = $game->missions()->where('status', 'success')->count();

                if ($failedMissions < 3 && $successfulMissions < 3) {
                    // Move to team_discussion before the leader proposes
                    $game->current_phase = 'team_discussion';
                    $game->current_leader_id = $this->getNextLeader($game);
                    $game->current_mission_id = $game->missions()
                        ->where('status', 'pending')
                        ->orderBy('mission_number')
                        ->first()
                        ->id;
                    $game->save();

                    // Log phase_start so the 30s discussion window is anchored and eligible-player
                    // tracking knows when this discussion began.
                    GameEvent::create([
                        'game_id' => $game->id,
                        'event_type' => 'phase_start',
                        'event_data' => ['phase' => 'team_discussion'],
                    ]);
                }
            }
        }
        $game->save();

        $failedMissions = $game->missions()->where('status', 'fail')->count();

        // Add private thoughts for context and instructions
        foreach ($game->players()->where('is_human', false)->get() as $player) {
            $contextMessage = $this->generatePhaseTransitionContext($previousPhase, $game, $player);
            $instructionsMessage = null; // No instructions for next phase if the game is over.
            if ($failedMissions < 3) {
                $instructionsMessage = $this->generateNextPhaseInstructions($game, $player);
            }

            if ($contextMessage) {
                Message::create([
                    'game_id' => $game->id,
                    'player_id' => $player->id,
                    'message_type' => 'game_event',
                    'content' => $contextMessage,
                ]);
            }

            if ($instructionsMessage) {
                Message::create([
                    'game_id' => $game->id,
                    'player_id' => $player->id,
                    'message_type' => 'game_event',
                    'content' => $instructionsMessage,
                ]);
            }
        }

        $gamePhaseLabel = match ($game->current_phase) {
            'team_proposal' => 'Team Proposal',
            'team_discussion' => 'Team Discussion',
            'team_voting' => 'Team Voting',
            'mission' => 'Mission',
            'evil_discussion' => 'Evil Discussion',
            'assassination' => 'Assassination',
            default => 'Unknown'
        };

        // Add a system message public_chat with what just happened.
        $publicmessage = Message::create([
            'game_id' => $game->id,
            'player_id' => null,
            'message_type' => 'public_chat',
            'content' => $gamePhaseLabel.' has begun.',
        ]);

        broadcast(new NewMessage($publicmessage));

        if (isset($failedMissions) && $failedMissions >= 3) {
            $this->endGame($game, 'evil', '3 missions failed');

            return;
        }
        broadcast(new GameStateUpdate($game));
    }

    public function determineNextPhaseAfterVoting(Game $game): string
    {
        if (! $game->currentProposal) {
            return 'team_proposal';
        }

        $approvedVotes = $game->currentProposal->votes()
            ->where('approved', true)
            ->count();

        $totalVotes = $game->currentProposal->votes()->count();

        // Tied or rejected vote: If the vote is tied or rejected, the leader passes the turn clockwise and the team building phase is repeated.
        return ($approvedVotes > ($totalVotes / 2)) ? 'mission' : 'team_proposal';
    }

    public function getNextLeader(Game $game): int
    {
        $playerCount = $game->players()->count();

        if (! $game->current_leader_id) {
            $firstPlayer = $game->players()->orderBy('player_index')->first();

            return $firstPlayer ? $firstPlayer->id : 1;
        }

        $currentLeader = $game->players()->find($game->current_leader_id);
        $nextIndex = ($currentLeader->player_index + 1) % $playerCount;

        return $game->players()
            ->where('player_index', $nextIndex)
            ->first()
            ->id;
    }

    public function generatePhaseTransitionContext(string $previousPhase, Game $game, Player $player): ?string
    {
        if ($previousPhase === 'mission') {
            $completedMission = $game->missions()->whereIn('status', ['success', 'fail'])->latest('mission_number')->first();
            $numberOfFailedVotes = $completedMission->teamMembers()->where('vote_success', false)->count();

            return 'Mission '.$completedMission->mission_number.' '.($completedMission->status === 'success' ? 'succeeded' : 'failed with '.$numberOfFailedVotes.' failed vote'.($numberOfFailedVotes === 1 ? '' : 's').'. ');
        }

        return match ($previousPhase) {
            'setup' => 'Initial introductions are complete. Open discussion begins — talk strategy, share suspicions, and try to influence who the leader picks for the mission.',
            'mission' => null, // handled above by the mission-specific block
            'team_discussion' => 'Discussion is over. The leader will now propose a team.',
            'team_proposal' => 'A team has been proposed. It is now time to vote.',
            'team_voting' => $game->currentProposal ?
                'The vote '.($game->currentProposal->status === 'approved' ? 'passed' : 'failed').'. '.
                ($game->currentProposal->status === 'approved'
                    ? 'The proposed team will now go on the mission.'
                    : 'Moving to the next leader for a new team proposal.')
                : 'Voting phase has concluded.',
            default => null
        };
    }

    public function generateNextPhaseInstructions(Game $game, Player $player): ?string
    {
        $game->refresh();
        $isLeader = $game->current_leader_id === $player->id;

        return match ($game->current_phase) {
            'team_discussion' => $isLeader
                ? 'Open discussion before you propose a team. Listen to what others say — they\'re trying to influence your pick. You must propose '.
                $game->currentMission->required_players.' players for Mission '.$game->currentMission->mission_number.'.'
                : 'Open discussion before the leader proposes a team. '.$game->currentLeader->name.' will make the final pick — try to influence their decision. Speak up about who you trust or suspect.',

            'team_proposal' => $isLeader
                ? 'Discussion is over. You are the leader — propose a team of '.
                $game->currentMission->required_players.' players for the mission now.'
                : $game->currentLeader->name.' will now propose a team for the mission.',

            'team_voting' => 'You need to vote on the proposed team: '.
                $game->currentProposal->teamMembers->map(fn ($tm) => $tm->player->name)->join(', ').
                '. Consider if you trust these players to complete the mission successfully.',

            'mission' => $game->currentMission->teamMembers()
                ->where('player_id', $player->id)
                ->exists()
                ? 'You are on the mission team. '.($player->role === 'loyal_servant' || $player->role === 'merlin'
                    ? 'As a good player, you should support the mission.'
                    : 'As an evil player, you can choose to sabotage the mission.')
                : 'The mission team will now attempt to complete their mission.',

            'evil_discussion' => match (true) {
                in_array($player->role, ['assassin', 'minion']) => 'Good has won 3 missions. Before the Assassin strikes, confer with your evil partner — share who you think Merlin is and why. The Assassin will make the final call.',
                $player->role === 'merlin' => 'Good has won 3 missions. The evil team is now conferring before the assassination attempt. Stay calm and do not give yourself away.',
                default => 'Good has won 3 missions. The evil team is conferring before the assassination attempt.',
            },

            'assassination' => match ($player->role) {
                'assassin' => 'The good team has won 3 missions. As the Assassin, you must now identify Merlin',
                'merlin' => 'The Assassination phase has begun. The Assassin will try to identify you',
                default => 'The Assassination phase has begun. The Assassin will try to identify Merlin'
            },

            default => null
        };
    }

    public static function isRunning(): bool
    {
        return DB::table('jobs')
            ->whereRaw('json_extract(payload, "$.data.commandName") = ?', ['App\Jobs\GameLoop'])
            ->exists();
    }
    
    private function hasPhaseTimedOut(Game $game): bool
    {
        if (!isset($this->phaseTimeouts[$game->current_phase])) {
            return false;
        }

        // Phases with a dedicated phase_start event use that as their clock start.
        if (in_array($game->current_phase, ['team_discussion', 'evil_discussion'])) {
            $phaseStartEvent = GameEvent::where('game_id', $game->id)
                ->where('event_type', 'phase_start')
                ->where('event_data->phase', $game->current_phase)
                ->latest()
                ->first();
            $startTime = $phaseStartEvent?->created_at ?? $game->started_at;
        } else {
            // For other phases, anchor off the last major transition event
            $lastTransition = GameEvent::where('game_id', $game->id)
                ->whereIn('event_type', ['game_start', 'team_vote', 'mission_complete'])
                ->orderBy('created_at', 'desc')
                ->first();
            $startTime = $lastTransition ? $lastTransition->created_at : $game->started_at;
        }

        $timeoutSeconds = $this->phaseTimeouts[$game->current_phase];
        $elapsedSeconds = $startTime->diffInSeconds(now());

        return $elapsedSeconds > $timeoutSeconds;
    }
    
    public function forcePhaseTransition(Game $game): void
    {
        Log::info("Forcing phase transition for game {$game->id} in phase {$game->current_phase}");
        
        switch ($game->current_phase) {
            case 'setup':
                // Force any players who haven't introduced themselves
                $silentPlayers = $game->players()
                    ->whereDoesntHave('messages', function ($query) {
                        $query->where('message_type', 'public_chat');
                    })
                    ->get();
                    
                foreach ($silentPlayers as $player) {
                    Message::create([
                        'game_id' => $game->id,
                        'player_id' => $player->id,
                        'message_type' => 'public_chat',
                        'content' => "Hi everyone! [Timed out]",
                    ]);
                }
                break;
                
            case 'team_proposal':
                // Force a random team proposal
                if (!$game->current_proposal_id && $game->current_leader_id) {
                    $requiredPlayers = $game->currentMission->required_players;
                    $selectedPlayers = $game->players()
                        ->inRandomOrder()
                        ->take($requiredPlayers)
                        ->get();
                        
                    $proposal = MissionProposal::create([
                        'game_id' => $game->id,
                        'mission_id' => $game->currentMission->id,
                        'proposed_by_id' => $game->current_leader_id,
                        'proposal_number' => ($game->currentMission->proposals()->max('proposal_number') ?? 0) + 1,
                        'status' => 'pending',
                    ]);

                    foreach ($selectedPlayers as $player) {
                        MissionProposalMember::create([
                            'proposal_id' => $proposal->id,
                            'player_id' => $player->id,
                        ]);
                    }
                    
                    $game->current_proposal_id = $proposal->id;
                    $game->save();
                    
                    Message::create([
                        'game_id' => $game->id,
                        'player_id' => $game->current_leader_id,
                        'message_type' => 'public_chat',
                        'content' => "Time's up! I'll propose: " . $selectedPlayers->pluck('name')->join(', '),
                    ]);
                }
                break;
                
            case 'team_discussion':
                // Timeout: advance to voting without forcing messages
                $this->transitionToNextPhase($game);
                break;

            case 'team_voting':
                // Force remaining votes as rejections (leader already auto-voted on proposal, so
                // whereDoesntHave naturally skips them — no manual exclusion needed)
                if ($game->currentProposal) {
                    $nonVoters = $game->players()
                        ->whereDoesntHave('proposalVotes', function ($query) use ($game) {
                            $query->where('proposal_id', $game->current_proposal_id);
                        })
                        ->get();
                        
                    foreach ($nonVoters as $player) {
                        MissionProposalVote::create([
                            'proposal_id' => $game->current_proposal_id,
                            'player_id' => $player->id,
                            'approved' => false, // Default to rejection
                        ]);
                        
                        Message::create([
                            'game_id' => $game->id,
                            'player_id' => $player->id,
                            'message_type' => 'public_chat',
                            'content' => "I vote no. [Timed out]",
                        ]);
                    }
                }
                break;
                
            case 'mission':
                // Force mission actions
                if ($game->currentMission) {
                    $pendingMembers = $game->currentMission->teamMembers()
                        ->whereNull('vote_success')
                        ->get();
                        
                    foreach ($pendingMembers as $member) {
                        // Good players must succeed, evil players randomly choose
                        $player = $member->player;
                        $mustSucceed = in_array($player->role, ['loyal_servant', 'merlin']);
                        $vote = $mustSucceed ? true : (bool)random_int(0, 1);
                        
                        $member->update(['vote_success' => $vote]);
                    }
                }
                break;
                
            case 'evil_discussion':
                // Force any evil AI players who haven't spoken to emit a placeholder message
                $phaseStartEvent = $game->gameEvents()
                    ->where('event_type', 'phase_start')
                    ->where('event_data->phase', 'evil_discussion')
                    ->latest()
                    ->first();
                $since = $phaseStartEvent?->created_at ?? $game->started_at;

                $silentEvil = $game->players()
                    ->whereIn('role', ['assassin', 'minion'])
                    ->where('is_human', false)
                    ->whereDoesntHave('messages', function ($q) use ($since) {
                        $q->where('message_type', 'public_chat')->where('created_at', '>', $since);
                    })
                    ->get();

                foreach ($silentEvil as $evilPlayer) {
                    Message::create([
                        'game_id' => $game->id,
                        'player_id' => $evilPlayer->id,
                        'message_type' => 'public_chat',
                        'content' => "Hard to say... could be anyone. [Timed out]",
                    ]);
                }
                break;

            case 'assassination':
                // Force random assassination
                $assassin = $game->players()->where('role', 'assassin')->first();
                if ($assassin) {
                    $target = $game->players()
                        ->whereNotIn('role', ['assassin', 'minion'])
                        ->inRandomOrder()
                        ->first();
                        
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
                    
                    Message::create([
                        'game_id' => $game->id,
                        'player_id' => $assassin->id,
                        'message_type' => 'public_chat',
                        'content' => "Time's up! I'll guess... {$target->name} is Merlin!",
                    ]);
                }
                break;
        }
        
        // Log the forced transition as a game_event message
        Message::create([
            'game_id' => $game->id,
            'player_id' => null,
            'message_type' => 'game_event',
            'content' => "Phase timeout: {$game->current_phase} phase timed out and was forced to progress.",
        ]);
    }
}
