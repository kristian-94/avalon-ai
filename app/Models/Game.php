<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'winner'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'has_human_player' => 'boolean',
        'turn_count' => 'integer'
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
}


