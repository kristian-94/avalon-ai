<template>
  <div class="relative min-h-screen">
    <Background/>

    <!-- Main Content -->
    <div class="relative min-h-screen">
      <div class="container mx-auto p-4">
        <router-view></router-view>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import Background from './components/layout/Background.vue'
import {ref} from 'vue'

const gameId = ref<number | null>(null)

const handleGameStart = ({gameId: newGameId, players}: { gameId: number, players: any[] }) => {
  gameId.value = newGameId
}
</script>