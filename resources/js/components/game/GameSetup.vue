<template>
  <div class="flex flex-col items-center justify-center min-h-[80vh]">
    <!-- Initial buttons -->
    <div v-if="!gameState && !pickingRole && !pickingIdentity" class="text-center space-y-8">
      <img src="/logo.png" alt="Avalon AI" class="w-40 h-40 mx-auto mb-4 drop-shadow-2xl" />
      <h1 class="text-4xl font-bold text-white mb-8">Avalon AI</h1>
      <div class="space-y-4">
        <p class="text-white/80 text-lg mb-8">
          Join a game of Avalon with AI agents.
        </p>
        <div v-if="errorMessage" class="text-red-400 text-sm mb-4">{{ errorMessage }}</div>
        <div class="flex justify-center">
          <button
              @click="pickingRole = true"
              class="px-8 py-4 bg-yellow-800 text-white rounded-lg border-2 border-yellow-900 hover:bg-yellow-700 transition-colors"
          >
            Play with AI
          </button>
        </div>
      </div>
    </div>

    <!-- Role picker -->
    <div v-else-if="pickingRole && !pickingIdentity && !gameState" class="text-center space-y-6 max-w-2xl w-full px-4">
      <h2 class="text-2xl font-bold text-white">Choose Your Role</h2>
      <p class="text-white/60 text-sm">Pick who you want to play as — the AI agents will fill the rest.</p>
      <div v-if="errorMessage" class="text-red-400 text-sm">{{ errorMessage }}</div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <button
            v-for="role in roles"
            :key="role.id ?? 'random'"
            @click="selectRole(role.id)"
            class="text-left p-5 rounded-xl border-2 transition-all hover:scale-[1.02]"
            :class="role.borderClass"
        >
          <div class="flex items-center gap-3 mb-2">
            <span class="text-2xl">{{ role.icon }}</span>
            <span class="font-bold text-lg" :class="role.nameClass">{{ role.name }}</span>
            <span class="ml-auto text-xs px-2 py-0.5 rounded-full" :class="role.teamBadgeClass">{{ role.team }}</span>
          </div>
          <p class="text-white/70 text-sm leading-relaxed">{{ role.description }}</p>
        </button>
      </div>
      <button @click="pickingRole = false" class="text-white/40 hover:text-white/70 text-sm transition-colors">
        ← Back
      </button>
    </div>

    <!-- Identity picker (name + avatar) -->
    <div v-else-if="pickingIdentity && !gameState" class="text-center space-y-8 max-w-md w-full px-4">
      <h2 class="text-2xl font-bold text-white">Who are you?</h2>

      <!-- Avatar grid -->
      <div class="grid grid-cols-5 gap-3 justify-items-center">
        <button
            v-for="avatar in avatars"
            :key="avatar.id"
            @click="selectedAvatar = avatar.id"
            class="relative rounded-xl overflow-hidden transition-all hover:scale-110 w-16 h-16"
            :class="selectedAvatar === avatar.id ? 'ring-3 ring-white scale-110 shadow-lg shadow-white/20' : 'ring-1 ring-white/20 opacity-70 hover:opacity-100'"
            :title="avatar.label"
        >
          <img :src="`/avatars/human/${avatar.id}.png`" :alt="avatar.label" class="w-full h-full object-cover" />
        </button>
      </div>

      <!-- Name input -->
      <div class="space-y-2">
        <input
            v-model="playerName"
            type="text"
            placeholder="Enter your name..."
            maxlength="20"
            @keyup.enter="startGame"
            class="w-full px-4 py-3 rounded-xl bg-white/10 border border-white/20 text-white placeholder-white/30 text-center text-lg focus:outline-none focus:border-white/50 focus:bg-white/15 transition-all"
        />
        <p v-if="errorMessage" class="text-red-400 text-sm">{{ errorMessage }}</p>
      </div>

      <button
          @click="startGame"
          :disabled="!playerName.trim() || !selectedAvatar"
          class="w-full px-8 py-4 bg-yellow-800 text-white rounded-xl border-2 border-yellow-900 hover:bg-yellow-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed font-semibold text-lg"
      >
        Enter the Round Table
      </button>

      <button @click="pickingIdentity = false" class="text-white/40 hover:text-white/70 text-sm transition-colors">
        ← Back
      </button>
    </div>

    <!-- Initializing -->
    <div v-else class="text-center">
      <div v-if="gameState === 'initializing'" class="text-white space-y-4">
        <div class="animate-spin w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full mx-auto mb-4"></div>
        <p>Assigning roles and initializing game...</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref} from 'vue'
