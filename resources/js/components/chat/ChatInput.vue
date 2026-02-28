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
          placeholder="Type a message... (↓ to act)"
          @keydown="handleKeydown"
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
const chatInput = ref<HTMLInputElement | null>(null)

const emit = defineEmits<{
  (e: 'sendMessage', message: string): void
  (e: 'focus-panel'): void
}>()

onMounted(() => {
  chatInput.value?.focus()
})

const handleKeydown = (e: KeyboardEvent) => {
  if (e.key === 'Enter') {
    handleSend()
  } else if (e.key === 'ArrowDown') {
    emit('focus-panel')
    e.preventDefault()
  }
}

const handleSend = () => {
  if (!inputMessage.value.trim()) return
  emit('sendMessage', inputMessage.value)
  inputMessage.value = ''
  chatInput.value?.focus()
}

const focus = () => chatInput.value?.focus()

defineExpose({ focus })
</script>
