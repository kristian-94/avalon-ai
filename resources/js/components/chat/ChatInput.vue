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
    <br>
    <button
        @click="handleRunGameLoop"
        class="bg-white/20 text-white px-4 py-2 rounded-lg
        hover:bg-white/30 focus:outline-none focus:ring-2 focus:ring-white/50"
    >
      Run game loop
    </button>
    <button
        @click="handleForceWebsocketGamestate"
        class="bg-white/20 text-white px-4 py-2 rounded-lg
        hover:bg-white/30 focus:outline-none focus:ring-2 focus:ring-white/50"
    >
      Force websocket gamestate
    </button>
    <div id="runGameLoopResponse" class="text-white/70"></div>
  </div>
</template>

<script setup lang="ts">
import {ref, onMounted} from 'vue'

const inputMessage = ref('')
const chatInput = ref(null)

const emit = defineEmits(['sendMessage'])
onMounted(() => {
  chatInput.value?.focus()
})
const handleSend = () => {
  if (!inputMessage.value.trim()) return
  emit('sendMessage', inputMessage.value)
  inputMessage.value = ''
  chatInput.value?.focus()
}

const handleRunGameLoop = () => {
  fetch('http://localhost:8000/api/game/test-ai?runGameLoop=1')
    .then(response => response.json())
    .then(data => {
      const responseElement = document.createElement('div')
      responseElement.textContent = JSON.stringify(data)
      const runGameLoopResponse = document.getElementById('runGameLoopResponse')
      if (runGameLoopResponse.firstChild) {
        runGameLoopResponse?.replaceChild(responseElement, runGameLoopResponse.firstChild)
      } else {
        runGameLoopResponse?.appendChild(responseElement)
      }
    })
    .catch((error) => {
      console.error('Error:', error)
    })
}

const handleForceWebsocketGamestate = () => {
  fetch('http://localhost:8000/api/game/test-ai?forceWebsocketGamestate=1')
    .then(response => response.json())
    .then(data => {
      const responseElement = document.createElement('div')
      responseElement.textContent = JSON.stringify(data)
      const runGameLoopResponse = document.getElementById('runGameLoopResponse')
      if (runGameLoopResponse.firstChild) {
        runGameLoopResponse?.replaceChild(responseElement, runGameLoopResponse.firstChild)
      } else {
        runGameLoopResponse?.appendChild(responseElement)
      }
    })
    .catch((error) => {
      console.error('Error:', error)
    })
}

</script>