<template>
  <div class="bg-black/40 backdrop-blur-sm rounded-lg shadow">
    <div class="h-[200px] flex flex-col">
      <div class="px-4 py-2 border-b border-white/20">
        <h2 class="text-sm font-bold text-white">Game History</h2>
      </div>
      <div ref="eventsContainer" class="flex-1 overflow-y-auto px-3 py-2 space-y-2">
        <div v-for="event in events" :key="event.id"
             :class="['rounded-lg px-3 py-2 text-sm relative', eventClass(event), hasBreakdown(event) ? 'group/evt cursor-default' : '']">
          {{ formatEvent(event) }}
          <!-- Breakdown tooltip on hover -->
          <div v-if="hasBreakdown(event)"
               class="absolute left-0 right-0 top-full mt-1 z-10 bg-black/90 border border-white/20 rounded-lg px-3 py-2 invisible group-hover/evt:visible opacity-0 group-hover/evt:opacity-100 transition-opacity pointer-events-none">
            <template v-if="event.event_type === 'team_vote'">
              <div v-for="v in event.event_data.breakdown" :key="v.player" class="flex items-center gap-2 text-xs">
                <span :class="v.approved ? 'text-green-400' : 'text-red-400'">{{ v.approved ? '✓' : '✗' }}</span>
                <span class="text-white/80">{{ v.player }}</span>
                <span v-if="v.player === event.event_data.proposed_by" class="text-yellow-400" title="Team Leader">&#9878;</span>
              </div>
            </template>
            <template v-else-if="event.event_type === 'mission_complete'">
              <div v-for="v in event.event_data.breakdown" :key="v.player" class="flex items-center gap-2 text-xs">
                <span v-if="props.rolesRevealed || event.event_data.success" :class="v.success ? 'text-green-400' : 'text-red-400'">{{ v.success ? '✓' : '✗' }}</span>
                <span v-else class="text-white/30">?</span>
                <span class="text-white/80">{{ v.player }}</span>
              </div>
            </template>
          </div>
        </div>
        <div v-if="events.length === 0" class="text-white/50 text-center mt-8">
          No events yet
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref, computed, nextTick} from 'vue'
import type {GameEvent} from "../../types/game"

const props = defineProps<{
  events: GameEvent[]
  rolesRevealed?: boolean
}>()

const eventsContainer = ref<HTMLElement | null>(null)

const latestEventId = computed(() => {
  const id = props.events.length > 0 ? props.events[props.events.length - 1].id : null
  nextTick(() => {
    if (eventsContainer.value) {
      eventsContainer.value.scrollTop = eventsContainer.value.scrollHeight
    }
  })
  return id
})

const eventClass = (event: GameEvent): string => {
  switch (event.event_type) {
    case 'team_proposal':
      return 'bg-blue-500/20 text-blue-300'
    case 'team_vote':
      return event.event_data?.approved
        ? 'bg-green-500/20 text-green-300'
        : 'bg-red-500/20 text-red-300'
    case 'mission_complete':
      return event.event_data?.success
        ? 'bg-green-500/20 text-green-300'
        : 'bg-red-500/20 text-red-300'
    case 'assassination':
      return 'bg-yellow-500/20 text-yellow-300'
    case 'game_start':
    case 'game_end':
      return 'bg-white/10 text-white/80'
    default:
      return 'bg-white/10 text-white/70'
  }
}

const hasBreakdown = (event: GameEvent): boolean => {
  const b = event.event_data?.breakdown
  if (!b || b.length === 0) return false
  if (event.event_type === 'team_vote') return true
  if (event.event_type === 'mission_complete') return true
  return false
}

const formatEvent = (event: GameEvent): string => {
  const data = event.event_data
  switch (event.event_type) {
    case 'game_start':
      return 'Game started'
    case 'team_proposal':
      return `${data.proposed_by} proposed: ${data.team?.join(', ') || 'unknown'}`
    case 'team_vote': {
      const result = data.approved ? 'Approved' : 'Rejected'
      return `${data.votes_for} approve / ${data.votes_against} reject — ${result}`
    }
    case 'mission_complete': {
      const outcome = data.success ? '\u2713 Success' : '\u2717 Failed'
      return `Mission ${data.mission_number} — ${outcome} (${data.fail_votes} fail votes)`
    }
    case 'assassination': {
      const role = data.assassin_target?.player_role || 'unknown role'
      return `Assassin targeted ${data.assassin_target?.player_name || 'unknown'} (${role})`
    }
    case 'game_end': {
      const label = data.winner === 'good' ? 'Good wins' : 'Evil wins'
      return data.reason ? `${label} — ${data.reason}` : label
    }
    default:
      return `Event: ${event.event_type}`
  }
}
</script>
