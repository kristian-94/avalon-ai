<template>
  <div class="flex flex-col">
    <div :class="[
      'max-w-[80%] rounded-lg p-3',
      message.isSystem ? 'bg-white/10 text-white self-center' : 'bg-blue-500/80 text-white self-end'
    ]">
      <p class="text-sm font-semibold">{{ message.sender }}</p>
      <p>{{ message.text }}</p>
    </div>
  </div>
</template>

<script setup>
defineProps({
  message: {
    type: Object,
    required: true
  }
})
</script>
