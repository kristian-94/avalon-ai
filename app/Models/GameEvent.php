<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameEvent extends Model
{
    use HasFactory;

    protected $casts = [
        'event_data' => 'array',
        'created_at' => 'datetime'
    ];

    protected $fillable = [
        'game_id',
        'event_type',
        'player_id',
        'event_data'
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}

