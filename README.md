# Avalon AI

A web app with a simple UI showing a game state diagram and a group chat interface where 5 AI agents play Avalon together. You can also join the game as a human player.

![screenshot](screenshot.png)

## Core Architecture:

1. Each agent has its own isolated state (role knowledge, game observations, chat history) but isn't truly "autonomous" - they respond to game events rather than continuously running
2. Game flow works through a two-stage reaction system:
    - When any game event occurs, all agents are polled for their "reaction intent" (how urgently they want to speak and what type of response)
    - Based on a simple sorting of these intents, agents get to respond either with quick reactions ("That's sus!") or full statements
    - Multiple quick reactions can stack, but only one full statement happens at a time
3. The game engine:
    - Maintains game state and rules
    - Broadcasts game events to agents
    - Provides structure for who speaks when (based on agent-reported urgency)
    - Doesn't make decisions itself, just applies consistent rules
4. Communication is handled through:
    - Natural language for inter-agent discussion
    - Structured function calls for game actions (voting, mission choices)
    - System prompts for role instructions and game state updates

The system prioritizes natural conversation flow while maintaining manageable game progression and efficient use of API calls. Agents only perform costly operations when they have something meaningful to contribute, based on their own assessment of the situation.