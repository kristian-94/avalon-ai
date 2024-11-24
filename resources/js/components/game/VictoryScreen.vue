<template>
  <div class="space-y-6 text-center p-6 bg-black/40 backdrop-blur-sm rounded-lg">
    <!-- Victory Banner -->
    <div :class="[
      'text-4xl font-bold',
      gameState.winner === 'good' ? 'text-blue-400' : 'text-red-400'
    ]">
      {{ gameState.winner === 'good' ? 'Good Triumphs!' : 'Evil Prevails!' }}
    </div>

    <!-- Assassination Result (if good team won missions) -->
    <div v-if="assassination" class="mt-4">
      <div class="mt-2 text-lg">
        <span class="text-red-400">{{ assassination.assassin.name }}</span>
        assassinated
        <span class="text-blue-400">{{ assassination.target.name }}</span>
        <div class="mt-2 text-white/80">
          {{ assassination.wasSuccessful ?
            'Merlin was found! Evil wins through assassination!' :
            'The real Merlin escaped! Good wins!' }}
        </div>
      </div>
    </div>

    <!-- Mission Results Summary -->
    <div class="flex justify-center gap-4 my-4">
      <div class="text-center">
        <div class="text-2xl font-bold text-blue-400">{{ goodMissions }}</div>
        <div class="text-sm text-blue-200">Successful</div>
      </div>
      <div class="text-white text-2xl font-bold">vs</div>
      <div class="text-center">
        <div class="text-2xl font-bold text-red-400">{{ evilMissions }}</div>
        <div class="text-sm text-red-200">Failed</div>
      </div>
    </div>

    <!-- Mission History -->
    <div class="grid grid-cols-5 gap-2 max-w-lg mx-auto">
      <div v-for="(mission, index) in gameState.missions" :key="index" class="relative">
        <div :class="[
          'w-full aspect-square rounded-lg flex items-center justify-center text-lg font-bold',
          mission.status === 'success' ? 'bg-blue-500/20 text-blue-400 border-2 border-blue-500' :
          mission.status === 'fail' ? 'bg-red-500/20 text-red-400 border-2 border-red-500' :
          'bg-gray-500/20 text-gray-400 border-2 border-gray-500'
        ]">
          {{ index + 1 }}
        </div>
        <div v-if="mission.result" class="mt-2 text-xs text-white/70">
          {{ mission.result.team.join(', ') }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps({
  gameState: {
    type: Object,
    required: true
  },
  players: {
    type: Array,
    required: true
  }
})

const completedMissions = computed(() =>
    props.gameState.missions.filter(m => m.status !== 'pending')
)

const goodMissions = computed(() =>
    completedMissions.value.filter(m => m.status === 'success').length
)

const evilMissions = computed(() =>
    completedMissions.value.filter(m => m.status === 'fail').length
)
const assassination = computed(() => props.gameState.assassination)
</script>