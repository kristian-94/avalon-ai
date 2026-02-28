<template>
  <div
      v-if="showPanel"
      ref="panelRef"
      tabindex="0"
      @keydown="handleKeydown"
      @focus="isFocused = true"
      @blur="isFocused = false"
      class="p-4 border-t border-white/20 focus:outline-none"
  >
    <!-- Submission confirmation -->
    <div v-if="submittedMessage" class="text-green-400 text-center py-2">
      {{ submittedMessage }}
    </div>

    <!-- Voting UI -->
    <div v-else-if="showVoting" class="space-y-2">
      <div class="text-white/70 text-sm mb-2">
        Vote on the proposed team
        <span class="text-white/40 text-xs ml-1">(← Approve · Reject → · Enter confirm · ↑ back)</span>
      </div>
      <div class="flex gap-2">
        <button
            @click="submitVote(true)"
            :class="[
              'flex-1 px-4 py-2 rounded-lg transition-colors',
              focusedVote === true && isFocused
                ? 'bg-green-500/70 text-white ring-2 ring-green-400'
                : 'bg-green-600/40 text-green-300 hover:bg-green-600/60',
            ]"
        >&#10003; Approve</button>
        <button
            @click="submitVote(false)"
            :class="[
              'flex-1 px-4 py-2 rounded-lg transition-colors',
              focusedVote === false && isFocused
                ? 'bg-red-500/70 text-white ring-2 ring-red-400'
                : 'bg-red-600/40 text-red-300 hover:bg-red-600/60',
            ]"
        >&#10007; Reject</button>
      </div>
    </div>

    <!-- Proposal UI -->
    <div v-else-if="showProposal" class="space-y-2">
      <div class="text-white/70 text-sm mb-2">
        Select {{ requiredCount }} players
        <span class="text-white/40 text-xs ml-1">(← → navigate · Space select · Enter submit)</span>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
            v-for="(player, index) in players"
            :key="player.id"
            @click="togglePlayer(player.id)"
            :class="[
              'px-3 py-1 rounded-full text-sm transition-colors',
              selectedIds.includes(player.id)
                ? 'bg-blue-500/60 text-white'
                : 'bg-white/10 text-white/70 hover:bg-white/20',
              focusedPlayerIndex === index && isFocused
                ? 'ring-2 ring-white/70'
                : '',
            ]"
        >{{ player.name }}</button>
      </div>
      <button
          @click="submitProposal"
          :disabled="selectedIds.length !== requiredCount"
          :class="[
            'w-full px-4 py-2 rounded-lg',
            selectedIds.length === requiredCount
              ? 'bg-blue-600/40 text-blue-300 hover:bg-blue-600/60'
              : 'bg-white/10 text-white/30 cursor-not-allowed',
          ]"
      >Propose Team ({{ selectedIds.length }}/{{ requiredCount }})</button>
    </div>

    <!-- Assassination UI -->
    <div v-else-if="showAssassination" class="space-y-2">
      <div class="text-white/70 text-sm mb-2">
        Choose who you think is Merlin to assassinate
        <span class="text-white/40 text-xs ml-1">(← → navigate · Enter confirm)</span>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
            v-for="(player, index) in assassinationTargets"
            :key="player.id"
            @click="submitAssassination(player.id)"
            :class="[
              'px-3 py-1 rounded-full text-sm transition-colors',
              focusedAssassinIndex === index && isFocused
                ? 'ring-2 ring-red-400 bg-red-600/60 text-white'
                : 'bg-red-900/40 text-red-300 hover:bg-red-600/40',
            ]"
        >{{ player.name }}</button>
      </div>
    </div>

    <!-- Mission UI -->
    <div v-else-if="showMission" class="space-y-2">
      <div class="text-white/70 text-sm mb-2">
        You are on this mission
        <span class="text-white/40 text-xs ml-1">(Enter to play{{ isEvilPlayer ? ' · ← Success · Fail →' : '' }})</span>
      </div>
      <div v-if="isEvilPlayer" class="flex gap-2">
        <button
            @click="submitMissionAction(true)"
            :class="[
              'flex-1 px-4 py-2 rounded-lg transition-colors',
              focusedMission === true && isFocused
                ? 'bg-green-500/70 text-white ring-2 ring-green-400'
                : 'bg-green-600/40 text-green-300 hover:bg-green-600/60',
            ]"
        >Play Success</button>
        <button
            @click="submitMissionAction(false)"
            :class="[
              'flex-1 px-4 py-2 rounded-lg transition-colors',
              focusedMission === false && isFocused
                ? 'bg-red-500/70 text-white ring-2 ring-red-400'
                : 'bg-red-600/40 text-red-300 hover:bg-red-600/60',
            ]"
        >Play Fail</button>
      </div>
      <button
          v-else
          @click="submitMissionAction(true)"
          class="w-full bg-green-600/40 text-green-300 px-4 py-2 rounded-lg hover:bg-green-600/60"
      >Play Success</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref, computed, watch} from 'vue'
