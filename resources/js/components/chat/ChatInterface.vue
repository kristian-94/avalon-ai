<template>
  <div class="lg:col-span-1 bg-black/40 backdrop-blur-sm rounded-lg shadow">
    <div class="h-[600px] flex flex-col">
      <div class="p-4 border-b border-white/20">
        <h2 class="text-xl font-bold text-white">Game Chat</h2>
      </div>
      <ChatMessages :messages="messages"/>
      <ChatInput @sendMessage="addMessage"/>
    </div>
  </div>
</template>

<script setup lang="ts">
import ChatMessages from './ChatMessages.vue'
import ChatInput from './ChatInput.vue'
import {ref} from 'vue'

const messages = ref([
  {
    id: 1,
    sender: 'System',
    text: 'Welcome to Avalon! The game will begin shortly.',
    isSystem: true
  },
  {
    id: 2,
    sender: 'Merlin',
    text: 'I sense a disturbance in the realm...',
    isSystem: false
  }
]);


const addMessage = (message: string) => {
  let messageObj = {
    id: messages.value.length + 1,
    sender: 'You',
    text: message,
    isSystem: false
  }
  messages.value.push(messageObj)
}
</script>
