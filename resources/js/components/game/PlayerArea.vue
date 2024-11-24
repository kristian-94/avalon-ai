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
        <div class="flex items-center gap-2">
          <div v-if="player.id === currentLeader" class="text-yellow-500 text-2xl">
            👑
          </div>
          <div v-if="currentProposal?.playerIndexes?.includes(player.player_index)" class="text-blue-500 text-2xl">
            🗡️
          </div>
          <div v-if="props.gameState.currentMission?.playerIndexes?.includes(player.player_index)" class="text-blue-500 text-2xl">
            <span class="border-2 border-blue-500 rounded-full px-2 py-1">🗡️</span>
          </div>
        </div>
      </div>

      <!-- Voting indicator, based on currentProposal votes. -->
      <div
          v-if="latestProposal?.votes && latestProposal.votes[player.player_index] !== undefined"
          class="w-3 h-3 transition-all duration-300"
          :title="latestProposal.votes[player.player_index] ? 'Approve' : 'Reject'"
      >
        {{ latestProposal.votes[player.player_index] ? '👍' : '👎' }}
      </div>

      <!-- Role & Type -->
      <div class="flex items-center justify-between mt-1">
        <div class="text-white/70 text-sm">
          {{ player.is_human ? 'Human Player' : 'AI Agent' }}
        </div>
        <!-- Only show role if game is finished -->
        <div v-if="isGameFinished" :class="[
          'text-m font-medium',
         player.role?.includes('minion') || player.role?.includes('assassin') ? 'text-red-400' : 'text-blue-400'
        ]">
          {{ player.roleLabel }}
        </div>
        <!-- During assassination phase, show evil players roles -->
        <div v-if="props.gameState.currentPhase === 'assassination' && (player.role?.includes('minion') || player.role?.includes('assassin'))" :class="[
          'text-m font-medium',
          player.role?.includes('minion') || player.role?.includes('assassin') ? 'text-red-400' : 'text-blue-400'
        ]">
          {{ player.roleLabel }}
        </div>
      </div>

      <!-- Mission indicators -->
      <div class="flex gap-2 mt-2">
        <template v-for="mission in missions" :key="mission.mission_number">
          <div
              v-if="mission.status !== 'pending' && mission.result?.team?.includes(player.name)"
              :class="[
              'w-3 h-3 rounded-full transition-all duration-300',
              mission.status === 'success' ? 'bg-green-500' :
              mission.status === 'fail' ? 'bg-red-500' :
              'bg-gray-500'
            ]"
              :title="`Mission ${mission.mission_number}: ${
              mission.status === 'success' ? 'Success' :
              mission.status === 'fail' ? 'Failed' :
              'Pending'
            }`"
          />
        </template>
      </div>

      <!-- Assassination indicators -->
      <div v-if="isGameFinished" class="mt-2 flex gap-2 justify-end">
        <div v-if="player.id === assassination?.assassin.player_id"
             class="text-xs text-red-400">
          Assassin
        </div>
        <div v-if="player.id === assassination?.target.player_id"
             class="text-xs text-yellow-400">
          Target
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {Player, GameState} from "../../types/game";
import {computed} from "vue";

const props = defineProps<{
  players: Player[]
  gameState: GameState
}>()

const latestProposal = computed(() => {
  if (!props.gameState.proposals || props.gameState.proposals.length === 0) return null
  if (!props.gameState.currentMission) return null
  if (props.gameState.currentPhase !== 'mission') return null
  return props.gameState.proposals[props.gameState.proposals.length - 1]
})

const currentLeader = computed(() => {
  if (!props.gameState.currentLeader) return null
  return props.gameState.currentLeader
})

const missions = computed(() => {
  if (!props.gameState.missions) return []
  return props.gameState.missions
})

const currentProposal = computed(() => {
  if (!props.gameState.currentProposal) return null
  return props.gameState.currentProposal
})
const assassination = computed(() => props.gameState.assassination)
const isGameFinished = computed(() => props.gameState.currentPhase === 'finished')
console.log(props.gameState.currentPhase)

</script>