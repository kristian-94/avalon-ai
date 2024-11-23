<template>
  <div class="p-4 border-t border-white/20">
    <div class="flex gap-2">
      <input
          ref="chatInput"
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
import {ref, onMounted} from 'vue'

const inputMessage = ref('')
const chatInput = ref(null)  // Add this line

const emit = defineEmits(['sendMessage'])
onMounted(() => {
  console.log('ChatInput mounted')
  chatInput.value?.focus()
})
const handleSend = () => {
  if (!inputMessage.value.trim()) return
  emit('sendMessage', inputMessage.value)
  inputMessage.value = ''
  chatInput.value?.focus()
}
</script>