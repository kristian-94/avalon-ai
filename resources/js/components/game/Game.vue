<template>
  <div v-if="loading" class="flex items-center justify-center min-h-[600px]">
    <div class="text-white text-xl">Loading game...</div>
  </div>

  <div v-else-if="error" class="flex items-center justify-center min-h-[600px]">
    <div class="text-red-500 text-xl">{{ error }}</div>
  </div>

  <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <GameStateComponent :game-state="gameState" :players="players" :game="game" @new-game="startNewGame"/>
    <GameHistory :events="events" :rolesRevealed="gameState?.currentPhase === 'debrief' || gameState?.currentPhase === 'finished'"/>
    <ChatInterface
        :player-id="playerId"
        :messages="messages"
        :game-state="gameState"
        :players="players"
        :game-id="gameId"
        @send-message="sendMessage"
    />
  </div>
</template>

<script setup lang="ts">
import {ref, onMounted, onUnmounted, onBeforeUnmount} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import axios from 'axios'
import ChatInterface from "../chat/ChatInterface.vue"
import GameStateComponent from "./GameState.vue"
import GameHistory from "./GameHistory.vue"
import type {Message, Player, Game, GameState, GameEvent} from "../../types/game";

const route = useRoute()
const router = useRouter()

const gameId = ref<number>(parseInt(route.params.id as string))
const playerId = ref<number>(parseInt(localStorage.getItem('playerId') || '0', 10))
const loading = ref(true)
const error = ref<string | null>(null)
const messages = ref<Message[]>([])
const players = ref<Player[]>([])
const gameState = ref<GameState | null>(null)
const game = ref<Game | null>(null)
const events = ref<GameEvent[]>([])
let pollInterval: ReturnType<typeof setInterval> | null = null

const initializeGame = async () => {
  try {
    const response = await axios.get(`/api/game/${gameId.value}/state`)
    const {game: gameData, messages: gameMessages, players: gamePlayers} = response.data

    // Check if game exists
    if (!gameData) {
      console.error('Game not found')
      router.push('/')
      return
    }

    // Set initial state
    messages.value = gameMessages
    players.value = gamePlayers
    gameState.value = gameData.game_state
    game.value = gameData
    events.value = response.data.events || []

    // If we don't have a valid player ID and this is a game with a human player,
    // try to find the human player or redirect
    if ((!playerId.value || playerId.value === 0) && game.has_human_player) {
      const humanPlayer = gamePlayers.find((player: Player) => player.is_human)
      if (humanPlayer) {
        playerId.value = humanPlayer.id
        localStorage.setItem('playerId', playerId.value.toString())
        console.log('Human player found:', humanPlayer)
      } else {
        console.error('No human player found')
        router.push('/')
        return
      }
    }

    // Initialize WebSocket connection
    initializeWebSocket()

    // Polling fallback: refresh state every 250ms while game is active
    if (!gameData.ended_at) {
      pollInterval = setInterval(pollGameState, 250)
    }
  } catch (err) {
    console.error('Failed to fetch game state:', err)
    error.value = 'Failed to load game'
    router.push('/')
  } finally {
    loading.value = false
  }
}

const pollGameState = async () => {
  try {
    const response = await axios.get(`/api/game/${gameId.value}/state`)
    const {game: gameData, players: gamePlayers} = response.data
    if (!gameData) return
    gameState.value = gameData.game_state
    players.value = gamePlayers
    game.value = gameData
    events.value = response.data.events || []
    // Stop polling when game ends
    if (gameData.ended_at && pollInterval) {
      clearInterval(pollInterval)
      pollInterval = null
    }
  } catch { /* ignore poll errors */ }
}

const initializeWebSocket = () => {
  const channel = window.Echo.channel(`game.${gameId.value}`)

  // Listen for chat messages
  channel.listen('NewMessage', (event: any) => {
    const newMessage: Message = {
      id: event.id || messages.value.length + 1,
      player_name: event.player_name || 'Unknown',
      content: event.content,
      created_at: event.created_at,
      isSystem: event.isSystem,
      player_id: event.player_id,
    }
    addMessage(newMessage)
  })

  // Listen for game state updates
  channel.listen('.GameStateUpdate', (event: any) => {
    if (event) {
      gameState.value = event.eventData.game.game_state
      if (event.eventData.players) {
        players.value = event.eventData.players
      }
      game.value = event.eventData.game
      events.value = event.eventData.events || []
    }
  })
}

const addMessage = (message: Message) => {
  messages.value.push(message)
}

const startNewGame = async () => {
  const mode = game.value?.has_human_player ? 'play' : 'watch'
  const response = await axios.post('/api/game/initialize', { mode })
  const { gameId: newGameId, playerId: newPlayerId } = response.data
  localStorage.setItem('playerId', newPlayerId)
  localStorage.setItem('gameId', newGameId)
  if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
  window.Echo.leave(`game.${gameId.value}`)
  gameId.value = newGameId
  playerId.value = newPlayerId
  await initializeGame()
  await router.push(`/game/${newGameId}`)
}

const sendMessage = async (messageText: string) => {
  if (!playerId.value) {
    console.error('No player ID available')
    return
  }

  try {
    await axios.post('/api/game/sendMessage', {
      gameId: gameId.value,
      playerId: playerId.value,
      content: messageText
    })
  } catch (err) {
    console.error('Failed to send message:', err)
  }
}

onMounted(() => {
  if (!gameId.value) {
    router.push('/')
    return
  }
  initializeGame()
})

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
  if (gameId.value) {
    window.Echo.leave(`game.${gameId.value}`)
  }
})
</script>