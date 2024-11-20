<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    use HasFactory;

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'has_human_player' => 'boolean',
        'game_state' => 'array',
        'winner' => 'string'
    ];

    protected $fillable = [
        'started_at',
        'ended_at',
        'has_human_player',
        'game_state',
        'winner'
    ];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function gameEvents(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