import {useRouter} from 'vue-router'
import axios from "axios";

const gameState = ref<'initializing' | null>(null)
const errorMessage = ref<string | null>(null)
const pickingRole = ref(false)
const pickingIdentity = ref(false)
const selectedRole = ref<string | null>(null)
const playerName = ref('')
const selectedAvatar = ref<string>('')
const router = useRouter()

const avatars = [
  { id: 'wizard', label: 'Wizard' },
  { id: 'knight', label: 'Knight' },
  { id: 'rogue', label: 'Rogue' },
  { id: 'ranger', label: 'Ranger' },
  { id: 'paladin', label: 'Paladin' },
  { id: 'bard', label: 'Bard' },
  { id: 'druid', label: 'Druid' },
  { id: 'warlock', label: 'Warlock' },
  { id: 'monk', label: 'Monk' },
  { id: 'barbarian', label: 'Barbarian' },
]

const roles = [
  {
    id: null,
    name: 'Random',
    icon: '🎲',
    team: 'Surprise',
    description: 'Let fate decide. You\'ll be assigned a random role when the game starts.',
    borderClass: 'border-white/20 bg-white/5 hover:border-white/40',
    nameClass: 'text-white/80',
    teamBadgeClass: 'bg-white/10 text-white/60',
  },
  {
    id: 'merlin',
    name: 'Merlin',
    icon: '🔮',
    team: 'Good',
    description: 'You know who the evil players are, but must stay hidden. Guide the good team through subtle hints — if the Assassin identifies you, evil wins.',
    borderClass: 'border-blue-700 bg-blue-950/40 hover:border-blue-500',
    nameClass: 'text-blue-300',
    teamBadgeClass: 'bg-blue-900 text-blue-300',
  },
  {
    id: 'loyal_servant',
    name: 'Loyal Servant',
    icon: '⚔️',
    team: 'Good',
    description: 'No special knowledge — just your wits. Watch voting patterns, read behaviour, and root out the evil players through careful observation.',
    borderClass: 'border-green-700 bg-green-950/40 hover:border-green-500',
    nameClass: 'text-green-300',
    teamBadgeClass: 'bg-green-900 text-green-300',
  },
  {
    id: 'assassin',
    name: 'Assassin',
    icon: '🗡️',
    team: 'Evil',
    description: 'Charming, ruthless, and deceptive. Sabotage missions without being caught, and at the end identify Merlin to seal victory for evil.',
    borderClass: 'border-red-700 bg-red-950/40 hover:border-red-500',
    nameClass: 'text-red-300',
    teamBadgeClass: 'bg-red-900 text-red-300',
  },
  {
    id: 'minion',
    name: 'Minion of Mordred',
    icon: '🌑',
    team: 'Evil',
    description: 'You know the other evil players. Appear enthusiastically helpful and trustworthy while steering missions toward failure.',
    borderClass: 'border-orange-700 bg-orange-950/40 hover:border-orange-500',
    nameClass: 'text-orange-300',
    teamBadgeClass: 'bg-red-900 text-red-300',
  },
]

const selectRole = (role: string | null) => {
  selectedRole.value = role
  pickingRole.value = false
  pickingIdentity.value = true
  errorMessage.value = null
}

const startGame = async () => {
  if (!playerName.value.trim() || !selectedAvatar.value) return

  gameState.value = 'initializing'
  errorMessage.value = null

  const name = playerName.value.trim()

  try {
    const response = await axios.post('/api/game/initialize', {
      mode: 'play',
      role: selectedRole.value,
      name,
      avatar: selectedAvatar.value,
    })
    const {gameId, playerId, message} = response.data

    localStorage.setItem('playerId', playerId);
    localStorage.setItem('gameId', gameId);
    localStorage.setItem('initialMessage', message);

    await router.push(`/game/${gameId}`)
  } catch (err: any) {
    gameState.value = null
    pickingIdentity.value = true
    errorMessage.value = err?.response?.data?.message ?? 'Failed to start game. Please try again.'
  }
}
</script>
