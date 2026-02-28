<template>
  <div class="mt-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
    <div
        v-for="player in players"
        :key="player.id"
        :class="[
        'group relative bg-black/40 backdrop-blur-sm rounded-lg p-4 transition-all duration-200 flex flex-col items-center text-center',
        'hover:bg-black/60 hover:scale-[1.03] hover:shadow-xl hover:shadow-black/40',
        player.id === currentLeader ? 'ring-2 ring-yellow-500/50' : '',
      ]"
    >
      <!-- Avatar -->
      <div class="relative mb-3">
        <img
          :src="getAvatarUrl(player.name)"
          :alt="player.name"
          class="w-28 h-28 rounded-full ring-2 ring-white/20 object-cover"
        />
        <!-- Status icons overlaid on avatar -->
        <div class="absolute -top-1 -right-1 flex flex-col gap-1">
          <div v-if="player.id === currentLeader" class="relative group/tip">
            <div class="text-yellow-500 text-lg cursor-default drop-shadow-lg">👑</div>
            <div class="absolute bottom-full right-0 mb-1.5 px-2 py-1 bg-black/90 border border-yellow-500/30 text-yellow-200 text-xs rounded whitespace-nowrap invisible group-hover/tip:visible opacity-0 group-hover/tip:opacity-100 transition-opacity z-20 pointer-events-none">
              Current Leader — proposes the mission team
            </div>
          </div>
          <div v-if="currentProposal?.playerIndexes?.includes(player.player_index) && gameState?.currentPhase === 'team_voting'" class="relative group/tip">
            <div class="text-blue-400 text-lg cursor-default drop-shadow-lg">🛡️</div>
            <div class="absolute bottom-full right-0 mb-1.5 px-2 py-1 bg-black/90 border border-blue-500/30 text-blue-200 text-xs rounded whitespace-nowrap invisible group-hover/tip:visible opacity-0 group-hover/tip:opacity-100 transition-opacity z-20 pointer-events-none">
              Proposed for this mission
            </div>
          </div>
          <div v-if="gameState?.currentMission?.playerIndexes?.includes(player.player_index) && gameState?.currentPhase === 'mission'" class="relative group/tip">
            <div class="text-green-400 text-lg cursor-default drop-shadow-lg">⚔️</div>
            <div class="absolute bottom-full right-0 mb-1.5 px-2 py-1 bg-black/90 border border-green-500/30 text-green-200 text-xs rounded whitespace-nowrap invisible group-hover/tip:visible opacity-0 group-hover/tip:opacity-100 transition-opacity z-20 pointer-events-none">
              On the active mission team
            </div>
          </div>
        </div>
      </div>

      <!-- Name -->
      <div class="text-white font-semibold text-sm">{{ player.name }}</div>

      <!-- Vote indicator -->
      <div
          v-if="latestProposal?.votes && latestProposal.votes[player.player_index] !== undefined"
          class="relative group/tip mt-1"
      >
        <span class="text-sm cursor-default">{{ latestProposal.votes[player.player_index] ? '👍' : '👎' }}</span>
        <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 bg-black/90 border border-white/20 text-white/80 text-xs rounded whitespace-nowrap invisible group-hover/tip:visible opacity-0 group-hover/tip:opacity-100 transition-opacity z-20 pointer-events-none">
          {{ latestProposal.votes[player.player_index] ? 'Voted to approve' : 'Voted to reject' }}
        </div>
      </div>

      <!-- Role & Type -->
      <div class="flex flex-col items-center gap-1 mt-1">
        <div class="text-white/50 text-xs">
          {{ player.is_human ? '🧑 Human' : '🤖 AI' }}
        </div>
        <div v-if="player.knownEvil" class="text-xs font-medium px-1.5 py-0.5 rounded bg-red-900/40 text-red-300">
          Evil
        </div>
        <div v-else-if="player.roleLabel" :class="[
          'text-xs font-medium px-1.5 py-0.5 rounded',
          player.role?.includes('minion') || player.role?.includes('assassin')
            ? 'bg-red-900/40 text-red-300'
            : 'bg-blue-900/40 text-blue-300'
        ]">
          {{ player.roleLabel }}
        </div>
      </div>

      <!-- Mission dots -->
      <div class="flex gap-1.5 mt-2 justify-center">
        <template v-for="mission in missions" :key="mission.mission_number">
          <div
              v-if="mission.status !== 'pending' && mission.result?.team?.includes(player.name)"
              class="relative group/tip"
          >
            <div :class="[
              'w-3 h-3 rounded-full transition-all duration-300',
              mission.status === 'success' ? 'bg-green-500' : mission.status === 'fail' ? 'bg-red-500' : 'bg-gray-500',
            ]"/>
            <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 bg-black/90 border border-white/20 text-white/80 text-xs rounded whitespace-nowrap invisible group-hover/tip:visible opacity-0 group-hover/tip:opacity-100 transition-opacity z-20 pointer-events-none">
              Mission {{ mission.mission_number }}: {{ mission.status === 'success' ? '✓ Success' : '✗ Failed' }}
            </div>
          </div>
        </template>
      </div>

      <!-- Hover detail overlay -->
      <div v-if="isGameFinished && (player.id === assassination?.assassin?.player_id || player.id === assassination?.target?.player_id)"
           class="mt-2 overflow-hidden max-h-0 group-hover:max-h-20 transition-all duration-300 text-xs leading-relaxed">
        <span v-if="player.id === assassination?.assassin?.player_id" class="text-red-400">🗡️ Assassin </span>
        <span v-if="player.id === assassination?.target?.player_id" class="text-yellow-400">🎯 Target </span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type {Player, GameState} from "../../types/game";
import {computed} from "vue";

const props = defineProps<{
  players: Player[]
  gameState: GameState
}>()

const aiNames = ['max', 'alex', 'sam', 'jordan', 'riley', 'taylor', 'morgan', 'jamie']
const getAvatarUrl = (name: string) => {
  const key = name.toLowerCase()
  return aiNames.includes(key) ? `/avatars/${key}.png` : '/avatars/default.png'
}

const latestProposal = computed(() => {
  if (!props.gameState.proposals || props.gameState.proposals.length === 0) return null
  if (!props.gameState.currentMission) return null
  if (props.gameState.currentPhase !== 'mission') return null
  return props.gameState.proposals[props.gameState.proposals.length - 1]
})

const currentLeader = computed(() => props.gameState.currentLeader ?? null)
const missions = computed(() => props.gameState.missions ?? [])
const currentProposal = computed(() => props.gameState.currentProposal ?? null)
const assassination = computed(() => props.gameState.assassination)
const isGameFinished = computed(() => props.gameState.currentPhase === 'finished' || props.gameState.currentPhase === 'debrief')
</script>
