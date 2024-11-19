<template>
  <div class="lg:col-span-2 rounded-lg p-4">
    <MissionTracker :missions="missions"/>
    <PlayerArea/>
  </div>
</template>

<script setup lang="ts">
import PlayerArea from './PlayerArea.vue'
import MissionTracker from "./MissionTracker.vue";

import {ref} from 'vue'

// Mock missions data for now.
const missions = ref([
  {id: 1, name: 'Mission 1', status: 'success', result: null, required: 2},
  {id: 2, name: 'Mission 2', status: 'pending', result: null, required: 3},
  {id: 3, name: 'Mission 3', status: 'pending', result: null, required: 2},
  {id: 4, name: 'Mission 4', status: 'pending', result: null, required: 3},
  {id: 5, name: 'Mission 5', status: 'pending', result: null, required: 2},
]);
</script>
