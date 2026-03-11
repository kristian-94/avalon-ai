<template>
  <div class="mt-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
    <div
        v-for="player in players"
        :key="player.id"
        :class="[
        'group relative bg-black/40 backdrop-blur-sm rounded-lg p-4 transition-all duration-200 flex flex-col items-center text-center',
        'hover:bg-black/60 hover:scale-[1.03] hover:shadow-xl hover:shadow-black/40',
        isOnMission(player.player_index)
          ? 'ring-4 ring-green-400 shadow-lg shadow-green-500/40'
          : isProposed(player.player_index)
            ? 'ring-4 ring-blue-400 shadow-lg shadow-blue-500/30'
            : isAssassinationPhase && isEvilPlayer(player)
              ? 'ring-4 ring-red-500 shadow-lg shadow-red-500/40'
              : !isAssassinationPhase && player.id === currentLeader
                ? 'ring-2 ring-yellow-500/50'
                : '',
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
          <div v-if="!isAssassinationPhase && player.id === currentLeader" class="relative group/tip">
            <div class="text-yellow-500 text-lg cursor-default drop-shadow-lg">👑</div>
            <div class="absolute bottom-full right-0 mb-1.5 px-2 py-1 bg-black/90 border border-yellow-500/30 text-yellow-200 text-xs rounded whitespace-nowrap invisible group-hover/tip:visible opacity-0 group-hover/tip:opacity-100 transition-opacity z-20 pointer-events-none">
              Current Leader — proposes the mission team
            </div>
          </div>
          <div v-if="isAssassinationPhase && isAssassin(player)" class="relative group/tip">
            <div class="text-red-400 text-lg cursor-default drop-shadow-lg">🗡️</div>
            <div class="absolute bottom-full right-0 mb-1.5 px-2 py-1 bg-black/90 border border-red-500/30 text-red-200 text-xs rounded whitespace-nowrap invisible group-hover/tip:visible opacity-0 group-hover/tip:opacity-100 transition-opacity z-20 pointer-events-none">
              Assassin — choosing a target
            </div>
          </div>
          <div v-if="isProposed(player.player_index)" class="relative group/tip">
            <div class="text-blue-400 text-lg cursor-default drop-shadow-lg">🛡️</div>
            <div class="absolute bottom-full right-0 mb-1.5 px-2 py-1 bg-black/90 border border-blue-500/30 text-blue-200 text-xs rounded whitespace-nowrap invisible group-hover/tip:visible opacity-0 group-hover/tip:opacity-100 transition-opacity z-20 pointer-events-none">
              Proposed for this mission
            </div>
          </div>
          <div v-if="isOnMission(player.player_index)" class="relative group/tip">
            <div class="text-green-400 text-lg cursor-default drop-shadow-lg">⚔️</div>
            <div class="absolute bottom-full right-0 mb-1.5 px-2 py-1 bg-black/90 border border-green-500/30 text-green-200 text-xs rounded whitespace-nowrap invisible group-hover/tip:visible opacity-0 group-hover/tip:opacity-100 transition-opacity z-20 pointer-events-none">
              On the active mission team
            </div>
          </div>
        </div>
      </div>

      <!-- Name -->
      <div class="text-white font-semibold text-sm">{{ player.name }}</div>

      <!-- Vote indicator: live votes during voting phase -->
      <div
          v-if="liveVotes && liveVotes[player.player_index] !== undefined"
          class="mt-1 vote-indicator vote-enter"
      >
        <span class="text-2xl font-black cursor-default drop-shadow-lg" :class="liveVotes[player.player_index] ? 'text-green-400' : 'text-red-400'">
          {{ liveVotes[player.player_index] ? '✓' : '✗' }}
        </span>
      </div>

      <!-- Vote indicator: fading out after phase transition -->
      <div
          v-if="fadeVotes && fadeVotes[player.player_index] !== undefined && !(liveVotes && liveVotes[player.player_index] !== undefined)"
          class="mt-1 vote-indicator transition-all duration-700"
          :class="showFadeVotes ? 'opacity-100 scale-100' : 'opacity-0 scale-75'"
      >
        <span class="text-2xl font-black cursor-default drop-shadow-lg" :class="fadeVotes[player.player_index] ? 'text-green-400' : 'text-red-400'">
          {{ fadeVotes[player.player_index] ? '✓' : '✗' }}
        </span>
      </div>

      <!-- Vote indicator: during mission phase (existing behavior) -->
      <div
          v-if="latestProposal?.votes && latestProposal.votes[player.player_index] !== undefined && !fadeVotes"
          class="relative group/tip mt-1"
      >
        <span class="text-sm font-bold cursor-default" :class="latestProposal.votes[player.player_index] ? 'text-green-400' : 'text-red-400'">{{ latestProposal.votes[player.player_index] ? '✓' : '✗' }}</span>
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
import {computed, ref, watch} from "vue";

