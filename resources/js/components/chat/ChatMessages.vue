<template>
  <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-4">
    <div v-for="message in messages"
         :key="message.id"
         :class="[
           'transition-all duration-300',
           message.id === latestMessageId ? 'animate-highlight' : ''
         ]">
      <ChatMessage :message="message" :isOwnMessage="message.player_id === playerId"/>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
import ChatMessage from './ChatMessage.vue'
import {Message} from "../../types/game";

const props = defineProps<{
  messages: Message[]
  playerId: number
}>()

const messagesContainer = ref<HTMLElement | null>(null)
const latestMessageId = computed(() => {
  const id = props.messages.length > 0 ? props.messages[props.messages.length - 1].id : null
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
  return id
})

onMounted(() => {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
})
</script>

<style>
@keyframes highlight {
  0% {
    background-color: rgba(255, 255, 255, 0.1);
    transform: translateX(-4px);
  }
  100% {
    background-color: transparent;
    transform: translateX(0);
  }
}

.animate-highlight {
  animation: highlight 3s ease-out forwards;
}
</style>