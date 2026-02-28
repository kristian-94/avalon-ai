<template>
  <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-4">
    <div v-for="message in messages"
         :key="message.id"
         :class="[
           'transition-all duration-300',
           message.id === latestMessageId ? 'animate-highlight' : ''
         ]">
      <ChatMessage
          :message="message"
          :isOwnMessage="message.player_id === playerId"
          :playerRole="rolesRevealed ? playerRoleMap[message.player_id ?? -1] : undefined"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
import ChatMessage from './ChatMessage.vue'
import type {Message, Player} from "../../types/game";

const props = defineProps<{
  messages: Message[]
  playerId: number
  players?: Player[]
  rolesRevealed?: boolean
}>()

const playerRoleMap = computed(() => {
  const map: Record<number, string> = {}
  props.players?.forEach(p => { if (p.role) map[p.id] = p.roleLabel ?? p.role })
  return map
})

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