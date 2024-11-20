<?php

namespace App\Jobs;

use App\Events\NewMessage;
use App\Models\Game;
use App\Models\Message;
use App\Models\Player;
use App\Services\OpenAIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GameLoop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $gameId;
    private $minThinkingTime = 2; // Minimum seconds before AI responds
    private $maxThinkingTime = 5; // Maximum seconds before AI responds

    public function __construct(int $gameId)
    {
        $this->gameId = $gameId;
    }

    public function handle(): void
    {
        $game = Game::with(['players', 'messages', 'gameEvents'])->find($this->gameId);

        if (!$game) {
            Log::error('Game not found', ['gameId' => $this->gameId]);
            return;
        }

        // Check if game is over
        if ($game->ended_at !== null) {
            return;
        }

        $gameState = $game->game_state;
        $currentPhase = $gameState['currentPhase'];

        // Determine which players should act based on the current phase
        $eligiblePlayers = $this->getEligiblePlayers($game, $currentPhase);

        // Process turns for eligible AI players
        foreach ($eligiblePlayers as $player) {
            if (!$player->is_human) {
                $this->processAIPlayerTurn($game, $player, $currentPhase);
            }
        }

        // Check if we need to transition to a new phase
        $this->checkPhaseTransition($game);

        // Schedule the next turn
        self::dispatch($this->gameId)->delay(now()->addSeconds(random_int($this->minThinkingTime, $this->maxThinkingTime)));
    }

    private function getEligiblePlayers(Game $game, string $currentPhase): array
    {
        return match ($currentPhase) {
            'setup' => $game->players->all(),
            'team_proposal' => $game->players
                ->where('player_index', $game->game_state['currentLeader'] ?? 0)
                ->all(),
            'team_voting' => $game->players
                ->filter(fn($p) => !isset($game->game_state['votes'][$p->player_index]))
                ->all(),
            'mission' => $game->players
                ->filter(fn($p) => in_array($p->player_index, $game->game_state['currentTeam'] ?? []))
                ->filter(fn($p) => !isset($game->game_state['missionVotes'][$p->player_index]))
                ->all(),
            'discussion' => $game->players->all(),
            default => []
        };
    }

    private function processAIPlayerTurn(Game $game, Player $player, string $currentPhase): void
    {
        // Get all relevant messages in chronological order
        $messages = $this->prepareAIContext($game, $player);

        // Get AI response
        $openAI = new \App\Services\OpenAIService();
        $response = $openAI->getChatResponse($messages);

        if (empty($response['message'])) {
            Log::error('Empty AI response', ['game_id' => $game->id, 'player_id' => $player->id]);
            return;
        }

        // Store the reasoning as a private thought if provided
        if (!empty($response['reasoning'])) {
            Message::create([
                'game_id' => $game->id,
                'player_id' => $player->id,
                'message_type' => 'private_thought',
                'content' => $response['reasoning']
            ]);
        }

        // Handle vote if provided
        if (isset($response['vote'])) {
            $gameState = $game->game_state;
            if (!isset($gameState['votes'])) {
                $gameState['votes'] = [];
            }
            $gameState['votes'][$player->player_index] = $response['vote'];
            $game->game_state = $gameState;
            $game->save();
        }

        // Handle mission action if provided
        if (isset($response['mission_action'])) {
            $gameState = $game->game_state;
            if (!isset($gameState['missionVotes'])) {
                $gameState['missionVotes'] = [];
            }
            $gameState['missionVotes'][$player->player_index] = $response['mission_action'];
            $game->game_state = $gameState;
            $game->save();
        }

        // Create and broadcast the public message
        $message = Message::create([
            'game_id' => $game->id,
            'player_id' => $player->id,
            'message_type' => 'public_chat',
            'content' => $response['message']
        ]);

        broadcast(new NewMessage($message));
    }

    private function prepareAIContext(Game $game, Player $player): array
    {
        // Get all messages in chronological order
        $messages = $game->messages()
            ->whereIn('message_type', ['private_thought', 'public_chat'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($msg) use ($player) {
                // Private thoughts for this player become system messages
                if ($msg->message_type === 'private_thought' && $msg->player_id === $player->id) {
                    return [
                        'role' => 'system',
                        'content' => $msg->content
                    ];
                }
                // Public chat: assistant for current player, user for others
                if ($msg->message_type === 'public_chat') {
                    return [
                        'role' => $msg->player_id === $player->id ? 'assistant' : 'user',
                        'content' => "{$msg->player->name}: {$msg->content}"
                    ];
                }
                return null;
            })
            ->filter()
            ->toArray();

        return $messages;
    }

    private function checkPhaseTransition(Game $game): void
    {
        $gameState = $game->game_state;
        $currentPhase = $gameState['currentPhase'];

        $needsTransition = match ($currentPhase) {
            'setup' => $this->shouldTransitionFromSetup($game),
            'team_proposal' => $this->shouldTransitionFromTeamProposal($game),
            'team_voting' => $this->shouldTransitionFromTeamVoting($game),
            'mission' => $this->shouldTransitionFromMission($game),
            'discussion' => $this->shouldTransitionFromDiscussion($game),
            default => false
        };

        if ($needsTransition) {
            $this->transitionToNextPhase($game);
        }
    }

    private function shouldTransitionFromSetup(Game $game): bool
    {
        // Transition from setup if all players have sent their initial messages
        return $game->messages()
                ->where('message_type', 'public_chat')
                ->distinct('player_id')
                ->count() >= $game->players()->count();
    }

    private function shouldTransitionFromTeamProposal(Game $game): bool
    {
        // Transition if the leader has proposed a team
        return isset($game->game_state['proposedTeam']);
    }

    private function shouldTransitionFromTeamVoting(Game $game): bool
    {
        // Transition if all players have voted
        return count($game->game_state['votes'] ?? []) >= $game->players()->count();
    }

    private function shouldTransitionFromMission(Game $game): bool
    {
        // Transition if all team members have submitted their mission votes
        $teamSize = count($game->game_state['currentTeam'] ?? []);
        return count($game->game_state['missionVotes'] ?? []) >= $teamSize;
    }

    private function shouldTransitionFromDiscussion(Game $game): bool
    {
        // Transition after a certain number of messages or time has passed
        $messagesSincePhaseStart = $game->messages()
            ->where('created_at', '>', $game->game_state['phaseStarted'])
            ->count();

        return $messagesSincePhaseStart >= 5; // Arbitrary number, adjust as needed
    }

    private function transitionToNextPhase(Game $game): void
    {
        $gameState = $game->game_state;
        $currentPhase = $gameState['currentPhase'];

        $gameState['currentPhase'] = match ($currentPhase) {
            'setup' => 'team_proposal',
            'team_proposal' => 'team_voting',
            'team_voting' => $this->determineNextPhaseAfterVoting($game),
            'mission' => 'discussion',
            'discussion' => 'team_proposal',
            default => $currentPhase
        };

        $gameState['phaseStarted'] = now()->toIso8601String();

        // Update game state
        $game->game_state = $gameState;
        $game->save();
    }

    private function determineNextPhaseAfterVoting(Game $game): string
    {
        $votes = $game->game_state['votes'] ?? [];
        $approvalCount = count(array_filter($votes, fn($vote) => $vote === true));

        if ($approvalCount > count($votes) / 2) {
            return 'mission';
        }

        return 'team_proposal';
    }
}