<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mission extends Model
{
    protected $fillable = [
        'game_id',
        'mission_number',
        'required_players',
        'status',
        'success_votes',
        'fail_votes',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(MissionProposal::class);
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(MissionTeamMember::class);
    }
}
