<template>
  <div class="flex justify-center gap-4 mb-8">
    <div
        v-for="(mission, index) in missions"
        :key="index"
        class="relative group"
    >
      <div
          class="w-12 h-12 rounded-full border-2 flex items-center justify-center cursor-help
          transition-colors duration-200"
          :class="[
          mission.result === null 
            ? currentMissionId === mission.id
              ? 'border-blue-500 ring-4 ring-blue-500/20 text-white'
              : 'border-white/50 text-white/90'
            : mission.result.success
              ? 'border-emerald-500 bg-emerald-500/20 text-emerald-300'
              : 'border-red-500 bg-red-500/20 text-red-300'
        ]"
      >
        {{ mission.required }}
      </div>
      <!-- Hover tooltip -->
      <div
          class="absolute bottom-full mb-2 p-2 bg-black/80 rounded-lg text-white text-sm
          opacity-0 group-hover:opacity-100 transition-opacity duration-200
          pointer-events-none whitespace-nowrap"
          :class="[index > 2 ? 'right-0' : 'left-0']"
      >
        <template v-if="mission.result">
          Mission {{ index + 1 }}: {{ mission.result.success ? 'Success' : 'Fail' }}
          <br>
          Team: {{ mission.result.team.join(', ') }}
        </template>
        <template v-else>
          Mission {{ index + 1 }}
          <br>
          Requires {{ mission.required }} players
        </template>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type {Mission} from '../../types/game'

const props = defineProps<{
  missions: Mission[]
  currentMissionId?: number
}>()

</script>