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
        'setup' => 300,         // 5 minutes for initial introductions
        'team_proposal' => 180, // 3 minutes to propose a team
        'team_voting' => 120,   // 2 minutes for everyone to vote
        'mission' => 120,       // 2 minutes for mission execution
        'assassination' => 180, // 3 minutes for assassination
    ];

    public function handle(): void
    {
        $game = Game::with(['players', 'messages', 'gameEvents', 'currentMission', 'currentProposal'])->find($this->gameId);

        if (! $game || $game->ended_at !== null) {
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

        // Process turns for all eligible AI players in this job
        $aiTurnsProcessed = 0;
        foreach ($eligiblePlayers as $player) {
            if (! $player->is_human) {
                $this->processAIPlayerTurn($game, $player);
                $aiTurnsProcessed++;
            }
        }

        // Refresh game state before phase check — human API calls may have already transitioned
        $game = $game->fresh(['players', 'messages', 'gameEvents', 'currentMission', 'currentMission.teamMembers', 'currentProposal', 'currentProposal.teamMembers', 'currentProposal.votes', 'missions']);

        // Check if we need to transition to a new phase
        $this->checkPhaseTransition($game);

        // When waiting for human input (no AI work done), use a poll interval to avoid queue flooding.
        // Otherwise use the configured thinking time.
        $thinkingMs = random_int($this->minThinkingTime, $this->maxThinkingTime) * 1000;
        $delayMs = ($aiTurnsProcessed === 0) ? max(200, $thinkingMs) : $thinkingMs;

        self::dispatch($this->gameId)->delay(now()->addMilliseconds($delayMs));
    }

    public function endGame(Game $game, string $winner): void
    {
        $game->update([
            'ended_at' => now(),
            'current_phase' => 'finished',
            'winner' => $winner,
        ]);

        GameEvent::create([
            'game_id' => $game->id,
            'event_type' => 'game_end',
            'event_data' => ['winner' => $winner],
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

            'team_voting' => $game->players()
                ->where('id', '!=', $game->current_leader_id)
                ->whereDoesntHave('proposalVotes', function ($query) use ($game) {
                    $query->where('proposal_id', $game->current_proposal_id);
                })
                ->get()
                ->all(),

            'mission' => $game->currentMission
                ->teamMembers()
                ->whereNull('vote_success')
                ->with('player')
                ->get()
                ->pluck('player')
                ->all(),

            'assassination' => $game->players()->where('role', 'assassin')->get()->all(),

            default => []
        };
    }

    public function processAIPlayerTurn(Game $game, Player $player): void
    {
        $messages = $this->prepareAIContext($game, $player);
        $response = Agent::getChatResponse($messages);

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
        if ($game->current_phase === 'team_proposal' && isset($response['team_proposal']) && $game->current_leader_id === $player->id) {
            $proposal = MissionProposal::create([
                'game_id' => $game->id,
                'mission_id' => $game->currentMission->id,
                'proposed_by_id' => $player->id,
                'proposal_number' => $game->currentMission->proposals()->max('proposal_number') + 1 ?? 1,
                'status' => 'pending',
            ]);

            // Add team members to proposal
            $proposedPlayers = collect(explode(',', $response['team_proposal']))
                ->map(fn ($name) => $game->players()->where('name', trim($name))->first())
                ->filter();

            foreach ($proposedPlayers as $proposedPlayer) {
                MissionProposalMember::create([
                    'proposal_id' => $proposal->id,
                    'player_id' => $proposedPlayer->id,
                ]);
            }

            $game->current_proposal_id = $proposal->id;
            $game->save();

            GameEvent::create([
                'game_id' => $game->id,
                'event_type' => 'team_proposal',
                'event_data' => [
                    'proposed_by' => $player->name,
                    'team' => $proposedPlayers->pluck('name')->values()->toArray(),
                    'proposal_number' => $proposal->proposal_number,
                ],
            ]);
        }

        // Handle assassination
        if ($game->current_phase === 'assassination' && isset($response['assassination_target'])) {
            $targetPlayer = $game->players()->where('name', $response['assassination_target'])->first();
            if (!$targetPlayer) {
                Log::error('Assassination target not found', ['target' => $response['assassination_target'], 'game_id' => $game->id]);
                return;
            }

            GameEvent::create([
                'game_id' => $game->id,
                'event_type' => 'assassination',
                'event_data' => [
                    'assassin_target' => [
                        'player_name' => $response['assassination_target'],
                        'player_id' => $targetPlayer->id,
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
        
        // Get recent conversation history (last 20 messages)
        $recentMessages = $game->messages()
            ->whereIn('message_type', ['public_chat', 'game_event'])
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(function ($msg) use ($player) {
                if ($msg->message_type === 'public_chat') {
                    $playerName = $msg->player?->name ?? 'System';
                    return [
                        'role' => $msg->player_id === $player->id ? 'assistant' : 'user',
                        'content' => "{$playerName}: {$msg->content}",
                    ];
                }
                
                if ($msg->message_type === 'game_event' && $msg->player_id === $player->id) {
                    return [
                        'role' => 'system',
                        'content' => $msg->content,
                    ];
                }
                
                return null;
            })
            ->filter()
            ->values();
            
        foreach ($recentMessages as $msg) {
            $messages[] = $msg;
        }
        
        return $messages;
    }
    
    private function generateGameStateSummary(Game $game, Player $player): string
    {
        $summary = "=== CURRENT GAME STATE ===\n";
        $summary .= "Phase: {$game->current_phase}\n";
        
        // Mission status
        $successfulMissions = $game->missions()->where('status', 'success')->count();
        $failedMissions = $game->missions()->where('status', 'fail')->count();
        $summary .= "Missions: {$successfulMissions} successful, {$failedMissions} failed\n";
        
        // Current mission details
        if ($game->currentMission) {
            $summary .= "Current Mission: #{$game->currentMission->mission_number} (requires {$game->currentMission->required_players} players)\n";
            
            // Proposal history for current mission
            $proposals = $game->currentMission->proposals()->orderBy('proposal_number')->get();
            if ($proposals->isNotEmpty()) {
                $summary .= "Proposals this mission: {$proposals->count()}\n";
                foreach ($proposals as $proposal) {
                    if ($proposal->status !== 'pending') {
                        $summary .= "  - Proposal #{$proposal->proposal_number} by {$proposal->proposedBy->name}: {$proposal->status}\n";
                    }
                }
            }
        }
        
        // Current leader
        if ($game->current_leader_id) {
            $isLeader = $game->current_leader_id === $player->id;
            $leader = $game->players()->find($game->current_leader_id);
            if ($leader) {
                $summary .= "Current Leader: {$leader->name}" . ($isLeader ? " (YOU)" : "") . "\n";
            }
        }
        
        // Current proposal details if in voting phase
        if ($game->current_phase === 'team_voting' && $game->currentProposal) {
            $proposedTeam = $game->currentProposal->teamMembers->pluck('player.name')->join(', ');
            $summary .= "Proposed Team: {$proposedTeam}\n";
            
            // Who has voted
            $votedPlayers = $game->currentProposal->votes->pluck('player.name')->join(', ');
            if ($votedPlayers) {
                $summary .= "Already Voted: {$votedPlayers}\n";
            }
        }
        
        // Mission team if in mission phase
        if ($game->current_phase === 'mission' && $game->currentMission) {
            $missionTeam = $game->currentMission->teamMembers->pluck('player.name')->join(', ');
            $summary .= "Mission Team: {$missionTeam}\n";
            
            $onMission = $game->currentMission->teamMembers->where('player_id', $player->id)->isNotEmpty();
            if ($onMission) {
                $summary .= "You are ON this mission team.\n";
            }
        }
        
        // Previous mission results
        $completedMissions = $game->missions()->whereIn('status', ['success', 'fail'])->orderBy('mission_number')->get();
        if ($completedMissions->isNotEmpty()) {
            $summary .= "\nPrevious Missions:\n";
            foreach ($completedMissions as $mission) {
                $teamMembers = $mission->teamMembers->pluck('player.name')->join(', ');
                $result = $mission->status === 'success' ? 'SUCCESS' : "FAILED ({$mission->fail_votes} fail votes)";
                $summary .= "  Mission #{$mission->mission_number}: {$result} - Team: {$teamMembers}\n";
            }
        }
        
        $summary .= "=========================\n";
        
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
            case 'team_voting':
                $hasVoted = $game->currentProposal->votes->where('player_id', $player->id)->isNotEmpty();
                if (!$hasVoted && $game->current_leader_id !== $player->id) {
                    $summary .= "Vote on the proposed team (you MUST include a vote: true/false in your response).\n";
                } else {
                    $summary .= "Wait for others to vote. You can discuss.\n";
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
            case 'assassination':
                if ($player->role === 'assassin') {
                    $summary .= "Choose who you think is Merlin (assassination_target: player_name).\n";
                } else {
                    $summary .= "The Assassin is choosing their target. You can try to mislead or stay quiet.\n";
                }
                break;
        }
        
        return $summary;
    }

    public function checkPhaseTransition(Game $game): void
    {
        $needsTransition = match ($game->current_phase) {
            'setup' => $this->shouldTransitionFromSetup($game),
            'team_proposal' => $this->shouldTransitionFromTeamProposal($game),
            'team_voting' => $this->shouldTransitionFromTeamVoting($game),
            'mission' => $this->shouldTransitionFromMission($game),
            'assassination' => $game->gameEvents()->where('event_type', 'assassination')->exists(),
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

    public function shouldTransitionFromTeamVoting(Game $game): bool
    {
        if (! $game->currentProposal) {
            return false;
        }

        // Transition if all players have voted on the current proposal
        $totalVotes = $game->currentProposal->votes()->count();
        $totalPlayers = $game->players()->count();

        return $totalVotes >= ($totalPlayers - 1);
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

    public function transitionToNextPhase(Game $game): void
    {
        $previousPhase = $game->current_phase;

        // Determine next phase
        $game->current_phase = match ($previousPhase) {
            'setup', 'mission' => 'team_proposal',
            'team_proposal' => 'team_voting',
            'team_voting' => $this->determineNextPhaseAfterVoting($game),
            'assassination' => 'finished',
            default => $game->current_phase
        };

        if ($previousPhase === 'team_voting') {
            $game->current_proposal_id = null;
            $totalVotes = $game->currentProposal->votes()->count();
            $requiredVotes = $game->players()->count() - 1; // Everyone must vote on who goes on the mission, except the leader

            if ($totalVotes >= $requiredVotes) {
                $approvalVotes = $game->currentProposal->votes()->where('approved', true)->count();
                $requiredVotes = (($game->players()->count() - 1) / 2) + 1; // Majority vote required
                $voteApproved = $approvalVotes >= $requiredVotes;

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

                    // If there are 5 rejected proposals in a row, the game ends
                    $rejectedProposals = $game->currentMission->proposals()->where('status', 'rejected')->count();
                    if ($rejectedProposals >= 5) {
                        $this->endGame($game, 'evil');

                        return;
                    }
                    // Move to next leader for new proposal
                    $game->current_phase = 'team_proposal';
                    $game->current_leader_id = $this->getNextLeader($game);
                }

                $game->current_proposal_id = null;
                $game->save();

                GameEvent::create([
                    'game_id' => $game->id,
                    'event_type' => 'team_vote',
                    'event_data' => [
                        'approved' => $voteApproved,
                        'votes_for' => $approvalVotes,
                        'votes_against' => $requiredVotes - $approvalVotes,
                    ],
                ]);

                broadcast(new GameStateUpdate($game));
            }
        } else {
            if ($previousPhase === 'assassination') {
                $assassination_event = $game->gameEvents()->where('event_type', 'assassination')->first();
                if (!$assassination_event) {
                    Log::error('Assassination transition with no event', ['game_id' => $game->id]);
                    return;
                }
                $assassin_target = $assassination_event->event_data['assassin_target']['player_id'];
                $merlin = $game->players()->where('role', 'merlin')->first();

                if ($assassin_target === $merlin->id) {
                    $this->endGame($game, 'evil');
                } else {
                    $this->endGame($game, 'good');
                }

                return;
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
                        ],
                    ]);

                    // Check if game should end
                    $successfulMissions = $game->missions()->where('status', 'success')->count();

                    if ($successfulMissions >= 3) {
                        $game->current_phase = 'assassination';

                        // Set leader to assassin
                        $assassin = $game->players()->where('role', 'assassin')->first();
                        $game->current_leader_id = $assassin->id;
                        $game->save();
                    }
                }

                $failedMissions = $game->missions()->where('status', 'fail')->count();
                $successfulMissions = $game->missions()->where('status', 'success')->count();

                if ($failedMissions < 3 && $successfulMissions < 3) {
                    // Move to next mission
                    $game->current_phase = 'team_proposal';
                    $game->current_leader_id = $this->getNextLeader($game);
                    $game->current_mission_id = $game->missions()
                        ->where('status', 'pending')
                        ->orderBy('mission_number')
                        ->first()
                        ->id;
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
            'team_voting' => 'Team Voting',
            'mission' => 'Mission',
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
            $this->endGame($game, 'evil');

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
            'setup' => 'Initial introductions are complete. The game is now moving to the team proposal phase.',
            'team_proposal' => 'The team has been proposed and now needs to be voted on.',
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
            'team_proposal' => $isLeader
                ? 'You are the leader for this round. You need to propose a team of '.
                $game->currentMission->required_players.' players for the mission. Who do you trust?'
                : $game->currentLeader->name.' will propose a team for the mission.',

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
        
        // For testing, check for a game_start event first
        $lastTransition = GameEvent::where('game_id', $game->id)
            ->whereIn('event_type', ['game_start', 'team_vote', 'mission_complete'])
            ->orderBy('created_at', 'desc')
            ->first();
            
        // Use the event time if available, otherwise use game started_at
        $startTime = $lastTransition ? $lastTransition->created_at : $game->started_at;
        $timeoutSeconds = $this->phaseTimeouts[$game->current_phase];
        
        // Calculate elapsed time
        $elapsedSeconds = now()->diffInSeconds($startTime);
        
        return $elapsedSeconds > $timeoutSeconds;
    }
    
    private function forcePhaseTransition(Game $game): void
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
                        'proposal_number' => $game->currentMission->proposals()->max('proposal_number') + 1 ?? 1,
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
                
            case 'team_voting':
                // Force remaining votes as rejections
                if ($game->currentProposal) {
                    $nonVoters = $game->players()
                        ->where('id', '!=', $game->current_leader_id)
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