const props = defineProps<{
  players: Player[]
  gameState: GameState
}>()

const aiNames = ['max', 'alex', 'sam', 'jordan', 'riley', 'taylor', 'morgan', 'jamie']
const getAvatarUrl = (name: string) => {
  const key = name.toLowerCase()
  return aiNames.includes(key) ? `/avatars/${key}.png` : '/avatars/default.png'
}

// Track vote results that should fade out after phase transition
const fadeVotes = ref<Record<number, boolean> | null>(null)
const showFadeVotes = ref(false)
let fadeTimeout: ReturnType<typeof setTimeout> | null = null

const latestProposal = computed(() => {
  if (!props.gameState.proposals || props.gameState.proposals.length === 0) return null
  if (!props.gameState.currentMission) return null
  if (props.gameState.currentPhase !== 'mission') return null
  return props.gameState.proposals[props.gameState.proposals.length - 1]
})

// Watch for phase changes — when leaving team_voting, capture the votes and fade them out
watch(() => props.gameState.currentPhase, (newPhase, oldPhase) => {
  if (oldPhase === 'team_voting' && newPhase !== 'team_voting') {
    // Try to get votes from multiple sources
    let votes: Record<number, boolean> | null = null

    // First try the proposals array
    const proposals = props.gameState.proposals
    if (proposals && proposals.length > 0) {
      const lastProposal = proposals[proposals.length - 1]
      if (lastProposal?.votes && Object.keys(lastProposal.votes).length > 0) {
        votes = { ...lastProposal.votes }
      }
    }

    // Fallback: use liveVotes snapshot (from currentProposal)
    if (!votes && liveVotes.value) {
      votes = { ...liveVotes.value }
    }

    if (votes && Object.keys(votes).length > 0) {
      fadeVotes.value = votes
      showFadeVotes.value = true
      if (fadeTimeout) clearTimeout(fadeTimeout)
      fadeTimeout = setTimeout(() => {
        showFadeVotes.value = false
        fadeTimeout = setTimeout(() => {
          fadeVotes.value = null
        }, 700) // allow CSS transition to complete
      }, 9000)
    }
  }
})

// Show votes during team_voting as they come in, and briefly after transition
const liveVotes = computed((): Record<number, boolean> | null => {
  if (props.gameState.currentPhase !== 'team_voting') return null

  // Check currentProposal (uses player_index keys during voting)
  const cp = props.gameState.currentProposal
  if (cp?.votes && Object.keys(cp.votes).length > 0) {
    // Convert string keys to number keys if needed
    const votes: Record<number, boolean> = {}
    for (const [key, val] of Object.entries(cp.votes)) {
      // Key might be player name or player index
      const idx = parseInt(key)
      if (!isNaN(idx)) {
        votes[idx] = val as boolean
      } else {
        // It's a player name, find the index
        const player = props.players.find(p => p.name === key)
        if (player) votes[player.player_index] = val as boolean
      }
    }
    if (Object.keys(votes).length > 0) return votes
  }

  // Fallback to proposals array
  const proposals = props.gameState.proposals
  if (!proposals || proposals.length === 0) return null
  const current = proposals[proposals.length - 1]
  return current?.votes ?? null
})

const currentLeader = computed(() => props.gameState.currentLeader ?? null)
const missions = computed(() => props.gameState.missions ?? [])
const currentProposal = computed(() => props.gameState.currentProposal ?? null)
const assassination = computed(() => props.gameState.assassination)
const isGameFinished = computed(() => props.gameState.currentPhase === 'finished' || props.gameState.currentPhase === 'debrief')

const isProposed = (playerIndex: number) => {
  const phase = props.gameState?.currentPhase
  if (phase !== 'team_proposal' && phase !== 'team_voting') return false
  return props.gameState?.currentProposal?.playerIndexes?.includes(playerIndex) ?? false
}

const isOnMission = (playerIndex: number) => {
  if (props.gameState?.currentPhase !== 'mission') return false
  return props.gameState?.currentMission?.playerIndexes?.includes(playerIndex) ?? false
}

const isAssassinationPhase = computed(() => {
  const phase = props.gameState?.currentPhase
  return phase === 'assassination' || phase === 'evil_discussion'
})

const isEvilPlayer = (player: Player) => {
  return player.role === 'assassin' || player.role === 'minion' || player.knownEvil === true
}

const isAssassin = (player: Player) => {
  return player.role === 'assassin'
}
</script>

<style scoped>
.vote-indicator {
  display: flex;
  justify-content: center;
}

.vote-enter {
  animation: vote-pop 0.3s ease-out;
}

@keyframes vote-pop {
  0% {
    opacity: 0;
    transform: scale(0.3);
  }
  60% {
    transform: scale(1.2);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}
</style>
