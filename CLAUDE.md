# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Avalon AI** — 5 AI agents play The Resistance: Avalon in a web app. Users can watch or join as a human player.

## Tech Stack
- **Backend**: Laravel 11.9, PHP 8.2+, SQLite, database-backed queues
- **Frontend**: Vue.js 3.5, Vue Router, Tailwind CSS, Vite 5
- **Real-time**: Laravel Reverb (WebSockets via Laravel Echo)
- **AI**: OpenAI API via `OpenAIService` (function calling for structured game actions)

## Key Files
- `app/Jobs/GameLoop.php` — entire game engine: phase transitions, AI turn processing, timeout handling
- `app/Services/GameSetupService.php` — game initialization and role assignment
- `app/Services/OpenAIService.php` — OpenAI API, phase-specific function schemas
- `app/Contracts/AgentService.php` — `getChatResponse(array $messages): array`
- `resources/js/components/` — Vue UI with WebSocket integration

## Game Phases
`setup` → `team_proposal` → `team_voting` → `mission` → (repeat) → `assassination` → `game_over`

## Architecture Notes
- All game logic runs through the `GameLoop` job (queued, self-re-dispatching with a random 2-5s delay)
- Each AI player gets one turn per `GameLoop::handle()` invocation; phases advance via `checkPhaseTransition()`
- Phase timeouts call `forcePhaseTransition()` to unblock stuck games
- AI agents use function calling for game actions (votes, proposals) and natural language for chat
- Models: `Game`, `Player`, `Mission`, `MissionProposal`, `MissionProposalVote`, `MissionTeamMember`, `Message`, `GameEvent`

## Dev Commands
```bash
composer dev          # starts everything: serve + queue:listen + pail + vite
php artisan migrate:fresh --seed
php artisan test
```

## Environment
- `OPEN_AI_API_KEY` — required for AI agents
- `QUEUE_CONNECTION=database`, `BROADCAST_CONNECTION=log` (default)
- Queue worker **must** be running for game logic to execute

## API Endpoints
```
POST /api/game/initialize    # start new game
POST /api/game/sendMessage   # human player chat
GET  /api/game/{id}/state    # current game state
POST /api/game/test-ai       # debug AI responses
```

## Skills
- `/game-rules` — full Avalon rules, victory conditions, phase timeouts
- `/testing` — test commands, test file inventory, code style
