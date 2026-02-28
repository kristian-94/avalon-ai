<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Player $currentLeader
 * @property Mission $currentMission
 * @property MissionProposal $currentProposal
 * @property Player[] $players
 * @property Mission[] $missions
 * @property MissionProposal[] $proposals
 * @property Message[] $messages
 * @property GameEvent[] $gameEvents
 * @property int $id
 * @property string current_phase
 */
class Game extends Model
{
    protected $fillable = [
        'started_at',
        'ended_at',
        'has_human_player',
        'current_phase',
        'turn_count',
        'current_leader_id',
        'current_mission_id',
        'current_proposal_id',
        'winner',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'has_human_player' => 'boolean',
        'turn_count' => 'integer',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function currentLeader(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'current_leader_id');
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    public function currentMission(): BelongsTo
    {
        return $this->belongsTo(Mission::class, 'current_mission_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(MissionProposal::class);
    }

    public function currentProposal(): BelongsTo
    {
        return $this->belongsTo(MissionProposal::class, 'current_proposal_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function gameEvents(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function renderFullGameState(): array
    {
        $game = $this;
        $missions = $game->missions->map(function ($mission) {
            return [
                'id' => $mission->id,
                'name' => "Mission {$mission->mission_number}",
                'status' => $mission->status,
                'required' => $mission->required_players,
                'result' => $mission->status !== 'pending' ? [
                    'success' => $mission->status === 'success',
                    'team' => $mission->teamMembers->map(fn ($tm) => $tm->player->name)->values()->toArray(),
                    'votes' => [
                        'success' => $mission->success_votes,
                        'fail' => $mission->fail_votes,
                    ],
                ] : null,
            ];
        });

        // Format current proposal data
        $currentProposal = null;
        if ($game->currentProposal) {
            $currentProposal = [
                'team' => $game->currentProposal->teamMembers->map(fn ($tm) => $tm->player->name)->values()->toArray(),
                'playerIndexes' => $game->currentProposal->teamMembers->map(fn ($tm) => $tm->player->player_index)->values()->toArray(),
                'votes' => $game->currentProposal->votes->mapWithKeys(function ($vote) {
                    return [$vote->player_id => $vote->approved];
                })->toArray(),
            ];
        }

        // Format current mission data
        $currentMission = null;
        if ($game->currentMission) {
            $currentMission = [
                'id' => $game->currentMission->id,
                'required' => $game->currentMission->required_players,
                'playerIndexes' => $game->currentMission->teamMembers->map(fn ($tm) => $tm->player->player_index)->values()->toArray(),
                'team' => $game->currentMission->teamMembers->map(fn ($tm) => $tm->player->name)->values()->toArray(),
                'status' => $game->currentMission->status,
            ];
        }

        $proposals = $game->proposals->map(function ($proposal) {
            return [
                'team' => $proposal->teamMembers->map(fn ($tm) => $tm->player->name)->values()->toArray(),
                'playerIndexes' => $proposal->teamMembers->map(fn ($tm) => $tm->player->player_index)->values()->toArray(),
                'votes' => $proposal->votes ? $proposal->votes->mapWithKeys(function ($vote) {
                    return [$vote->player->player_index => $vote->approved];
                })->toArray() : [],
            ];
        })->values()->toArray();

        $assassinationEvent = $game->gameEvents()->firstWhere('event_type', 'assassination');
        $assassination = null;
        if ($assassinationEvent) {
            $eventData = $assassinationEvent->event_data;
            $name = $eventData['assassin_target']['player_name'];
            $targetId = $eventData['assassin_target']['player_id'];
            $assassin = $game->players()->firstWhere('role', 'assassin');
            $merlin = $game->players()->firstWhere('role', 'merlin');
            $assassination = [
                'assassin' => [
                    'name' => $assassin->name,
                    'id' => $assassin->id,
                    'index' => $assassin->player_index,
                ],
                'target' => [
                    'name' => $name,
                    'id' => $targetId,
                    'role' => $game->players()->firstWhere('id', $targetId)->role,
                ],
                'wasSuccessful' => $targetId === $merlin->id,
            ];
        }

        return [
            'game' => [
                'id' => $game->id,
                'game_state' => [
                    'currentPhase' => $game->current_phase,
                    'turnCount' => $game->turn_count,
                    'currentLeader' => $game->current_leader_id,
                    'currentMission' => $currentMission,
                    'currentProposal' => $currentProposal,
                    'assassination' => $assassination,
                    'missions' => $missions,
                    'proposals' => $proposals,
                ],
                'has_human_player' => $game->has_human_player,
                'winner' => $game->winner,
                'turn_count' => $game->turn_count,
                'ended_at' => $game->ended_at,
                'started_at' => $game->started_at,
            ],
            'messages' => array_values($game->messages
                ->reject(fn ($message) => $message->message_type !== 'public_chat')
                ->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'content' => $message->content,
                        'player_id' => $message->player_id,
                        'player_name' => $message->player ? $message->player->name : 'System',
                        'created_at' => $message->created_at,
                        'isSystem' => $message->player_id === null,
                    ];
                })->toArray()),
            'events' => $game->gameEvents->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'event_data' => $e->event_data,
                'created_at' => $e->created_at,
            ])->values()->toArray(),
            'apiUsage' => [
                'calls' => $game->api_calls,
                'totalTokens' => $game->total_tokens,
                'promptTokens' => $game->prompt_tokens,
                'completionTokens' => $game->completion_tokens,
            ],
            'players' => $game->players->map(function ($player) use ($game) {
                $game->fresh();
                // Always include role for the human player, or at game end for all players
                $role = ($player->is_human || in_array($game->current_phase, ['finished', 'debrief'])) ? $player->role : null;
                // Otherwise include the role for evil players during final assassination phase
                if (!$role && $game->current_phase === 'assassination' && $player->role === 'assassin') {
                    $role = 'assassin';
                } elseif (!$role && $game->current_phase === 'assassination' && $player->role === 'minion') {
                    $role = 'minion';
                }
                $roleLabel = $role ? ($role === 'loyal_servant' ? 'Loyal Servant' : ucfirst($role)) : null;
                if ($role === 'merlin') {
                    $roleLabel = 'Merlin';
                } elseif ($role === 'minion') {
                    $roleLabel = 'Minion of Mordred';
                } elseif ($role === 'assassin') {
                    $roleLabel = 'Assassin';
                }

                return [
                    'id' => $player->id,
                    'name' => $player->name,
                    'player_index' => $player->player_index,
                    'role' => $role,
                    'roleLabel' => $roleLabel,
                    'is_human' => $player->is_human,
                ];
            }),
        ];
    }
}