import axios from 'axios'
import type {GameState, Player} from "../../types/game"

const props = defineProps<{
  gameState: GameState | null
  playerId: number
  players: Player[]
  gameId: number
}>()

const emit = defineEmits<{
  (e: 'return-focus'): void
}>()

const panelRef = ref<HTMLElement | null>(null)
const selectedIds = ref<number[]>([])
const missionSubmitted = ref(false)
const submittedMessage = ref('')
const isFocused = ref(false)
const focusedVote = ref<boolean | null>(true)    // default highlight: Approve
const focusedMission = ref<boolean>(true)        // default highlight: Success
const focusedPlayerIndex = ref(0)
const focusedAssassinIndex = ref(0)
const assassinationSubmitted = ref(false)

const humanPlayerIndex = computed(() =>
    props.players.find(p => p.id === props.playerId)?.player_index
)

const isEvilPlayer = computed(() => {
  const role = props.players.find(p => p.id === props.playerId)?.role
  return role === 'assassin' || role === 'minion'
})

// Reset missionSubmitted when a new mission starts
watch(() => props.gameState?.currentMission?.id, () => { missionSubmitted.value = false })

const showVoting = computed(() => {
  if (props.gameState?.currentPhase !== 'team_voting') return false
  if (props.gameState?.currentLeader === props.playerId) return false
  const votes = props.gameState?.currentProposal?.votes || {}
  return votes[props.playerId] === undefined
})

const showProposal = computed(() => {
  if (props.gameState?.currentPhase !== 'team_proposal') return false
  if (props.gameState?.currentLeader !== props.playerId) return false
  return !props.gameState?.currentProposal
})

const showAssassination = computed(() => {
  if (props.gameState?.currentPhase !== 'assassination') return false
  const role = props.players.find(p => p.id === props.playerId)?.role
  return role === 'assassin' && !assassinationSubmitted.value
})

const assassinationTargets = computed(() =>
  props.players.filter(p => p.role !== 'assassin' && p.role !== 'minion')
)

const showMission = computed(() => {
  if (props.gameState?.currentPhase !== 'mission') return false
  if (humanPlayerIndex.value === undefined) return false
  const onTeam = props.gameState?.currentMission?.playerIndexes?.includes(humanPlayerIndex.value)
  return onTeam && !missionSubmitted.value
})

const showPanel = computed(() =>
    !!submittedMessage.value || showVoting.value || showProposal.value || showMission.value || showAssassination.value
)

const requiredCount = computed(() => props.gameState?.currentMission?.required || 0)

// ── Keyboard handler ────────────────────────────────────────────────────────

