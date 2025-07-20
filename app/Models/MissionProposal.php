<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MissionProposal extends Model
{
    protected $fillable = [
        'game_id',
        'mission_id',
        'proposed_by_id',
        'proposal_number',
        'status',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'proposed_by_id');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(MissionProposalMember::class, 'proposal_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(MissionProposalVote::class, 'proposal_id');
    }
}
