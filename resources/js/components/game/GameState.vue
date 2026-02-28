<template>
  <div class="lg:col-span-1 rounded-lg p-4">
    <MissionTracker
        :missions="gameState?.missions || []"
        :currentMissionId="gameState?.currentMission?.id || null"
    />

    <!-- Game Progress -->
    <div v-if="gameState?.currentPhase === 'finished' || gameState?.currentPhase === 'debrief'" class="mb-4">
      <VictoryScreen :game-state="gameState" :players="players" :game="game" :phase="gameState?.currentPhase"/>
      <div v-if="gameState?.currentPhase === 'debrief'" class="mt-2 text-center text-white/50 text-sm animate-pulse">
        Debrief in progress…
      </div>
    </div>
    <div v-else class="mb-4 bg-black/40 backdrop-blur-sm rounded-lg p-4">
      <div class="text-white/70">

        <div class="flex items-center w-full">
          <template v-for="(phase, index) in phases" :key="phase.id">
            <!-- Arrow separator -->
            <template v-if="index > 0">
              <div class="flex-1 flex items-center mx-2">
                <div :class="['h-0.5 flex-1', isPhaseComplete(phases[index - 1].id) ? 'bg-green-500/60' : 'bg-white/20']"></div>
                <span :class="['text-2xl font-bold mx-1 leading-none', isPhaseComplete(phases[index - 1].id) ? 'text-green-500/80' : 'text-white/30']">›</span>
                <div :class="['h-0.5 flex-1', isPhaseComplete(phases[index - 1].id) ? 'bg-green-500/60' : 'bg-white/20']"></div>
              </div>
            </template>

            <!-- Phase node -->
            <div class="flex flex-col items-center gap-1.5">
              <div :class="[
                'rounded-full flex items-center justify-center font-bold transition-all duration-300',
                phase.id === gameState?.currentPhase
                  ? 'w-12 h-12 text-lg bg-blue-500 text-white ring-8 ring-blue-400/25 shadow-lg shadow-blue-500/40 animate-pulse'
                  : isPhaseComplete(phase.id)
                    ? 'w-9 h-9 text-base bg-green-500 text-white'
                    : 'w-9 h-9 text-sm bg-white/10 text-white/30',
              ]">
                <span v-if="isPhaseComplete(phase.id)">✓</span>
                <span v-else>{{ index + 1 }}</span>
              </div>
              <span :class="[
                'whitespace-nowrap font-medium',
                phase.id === gameState?.currentPhase ? 'text-sm text-blue-400'
                  : isPhaseComplete(phase.id) ? 'text-xs text-green-400'
                  : 'text-xs text-white/30'
              ]">{{ phase.label }}</span>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Game Phase Banner -->
    <div v-if="gameState?.currentPhase !== 'finished'">
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
        <div v-else-if="gameState?.currentPhase === 'assassination'" class="mt-2 text-red-500 text-2xl text-center">
          Assassination phase
        </div>
      </div>
    </div>

    <PlayerArea :players="players" :gameState="props.gameState"/>
  </div>
</template>

<script setup lang="ts">
import PlayerArea from './PlayerArea.vue'
import MissionTracker from "./MissionTracker.vue"
import {computed} from 'vue'
import VictoryScreen from './VictoryScreen.vue'
import type {Game, GameState, Player} from "../../types/game";

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
  gameState: GameState | null
  players: Player[]
  game: Game
}>()

const phases = [
  {id: 'team_proposal', label: 'Propose Team'},
  {id: 'team_voting', label: 'Vote Team'},
  {id: 'mission', label: 'Mission'}, // quick phase of just getting a vote, no discussions.
] as const

// Helper to determine if a phase is complete in the current round
const isPhaseComplete = (phaseId: string) => {
  if (!props.gameState?.currentPhase) return false

  const currentPhaseIndex = phases.findIndex(p => p.id === props.gameState?.currentPhase)
  const thisPhaseIndex = phases.findIndex(p => p.id === phaseId)

  return thisPhaseIndex < currentPhaseIndex
}

</script>