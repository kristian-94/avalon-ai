<template>
  <div class="lg:col-span-2 rounded-lg p-4">
    <MissionTracker
        :missions="gameState?.missions || []"
        :currentMissionId="gameState?.currentMission?.id || null"
    />

    <!-- Phase Timeline -->
    <div class="mb-4 bg-black/40 backdrop-blur-sm rounded-lg p-4">
      <div class="text-white/70">

        <div class="flex-1 relative flex items-center justify-between">
          <!-- Connecting line -->
          <div class="absolute h-0.5 bg-white/20 left-0 right-0 top-1/2 -translate-y-1/2"></div>

          <!-- Phase dots -->
          <template v-for="(phase, index) in phases" :key="phase.id">
            <div class="relative z-10 flex flex-col items-center gap-2">
              <div
                  :class="[
                  'w-4 h-4 rounded-full transition-colors duration-300',
                  phase.id === gameState?.currentPhase
                    ? 'bg-blue-500 ring-4 ring-blue-500/20'
                    : isPhaseComplete(phase.id)
                      ? 'bg-green-500'
                      : 'bg-white/20',
                ]"
              ></div>
              <div
                  :class="[
                  'text-sm whitespace-nowrap transition-colors duration-300',
                  phase.id === gameState?.currentPhase
                    ? 'text-blue-500'
                    : isPhaseComplete(phase.id)
                      ? 'text-green-500'
                      : 'text-white/60'
                ]"
              >
                {{ phase.label }}
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Game Phase Banner -->
    <div class="mb-6 bg-black/40 backdrop-blur-sm rounded-lg p-4">
      <!-- Phase-specific info -->
      <div v-if="gameState?.currentPhase === 'team_proposal'" class="mt-2 text-white/80">
        Waiting for team leader {{ currentLeaderName }} to propose {{ requiredPlayerCount }} players
      </div>
      <div v-else-if="gameState?.currentPhase === 'team_voting'" class="mt-2 text-white/80">
        Team proposed: {{ playersProposed }}
      </div>
      <div v-else-if="gameState?.currentPhase === 'mission'" class="mt-2 text-white/80">
        Mission team: {{ gameState.currentMission.team.join(', ') }}
      </div>
    </div>

    <PlayerArea :players="players" :gameState="props.gameState"/>
  </div>
</template>

<script setup lang="ts">
import PlayerArea from './PlayerArea.vue'
import MissionTracker from "./MissionTracker.vue"
import { computed } from 'vue'
import {GameState, Player} from "../../types/game";

const currentLeaderName = computed(() => {
  const leader = props.players.find(player => player.id === props.gameState.currentLeader)
  return leader?.name || 'Unknown'
})

const requiredPlayerCount = computed(() => {
  const currentMissionId = props.gameState?.currentMission?.id
  const currentMission = props.gameState?.missions.find(m => m.id === currentMissionId)
  return currentMission?.required || 'error'
})

const playersProposed = computed(() => {
  return props.gameState?.currentProposal?.team.join(', ') || 'error';
})

const props = defineProps<{
  gameId: number
  gameState: GameState | null
  players: Player[]
}>()

const phases = [
  { id: 'team_proposal', label: 'Propose Team' },
  { id: 'team_voting', label: 'Vote Team' },
  { id: 'mission', label: 'Mission' }, // quick phase of just getting a vote, no discussions.
] as const

// Helper to determine if a phase is complete in the current round
const isPhaseComplete = (phaseId: string) => {
  if (!props.gameState?.currentPhase) return false

  const currentPhaseIndex = phases.findIndex(p => p.id === props.gameState?.currentPhase)
  const thisPhaseIndex = phases.findIndex(p => p.id === phaseId)

  return thisPhaseIndex < currentPhaseIndex
}

// Format the phase for display
const formatPhase = (phase?: string) => {
  if (!phase) return ''
  const formats: Record<string, string> = {
    setup: 'Game Setup',
    team_proposal: 'Proposing Team',
    team_voting: 'Voting on Team',
    mission: 'Mission in Progress',
  }
  return formats[phase] || phase
}
</script>