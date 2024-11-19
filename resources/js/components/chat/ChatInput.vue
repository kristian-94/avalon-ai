<template>
  <div class="p-4 border-t border-white/20">
    <div class="flex gap-2">
      <input
          v-model="inputMessage"
          type="text"
          class="flex-1 rounded-lg border-none px-4 py-2
        bg-white/20 text-white placeholder-white/70
        focus:outline-none focus:ring-2 focus:ring-white/50"
          placeholder="Type a message..."
          @keyup.enter="handleSend"
      />
      <button
          @click="handleSend"
          class="bg-white/20 text-white px-4 py-2 rounded-lg
        hover:bg-white/30 focus:outline-none focus:ring-2 focus:ring-white/50"
      >
        Send
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import {defineEmits, ref} from 'vue'

const inputMessage = ref('')

const emit = defineEmits(['sendMessage'])

const handleSend = () => {
  if (!inputMessage.value.trim()) return
  emit('sendMessage', inputMessage.value)
  inputMessage.value = ''
}
</script>