const handleKeydown = (e: KeyboardEvent) => {
  if (showVoting.value) {
    if (e.key === 'ArrowRight') { focusedVote.value = false; e.preventDefault() }
    else if (e.key === 'ArrowLeft')  { focusedVote.value = true;  e.preventDefault() }
    else if (e.key === 'Enter' && focusedVote.value !== null) { submitVote(focusedVote.value); e.preventDefault() }

  } else if (showProposal.value) {
    if (e.key === 'ArrowRight') {
      focusedPlayerIndex.value = (focusedPlayerIndex.value + 1) % props.players.length
      e.preventDefault()
    } else if (e.key === 'ArrowLeft') {
      focusedPlayerIndex.value = (focusedPlayerIndex.value - 1 + props.players.length) % props.players.length
      e.preventDefault()
    } else if (e.key === ' ') {
      togglePlayer(props.players[focusedPlayerIndex.value]?.id)
      e.preventDefault()
    } else if (e.key === 'Enter' && selectedIds.value.length === requiredCount.value) {
      submitProposal()
      e.preventDefault()
    }

  } else if (showAssassination.value) {
    if (e.key === 'ArrowRight') { focusedAssassinIndex.value = (focusedAssassinIndex.value + 1) % assassinationTargets.value.length; e.preventDefault() }
    else if (e.key === 'ArrowLeft') { focusedAssassinIndex.value = (focusedAssassinIndex.value - 1 + assassinationTargets.value.length) % assassinationTargets.value.length; e.preventDefault() }
    else if (e.key === 'Enter') { submitAssassination(assassinationTargets.value[focusedAssassinIndex.value]?.id); e.preventDefault() }

  } else if (showMission.value) {
    if (isEvilPlayer.value) {
      if (e.key === 'ArrowRight') { focusedMission.value = false; e.preventDefault() }
      else if (e.key === 'ArrowLeft') { focusedMission.value = true; e.preventDefault() }
      else if (e.key === 'Enter') { submitMissionAction(focusedMission.value); e.preventDefault() }
    } else {
      if (e.key === 'Enter') { submitMissionAction(true); e.preventDefault() }
    }
  }

  if (e.key === 'Escape' || e.key === 'ArrowUp') { emit('return-focus'); e.preventDefault() }
}

// ── Actions ─────────────────────────────────────────────────────────────────

const togglePlayer = (id: number) => {
  const idx = selectedIds.value.indexOf(id)
  if (idx >= 0) {
    selectedIds.value.splice(idx, 1)
  } else if (selectedIds.value.length < requiredCount.value) {
    selectedIds.value.push(id)
  }
}

const showConfirmation = (message: string) => {
  submittedMessage.value = message
  emit('return-focus')
  setTimeout(() => { submittedMessage.value = '' }, 2000)
}

const submitVote = async (approve: boolean) => {
  try {
    await axios.post('/api/game/vote', { gameId: props.gameId, playerId: props.playerId, approved: approve })
    showConfirmation(approve ? '✓ Approved' : '✓ Rejected')
  } catch (err) {
    console.error('Failed to submit vote:', err)
  }
}

const submitProposal = async () => {
  if (selectedIds.value.length !== requiredCount.value) return
  try {
    await axios.post('/api/game/propose', { gameId: props.gameId, playerId: props.playerId, playerIds: selectedIds.value })
    selectedIds.value = []
    showConfirmation('✓ Proposal submitted')
  } catch (err) {
    console.error('Failed to submit proposal:', err)
  }
}

const submitAssassination = async (targetPlayerId: number) => {
  if (!targetPlayerId) return
  try {
    await axios.post('/api/game/assassinate', { gameId: props.gameId, playerId: props.playerId, targetPlayerId })
    assassinationSubmitted.value = true
    showConfirmation('🗡️ Assassination target chosen')
  } catch (err) {
    console.error('Failed to submit assassination:', err)
  }
}

const submitMissionAction = async (success: boolean) => {
  try {
    await axios.post('/api/game/mission-action', { gameId: props.gameId, playerId: props.playerId, success })
    missionSubmitted.value = true
    showConfirmation('✓ Mission card played')
  } catch (err) {
    console.error('Failed to submit mission action:', err)
  }
}

// ── Exposed for parent focus wiring ─────────────────────────────────────────

const focusPanel = () => {
  focusedVote.value = true
  focusedPlayerIndex.value = 0
  panelRef.value?.focus()
}

defineExpose({ focusPanel })
</script>
