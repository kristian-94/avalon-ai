# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Avalon AI** is a web app with a simple UI showing a game state diagram and a group chat interface where 5 AI agents play Avalon together. You can also join the game as a human player.

## Core Architecture

### Technology Stack
- **Backend**: Laravel 11.9 with PHP 8.2+
- **Frontend**: Vue.js 3.5 with Vue Router and Tailwind CSS
- **Database**: SQLite (default), configurable to MySQL/PostgreSQL
- **Real-time**: Laravel Reverb for WebSocket connections
- **Build**: Vite 5.0 for frontend compilation
- **Queue**: Database-backed job processing for game logic

### Key Components
- **Game Loop** (`app/Jobs/GameLoop.php`): Core game logic processor that handles phase transitions and AI coordination
- **Agent Services** (`app/Contracts/AgentService.php`, `app/Services/`): AI integration layer with OpenAI
- **Game Models**: Complex relational structure (Game, Player, Mission, Message, etc.)
- **Vue Components** (`resources/js/components/`): Real-time game UI with WebSocket integration

### Game Architecture

1. **Agent Autonomy**: Each agent has its own isolated state (role knowledge, game observations, chat history) but isn't truly "autonomous" - they respond to game events rather than continuously running

2. **Two-Stage Reaction System**:
   - When any game event occurs, all agents are polled for their "reaction intent" (how urgently they want to speak and what type of response)
   - Based on a simple sorting of these intents, agents get to respond either with quick reactions ("That's sus!") or full statements
   - Multiple quick reactions can stack, but only one full statement happens at a time

3. **Game Engine**:
   - Maintains game state and rules
   - Broadcasts game events to agents
   - Provides structure for who speaks when (based on agent-reported urgency)
   - Doesn't make decisions itself, just applies consistent rules

4. **Communication Handling**:
   - Natural language for inter-agent discussion
   - Structured function calls for game actions (voting, mission choices)
   - System prompts for role instructions and game state updates

The system prioritizes natural conversation flow while maintaining manageable game progression and efficient use of API calls. Agents only perform costly operations when they have something meaningful to contribute, based on their own assessment of the situation.

## Game Rules (Simplified)

**The Resistance: Avalon** is a social deduction game where players have secret roles as either Good (Loyal Servants of Arthur) or Evil (Minions of Mordred).

### Setup (5 Players)
- **3 Good players**: Loyal Servants, including Merlin (knows all evil players)
- **2 Evil players**: Minions, including Assassin (can win by identifying Merlin)

### Special Roles
- **Merlin** (Good): Sees all evil players, must guide Good team without revealing identity
- **Assassin** (Evil): If Good wins 3 quests, can win by correctly identifying Merlin

### Game Flow
1. **Team Selection**: Current leader selects players for quest (2→3→2→3→3 players for quests 1-5)
2. **Team Voting**: All players vote to approve/reject the team (majority approval needed)
3. **Quest Phase**: Selected players secretly choose Success or Fail cards
   - Good players MUST play Success
   - Evil players may play Success OR Fail
   - Quest succeeds only if all cards are Success

### Victory Conditions
- **Good wins**: Complete 3 successful quests AND Assassin fails to identify Merlin
- **Evil wins**: Fail 3 quests OR 5 consecutive rejected proposals OR Assassin identifies Merlin

### Key Game Phases
- `setup`: Role assignment and initial state
- `team_proposal`: Leader selects team members
- `team_voting`: All players vote on proposed team
- `mission`: Selected players execute the quest
- `assassination`: Assassin attempts to identify Merlin (if Good won 3 quests)
- `game_over`: Final results and victory conditions

## Development Commands

### Primary Development Workflow
```bash
# Start all development services concurrently
composer dev
# This runs: serve + queue:listen + pail + npm run dev

# Individual services
php artisan serve                    # Laravel backend (port 8000)
npm run dev                         # Vite frontend dev server
php artisan queue:listen --tries=1  # Queue worker (required for game functionality)
php artisan pail --timeout=0       # Real-time log viewer

# Production build
npm run build
```

### Database Management
```bash
php artisan migrate                 # Run migrations
php artisan migrate:fresh --seed   # Fresh database with sample data
php artisan tinker                  # Laravel REPL for debugging
```

### Testing
```bash
php artisan test                    # Run all PHPUnit tests
php artisan test --filter=GameLoopTest  # Run specific test suite
```

**Important**: The queue worker (`php artisan queue:listen`) must be running for game functionality to work, as game logic is processed through the `GameLoop` job.

## Environment Setup

### Required Environment Variables
- `OPEN_AI_API_KEY`: OpenAI API key for AI agent functionality
- `DB_CONNECTION=sqlite`: Database configuration (SQLite by default)
- `QUEUE_CONNECTION=database`: Queue processing via database
- `BROADCAST_CONNECTION=log`: Broadcasting configuration for real-time features

### Database
- Default: SQLite at `database/database.sqlite`
- Created automatically during setup
- Complex schema supporting game state, players, missions, messages, and events

## API Endpoints

```
POST /api/game/initialize          # Start new game (play/watch mode)
POST /api/game/sendMessage         # Send human player chat message  
GET  /api/game/{id}/state          # Get current game state
POST /api/game/test-ai             # Debug AI responses
```

## Key Development Patterns

### Game State Management
- All game logic flows through the `GameLoop` job for consistency
- Real-time updates broadcast via Laravel Events and WebSockets
- AI agents maintain isolated state with role-specific knowledge

### AI Integration
- `AgentService` contract defines AI interaction interface
- `OpenAIService` handles API communication with structured prompts
- Function calling used for structured game actions (voting, proposals)
- Natural language for chat and discussion

### Testing Strategy
- Comprehensive unit tests in `tests/Unit/GameLoopTest.php`
- Full game simulation tests ensuring complete playability
- AI integration mocking for deterministic scenarios
- Phase transition testing for all game states

## Real-time Features

The application uses Laravel Echo with Reverb for WebSocket connections:
- Live game state updates
- Real-time chat with immediate message delivery
- Automatic UI updates for phase transitions
- Broadcasting of game events to all connected clients

## Code Conventions

- Follow Laravel conventions for models, controllers, and services
- Vue.js components use Composition API style
- Tailwind CSS for styling with component-based approach
- Database relationships heavily used - always eager load related models
- Game phases: `setup`, `team_proposal`, `team_voting`, `mission`, `assassination`, `game_over`

## Common Development Tasks

### Adding New Game Features
1. Update relevant models and migrations
2. Modify `GameLoop.php` for new game logic
3. Update Vue components for UI changes
4. Add corresponding tests in `GameLoopTest.php`

### AI Behavior Modifications  
1. Update prompts in `OpenAIService.php`
2. Modify agent state handling in `GameLoop.php`
3. Test with `POST /api/game/test-ai` endpoint

### Database Changes
Always create migrations and update model relationships. The schema is complex with interdependent tables.