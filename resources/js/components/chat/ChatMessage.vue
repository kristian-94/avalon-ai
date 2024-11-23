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
      <p class="text-sm font-semibold">{{ message.player_name }}</p>
      <p>{{ message.content }}</p>
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
}>()
</script>