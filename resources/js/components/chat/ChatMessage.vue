<template>
  <div class="flex flex-col">
    <div :class="[
      'max-w-[80%] rounded-lg p-3 transition-all duration-300',
      message.isSystem
        ? 'bg-white/10 text-white self-center'
        : isOwnMessage
          ? 'bg-blue-500/80 text-white self-end'
          : 'bg-gray-500/80 text-white self-start'
    ]">
      <p v-if="!message.isSystem" class="text-sm font-semibold flex items-center gap-1.5 flex-wrap">
        {{ message.player_name }}
        <span v-if="playerRole" :class="[
          'text-xs font-normal px-1.5 py-0.5 rounded',
          playerRole?.toLowerCase().includes('minion') || playerRole?.toLowerCase().includes('assassin')
            ? 'bg-red-900/60 text-red-300'
            : 'bg-blue-900/60 text-blue-300'
        ]">{{ playerRole }}</span>
      </p>
      <p v-html="message.isSystem ? '<i>' + message.content + '</i>' : message.content"></p>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  message: {
    id: number
    content: string
    player_id?: number
    player_name: string
    created_at: string
    isSystem?: boolean
  }
  isOwnMessage: boolean
  playerRole?: string
}>()
</script>