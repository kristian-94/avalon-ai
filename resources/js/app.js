import './bootstrap';
import {createApp} from 'vue'
import {createRouter, createWebHistory} from 'vue-router'

import App from './App.vue'
import GameSetup from "./components/game/GameSetup.vue";
import Game from "./components/game/Game.vue";

const routes = [
    {path: '/', component: GameSetup},
    {path: '/game/:id', component: Game},
]

const router = createRouter({
    history: createWebHistory(),
    routes
})
const app = createApp(App)
app.use(router)
app.mount('#app')