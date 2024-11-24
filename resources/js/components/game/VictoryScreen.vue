<template>
  <div class="space-y-6 text-center p-6 bg-black/40 backdrop-blur-sm rounded-lg">
    <!-- Victory Banner -->
    <div :class="[
      'text-4xl font-bold',
      gameState.winner === 'good' ? 'text-blue-400' : 'text-red-400'
    ]">
      {{ gameState.winner === 'good' ? 'Good Triumphs!' : 'Evil Prevails!' }}
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

    <!-- Player Roles -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mt-8">
      <div v-for="player in players" :key="player.id" class="bg-black/30 p-4 rounded-lg">
        <div class="font-bold text-white">{{ player.name }}</div>
        <div :class="[
          'text-sm mt-1',
          player.role?.includes('evil') ? 'text-red-400' : 'text-blue-400'
        ]">
          {{ player.roleLabel || 'Unknown Role' }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
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
</script>