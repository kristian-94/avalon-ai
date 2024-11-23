<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    use HasFactory;

    protected $casts = [
        'is_human' => 'boolean',
        'role_knowledge' => 'array',
        'player_index' => 'integer'
    ];

    protected $fillable = [
        'game_id',
        'player_index',
        'name',
        'role',
        'is_human',
        'role_knowledge'
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function gameEvents(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function proposalVotes(): HasMany
    {
        return $this->hasMany(MissionProposalVote::class);
    }
}
