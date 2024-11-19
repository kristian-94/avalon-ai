<template>
  <div class="flex-1 overflow-y-auto p-4 space-y-4">
    <ChatMessage
        v-for="message in messages"
        :key="message.id"
        :message="message"
    />
  </div>
</template>

<script setup lang="ts">
import {defineProps} from 'vue';
import ChatMessage from './ChatMessage.vue';

const props = defineProps<{
  messages: Array<{
    id: number;
    sender: string;
    text: string;
    isSystem: boolean;
  }>;
}>();

</script>
