<template>
  <div class="lg:col-span-1 bg-black/40 backdrop-blur-sm rounded-lg shadow">
    <div class="h-[600px] flex flex-col">
      <div class="p-4 border-b border-white/20">
        <h2 class="text-xl font-bold text-white">Game Chat</h2>
      </div>
      <ChatMessages :messages="messages" :playerId="playerId"/>
      <ChatInput @sendMessage="$emit('send-message', $event)"/>
    </div>
  </div>
</template>

<script setup lang="ts">
import ChatMessages from './ChatMessages.vue'
import ChatInput from './ChatInput.vue'
import type {Message} from "../../types/game";

defineProps<{
  playerId: number
  messages: Message[]
}>()

defineEmits<{
  (e: 'send-message', message: string): void
}>()
</script>