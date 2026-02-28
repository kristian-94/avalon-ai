<template>
  <div class="lg:col-span-1 bg-black/40 backdrop-blur-sm rounded-lg shadow">
    <div class="h-[600px] flex flex-col">
      <div class="p-4 border-b border-white/20">
        <h2 class="text-xl font-bold text-white">Game History</h2>
      </div>
      <div ref="eventsContainer" class="flex-1 overflow-y-auto p-4 space-y-3">
        <div v-for="event in events" :key="event.id"
             :class="['rounded-lg px-3 py-2 text-sm', eventClass(event)]">
          {{ formatEvent(event) }}
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
    case 'assassination':
      return `Assassin targeted ${data.assassin_target?.player_name || 'unknown'}`
    case 'game_end':
      return data.winner === 'good' ? 'Good wins' : 'Evil wins'
    default:
      return `Event: ${event.event_type}`
  }
}
</script>
