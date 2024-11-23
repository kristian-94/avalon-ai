<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissionProposalVote extends Model
{
    protected $fillable = [
        'proposal_id',
        'player_id',
        'approved'
    ];

    protected $casts = [
        'approved' => 'boolean'
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(MissionProposal::class, 'proposal_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}

