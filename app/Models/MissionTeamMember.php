<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissionTeamMember extends Model
{
    protected $fillable = [
        'mission_id',
        'player_id',
        'vote_success',
    ];

    protected $casts = [
        'vote_success' => 'boolean',
    ];

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
