<template>
  <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <div
        v-for="player in players"
        :key="player.id"
        :class="[
        'bg-black/40 backdrop-blur-sm rounded-lg p-4',
        player.id === currentLeader ? 'ring-2 ring-yellow-500/50' : '',
        'transition-all duration-300'
      ]"
    >
      <div class="flex items-center justify-between">
        <div class="text-white font-semibold">{{ player.name }}</div>
        <div v-if="player.id === currentLeader" class="text-yellow-500 text-2xl">
          👑
        </div>
        <div v-if="props.currentProposal?.playerIndexes?.includes(player.player_index)" class="text-blue-500 text-2xl">
          🗡️
        </div>
      </div>
      <div class="text-white/70 text-sm mt-1">
        {{ player.is_human ? 'Human Player' : 'AI Agent' }}
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
interface Player {
  id: number
  name: string
  is_human: boolean
}

const props = defineProps<{
  players: Player[]
  currentLeader?: number // This is the player index, not the player ID
  currentProposal?: {
    team: string[]
    playerIndexes: number[]
    votes?: Record<string, boolean>
  }
}>()

</script>