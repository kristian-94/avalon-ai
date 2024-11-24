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
use App\Services\OpenAIService;
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

    private $minThinkingTime = 2; // Minimum seconds before AI responds
    private $maxThinkingTime = 5; // Maximum seconds before AI responds

    public function __construct(public int $gameId)
    {
    }

    public function handle(): void
    {
        $game = Game::with(['players', 'messages', 'gameEvents', 'currentMission', 'currentProposal'])->find($this->gameId);

        if (!$game || $game->ended_at !== null) {
            return;
        }

        // Determine which players should act based on the current phase
        $eligiblePlayers = $this->getEligiblePlayers($game);

        // Process turns for eligible AI players
        foreach ($eligiblePlayers as $player) {
            if (!$player->is_human) {
                $this->processAIPlayerTurn($game, $player);
            }
        }

        // Check if we need to transition to a new phase
        $this->checkPhaseTransition($game);

        // Schedule the next turn
        self::dispatch($this->gameId)->delay(now()->addSeconds(random_int($this->minThinkingTime, $this->maxThinkingTime)));
    }

    public function endGame(Game $game, string $winner): void
    {
        $game->update([
            'ended_at' => now(),
            'current_phase' => 'finished',
            'winner' => $winner
        ]);

        GameEvent::create([
            'game_id' => $game->id,
            'event_type' => 'game_end',
            'event_data' => ['winner' => $winner]
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
        $wonAfterAssassination = (bool)$assassination_event;
        $rejectedProposals = $game->currentMission->proposals()->where('status', 'rejected')->count();
        $wonAfter5FailedProposals = $rejectedProposals >= 5;

        // Create messages for each player
        foreach ($game->players as $player) {
            $message = match (true) {
                // Merlin's special messages
                $player->role === 'merlin' && $winner === 'good' =>
                "You are Merlin and the good team has won. You were able to identify all the evil players. ",
                $player->role === 'merlin' && $wonAfterAssassination =>
                "You are Merlin and were too obvious. The evil team has won by killing you. ",
                $player->role === 'merlin' && $wonAfter5FailedProposals =>
                "You are Merlin and the evil team has won. You were unable to stop the chaos of the team rejections. ",
                $player->role === 'merlin' =>
                "You are Merlin and the evil team has won. You were unable to identify all the evil players. ",
                default => ""
            };

            // Add game outcome message
            $message .= "The game has ended. " . match (true) {
                    $winner === 'good' && $wonAfterAssassination =>
                    "The loyal servants have won. The Assassin was unable to identify Merlin.",
                    $winner === 'good' =>
                    "The loyal servants have won.",
                    $wonAfterAssassination =>
                    "The minions of Mordred have won. The Assassin was able to identify Merlin.",
                    $wonAfter5FailedProposals =>
                    "The minions of Mordred have won through team rejection chaos.",
                    default =>
                    "The minions of Mordred have won."
                };

            Message::create([
                'game_id' => $game->id,
                'player_id' => $player->id,
                'message_type' => 'game_event',
                'content' => $message . $roleRevealString
            ]);
        }
    }

    public function getEligiblePlayers(Game $game): array
    {
        return match ($game->current_phase) {
            'setup' => $game->players()
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

        $needsMessage = true;
        if (empty($response['message'])) {
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
                $needsMessage = false; // No need to send a message for mission action
            }
        }

        // Handle team proposal
        if ($game->current_phase === 'team_proposal' && isset($response['team_proposal']) && $game->current_leader_id === $player->id) {
            $proposal = MissionProposal::create([
                'game_id' => $game->id,
                'mission_id' => $game->currentMission->id,
                'proposed_by_id' => $player->id,
                'proposal_number' => $game->currentMission->proposals()->max('proposal_number') + 1 ?? 1,
                'status' => 'pending'
            ]);

            // Add team members to proposal
            $proposedPlayers = collect(explode(',', $response['team_proposal']))
                ->map(fn($name) => $game->players()->where('name', trim($name))->first())
                ->filter();

            foreach ($proposedPlayers as $proposedPlayer) {
                MissionProposalMember::create([
                    'proposal_id' => $proposal->id,
                    'player_id' => $proposedPlayer->id
                ]);
            }

            $game->current_proposal_id = $proposal->id;
            $game->save();
        }
        if ($game->current_phase === 'assassination' && isset($response['assassination_target'])) {
            $targetPlayer = $game->players()->where('name', $response['assassination_target'])->first();
            assert($targetPlayer !== null);

            GameEvent::create([
                'game_id' => $game->id,
                'event_type' => 'assassination',
                'event_data' => [
                    'assassin_target' => [
                        'player_name' => $response['assassination_target'],
                        'player_id' => $targetPlayer->id
                    ]
                ]
            ]);
        }

        if ($needsMessage) {
            // Create and broadcast the message
            $message = Message::create([
                'game_id' => $game->id,
                'player_id' => $player->id,
                'message_type' => 'public_chat',
                'content' => $response['message']
            ]);

            broadcast(new NewMessage($message));
        }
    }

    public function prepareAIContext(Game $game, Player $player): array
    {
        // Get all messages in chronological order
        $messages = $game->messages()
            ->whereIn('message_type', ['system_prompt', 'private_thought', 'public_chat', 'game_event'])
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($msg) use ($player) {
                // system_prompt is the first message.
                if ($msg->message_type === 'system_prompt' && $msg->player_id === $player->id) {
                    return [
                        'role' => 'system',
                        'content' => $msg->content
                    ];
                }

                // Private thoughts for this player are from the assistant role
                if ($msg->message_type === 'private_thought' && $msg->player_id === $player->id) {
                    return [
                        'role' => 'assistant',
                        'content' => 'Private thought: ' . $msg->content
                    ];
                }
                // Public chat: assistant for current player, user for others, and system for game events
                if ($msg->message_type === 'public_chat') {
                    $playerName = $msg->player?->name ?? 'Game event';
                    return [
                        'role' => $msg->player_id === $player->id ? 'assistant' : 'user',
                        'content' => "{$playerName}: {$msg->content}"
                    ];
                }

                if ($msg->message_type === 'game_event' && $msg->player_id === $player->id) {
                    return [
                        'role' => 'system',
                        'content' => $msg->content
                    ];
                }
                return null;
            })
            ->filter()
            ->toArray();

        return array_values($messages);
    }

    public function checkPhaseTransition(Game $game): void
    {
        $needsTransition = match ($game->current_phase) {
            'setup' => $this->shouldTransitionFromSetup($game),
            'team_proposal' => $this->shouldTransitionFromTeamProposal($game),
            'team_voting' => $this->shouldTransitionFromTeamVoting($game),
            'mission' => $this->shouldTransitionFromMission($game),
            'assassination' => true, // Always transition from assassination
            default => false
        };

        if ($needsTransition) {
            $this->transitionToNextPhase($game);
        }
    }

    public function processPlayerVote(Game $game, Player $player, bool $approved): void
    {
        if (!$game->currentProposal) {
            return;
        }

        MissionProposalVote::create([
            'proposal_id' => $game->currentProposal->id,
            'player_id' => $player->id,
            'approved' => $approved
        ]);
    }

    public function shouldTransitionFromSetup(Game $game): bool
    {
        // Transition from setup if all players have sent their initial messages
        return $game->messages()
                ->where('message_type', 'public_chat')
                ->distinct('player_id')
                ->count() >= $game->players()->count();
    }

    public function shouldTransitionFromTeamProposal(Game $game): bool
    {
        // Transition if there's a current proposal
        return $game->current_proposal_id !== null;
    }

    public function shouldTransitionFromTeamVoting(Game $game): bool
    {
        if (!$game->currentProposal) {
            return false;
        }

        // Transition if all players have voted on the current proposal
        $totalVotes = $game->currentProposal->votes()->count();
        $totalPlayers = $game->players()->count();

        return $totalVotes >= ($totalPlayers - 1);
    }

    public function shouldTransitionFromMission(Game $game): bool
    {
        if (!$game->currentMission) {
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
                    // If approved, move team members to mission
                    foreach ($game->currentProposal->teamMembers as $member) {
                        MissionTeamMember::create([
                            'mission_id' => $game->currentMission->id,
                            'player_id' => $member->player_id
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
                        'votes_against' => $requiredVotes - $approvalVotes
                    ]
                ]);

                broadcast(new GameStateUpdate($game));
            }
        } else {
            if ($previousPhase === 'assassination') {
                $assassination_event = $game->gameEvents()->where('event_type', 'assassination')->first();
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
            } else if ($previousPhase === 'mission' || $previousPhase === 'setup') {
                // We're transitioning after a mission, set up the next mission
                if ($previousPhase === 'mission') {
                    $requiredVotes = $game->currentMission->teamMembers()->count();

                    $failVotes = $game->currentMission->teamMembers()->where('vote_success', false)->count();
                    $missionSucceeded = $failVotes === 0; // If there are any fail votes, the mission fails.

                    $game->currentMission->status = $missionSucceeded ? 'success' : 'fail';
                    $game->currentMission->success_votes = $requiredVotes - $failVotes;
                    $game->currentMission->fail_votes = $failVotes;
                    $game->currentMission->save();

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

        broadcast(new GameStateUpdate($game));
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
                    'content' => $contextMessage
                ]);
            }

            if ($instructionsMessage) {
                Message::create([
                    'game_id' => $game->id,
                    'player_id' => $player->id,
                    'message_type' => 'game_event',
                    'content' => $instructionsMessage
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
            'content' => $gamePhaseLabel . ' has begun.'
        ]);

        broadcast(new NewMessage($publicmessage));

        if (isset($failedMissions) && $failedMissions >= 3) {
            $this->endGame($game, 'evil');
        }
    }

    public function determineNextPhaseAfterVoting(Game $game): string
    {
        if (!$game->currentProposal) {
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

        if (!$game->current_leader_id) {
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
            return "Mission " . $completedMission->mission_number . " " . ($completedMission->status === 'success' ? "succeeded" : "failed with " . $numberOfFailedVotes . " failed vote" . ($numberOfFailedVotes === 1 ? "" : "s") . ". ");
        }

        return match ($previousPhase) {
            'setup' => "Initial introductions are complete. The game is now moving to the team proposal phase.",
            'team_proposal' => "The team has been proposed and now needs to be voted on.",
            'team_voting' => $game->currentProposal ?
                "The vote " . ($game->currentProposal->status === 'approved' ? "passed" : "failed") . ". " .
                ($game->currentProposal->status === 'approved'
                    ? "The proposed team will now go on the mission."
                    : "Moving to the next leader for a new team proposal.")
                : "Voting phase has concluded.",
            default => null
        };
    }

    public function generateNextPhaseInstructions(Game $game, Player $player): ?string
    {
        $game->refresh();
        $isLeader = $game->current_leader_id === $player->id;

        return match ($game->current_phase) {
            'team_proposal' => $isLeader
                ? "You are the leader for this round. You need to propose a team of " .
                $game->currentMission->required_players . " players for the mission. Who do you trust?"
                : $game->currentLeader->name . " will propose a team for the mission.",

            'team_voting' => "You need to vote on the proposed team: " .
                $game->currentProposal->teamMembers->map(fn($tm) => $tm->player->name)->join(', ') .
                ". Consider if you trust these players to complete the mission successfully.",

            'mission' => $game->currentMission->teamMembers()
                ->where('player_id', $player->id)
                ->exists()
                ? "You are on the mission team. " . ($player->role === 'loyal_servant' || $player->role === 'merlin'
                    ? "As a good player, you should support the mission."
                    : "As an evil player, you can choose to sabotage the mission.")
                : "The mission team will now attempt to complete their mission.",

            'assassination' => match ($player->role) {
                'assassin' => "The good team has won 3 missions. As the Assassin, you must now identify Merlin",
                'merlin' => "The Assassination phase has begun. The Assassin will try to identify you",
                default => "The Assassination phase has begun. The Assassin will try to identify Merlin"
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
}