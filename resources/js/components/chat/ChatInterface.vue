<template>
  <div class="lg:col-span-1 bg-black/40 backdrop-blur-sm rounded-lg shadow">
    <div class="h-[600px] flex flex-col">
      <div class="p-4 border-b border-white/20">
        <h2 class="text-xl font-bold text-white">Game Chat</h2>
      </div>
      <ChatMessages :messages="messages"/>
      <ChatInput @sendMessage="sendMessage"/>
    </div>
  </div>
</template>

<script setup lang="ts">
import ChatMessages from './ChatMessages.vue'
import ChatInput from './ChatInput.vue'
import {ref, onMounted, onUnmounted} from 'vue'
import axios from 'axios'

// Should match what we see in WebSocket:
interface Message {
  id: number
  content: string
  player_id?: number
  player_name: string
  created_at: string
  isSystem?: boolean
}

const props = defineProps<{
  gameId: number
  playerId: number
}>()

let initialMessage = localStorage.getItem('initialMessage') || ''

const messages = ref<Message[]>([
  {id: 1, player_name: 'System', content: initialMessage, isSystem: true, created_at: new Date().toISOString()},
]);

// Send message to backend
const sendMessage = async (messageText: string) => {
  try {
    const response = await axios.post('/api/game/sendMessage', {
      gameId: props.gameId,
      playerId: props.playerId,
      content: messageText
    });
  } catch (error) {
    console.error('Failed to send message:', error);
    // Maybe show an error toast here
  }
}

const addMessage = (message: Message) => {
  messages.value.push(message);
}

onMounted(() => {
  window.Echo.channel(`game.${props.gameId}`)
      .listen('NewMessage', (event: any) => {
        console.log('Raw event:', event);

        const newMessage = {
          id: event.id || messages.value.length + 1,
          player_name: event.player_name || 'Unknown',
          content: event.content,
          created_at: event.created_at,
          isSystem: event.is_system,
        };
        console.log('Processed message:', newMessage);
        addMessage(newMessage);
      });
});
// Clean up WebSocket listener
onUnmounted(() => {
  window.Echo.leave(`game.${props.gameId}`);
});
</script>
