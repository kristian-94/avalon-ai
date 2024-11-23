<template>
  <div class="flex flex-col items-center justify-center min-h-[80vh]">
    <div v-if="!gameState" class="text-center space-y-8">
      <h1 class="text-4xl font-bold text-white mb-8">Avalon AI</h1>
      <div class="space-y-4">
        <p class="text-white/80 text-lg mb-8">
          Join a game of Avalon with AI agents, or watch them play against each other.
        </p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
          <button
              @click="startGame('play')"
              class="px-8 py-4 bg-yellow-800 text-white rounded-lg border-2 border-yellow-900 hover:bg-yellow-700 transition-colors"
          >
            Play with AI
          </button>
          <button
              @click="startGame('watch')"
              class="px-8 py-4 bg-brown-800 text-white rounded-lg border-2 border-brown-900 hover:bg-brown-700 transition-colors"
          >
            Watch AI Play
          </button>
        </div>
      </div>
    </div>

    <div v-else class="text-center">
      <div v-if="gameState === 'initializing'" class="text-white space-y-4">
        <div class="animate-spin w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full mx-auto mb-4"></div>
        <p>Assigning roles and initializing game...</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref} from 'vue'
import {useRouter} from 'vue-router'
import axios from "axios";

const gameState = ref<'initializing' | null>(null)
const router = useRouter()

const startGame = async (mode: 'play' | 'watch') => {
  gameState.value = 'initializing'

  const response = await axios.post('/api/game/initialize', {
    mode: mode
  });

  const {gameId, playerId, message} = response.data

  localStorage.setItem('playerId', playerId);
  localStorage.setItem('gameId', gameId);
  localStorage.setItem('initialMessage', message);

  // Navigate to the game route
  await router.push(`/game/${gameId}`)
}
</script>
