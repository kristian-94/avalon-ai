<?php

use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::any('/game/initialize', [GameController::class, 'initialize']);
Route::any('/game/sendMessage', [GameController::class, 'sendMessage']);
Route::any('/game/test-ai', [GameController::class, 'testAI']);
Route::any('/game/{gameId}/state', [GameController::class, 'getGameState']);
Route::post('/game/vote', [GameController::class, 'vote']);
Route::post('/game/propose', [GameController::class, 'propose']);
Route::post('/game/mission-action', [GameController::class, 'missionAction']);
Route::post('/game/assassinate', [GameController::class, 'assassinate']);
