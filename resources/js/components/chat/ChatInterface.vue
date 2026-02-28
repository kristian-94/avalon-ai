<template>
  <div class="lg:col-span-1 bg-black/40 backdrop-blur-sm rounded-lg shadow">
    <div class="h-[600px] flex flex-col">
      <div class="p-4 border-b border-white/20">
        <h2 class="text-xl font-bold text-white">Game Chat</h2>
      </div>
      <ChatMessages
          :messages="messages"
          :playerId="playerId"
          :players="players"
          :rolesRevealed="gameState?.currentPhase === 'debrief' || gameState?.currentPhase === 'finished'"
      />
      <ChatInput
          ref="chatInputRef"
          @sendMessage="$emit('send-message', $event)"
          @focus-panel="focusActionPanel"
      />
      <HumanActionPanel
          v-if="gameState && gameId"
          ref="actionPanel"
          :game-state="gameState"
          :player-id="playerId"
          :players="players"
          :game-id="gameId"
          @return-focus="focusChatInput"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref} from 'vue'
import ChatMessages from './ChatMessages.vue'
import ChatInput from './ChatInput.vue'
import HumanActionPanel from '../game/HumanActionPanel.vue'
import type {Message, GameState, Player} from "../../types/game";

defineProps<{
  playerId: number
  messages: Message[]
  gameState: GameState | null
  players: Player[]
  gameId: number
}>()

defineEmits<{
  (e: 'send-message', message: string): void
}>()

const actionPanel = ref<InstanceType<typeof HumanActionPanel> | null>(null)
const chatInputRef = ref<InstanceType<typeof ChatInput> | null>(null)

const focusActionPanel = () => actionPanel.value?.focusPanel()
const focusChatInput = () => chatInputRef.value?.focus()
</script>
