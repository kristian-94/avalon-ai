<template>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <GameState :game-id="gameId"/>
    <ChatInterface :game-id="gameId"/>
  </div>
</template>

<script setup lang="ts">
import ChatInterface from "../chat/ChatInterface.vue";
import GameState from "./GameState.vue";
import {ref} from 'vue'
import {useRoute} from 'vue-router'

const route = useRoute()
const gameId = ref<number | null>(parseInt(route.params.id as string) || null)
</script>