<template>
  <div v-if="loading" class="flex items-center justify-center min-h-[600px]">
    <div class="text-white text-xl">Loading game...</div>
  </div>

  <div v-else-if="error" class="flex items-center justify-center min-h-[600px]">
    <div class="text-red-500 text-xl">{{ error }}</div>
  </div>

  <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <GameState :game-id="gameId" :game-state="gameState" :players="players"/>
    <ChatInterface :game-id="gameId" :player-id="playerId" :messages="messages" @send-message="sendMessage"/>
  </div>
</template>

<script setup lang="ts">
import {ref, onMounted, onUnmounted} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import axios from 'axios'
import ChatInterface from "../chat/ChatInterface.vue"
import GameState from "./GameState.vue"
import {Message, Player} from "../../types/game";

const route = useRoute()
const router = useRouter()

const gameId = ref<number>(parseInt(route.params.id as string))
const playerId = ref<number>(parseInt(localStorage.getItem('playerId') || '0', 10))
const loading = ref(true)
const error = ref<string | null>(null)
const messages = ref<Message[]>([])
const players = ref<Player[]>([])
const gameState = ref<GameState | null>(null)

const initializeGame = async () => {
  try {
    const response = await axios.get(`/api/game/${gameId.value}/state`)
    const {game, messages: gameMessages, players: gamePlayers} = response.data

    // Check if game exists
    if (!game) {
      console.error('Game not found')
      router.push('/')
      return
    }

    // Set initial state
    messages.value = gameMessages
    players.value = gamePlayers
    gameState.value = game.game_state

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
  } catch (err) {
    console.error('Failed to fetch game state:', err)
    error.value = 'Failed to load game'
    router.push('/')
  } finally {
    loading.value = false
  }
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
    }
  })
}

const addMessage = (message: Message) => {
  messages.value.push(message)
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
  if (gameId.value) {
    window.Echo.leave(`game.${gameId.value}`)
  }
})
</script>