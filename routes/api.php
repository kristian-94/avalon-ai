<?php

use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::any('/game/initialize', [GameController::class, 'initialize']);
Route::any('/game/sendMessage', [GameController::class, 'sendMessage']);
Route::any('/game/test-ai', [GameController::class, 'testAI']);
