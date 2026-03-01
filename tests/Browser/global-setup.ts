import { readFileSync, writeFileSync } from 'fs'
import { resolve, dirname } from 'path'
import { fileURLToPath } from 'url'
import { execSync } from 'child_process'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ENV_PATH = resolve(__dirname, '../../.env')
const PROJECT_ROOT = resolve(__dirname, '../../')

function patchEnv(content: string): string {
    let patched = content
        .replace(/^AI_THINKING_TIME_MIN=.*/m, 'AI_THINKING_TIME_MIN=0')
        .replace(/^AI_THINKING_TIME_MAX=.*/m, 'AI_THINKING_TIME_MAX=0')
    if (/^MAX_ACTIVE_GAMES=.*/m.test(patched)) {
        patched = patched.replace(/^MAX_ACTIVE_GAMES=.*/m, 'MAX_ACTIVE_GAMES=3')
    } else {
        patched += '\nMAX_ACTIVE_GAMES=3'
    }
    return patched
}

function restoreEnv(content: string, original: string): string {
    const minMatch = original.match(/^AI_THINKING_TIME_MIN=.*/m)
    const maxMatch = original.match(/^AI_THINKING_TIME_MAX=.*/m)
    const maxGamesMatch = original.match(/^MAX_ACTIVE_GAMES=.*/m)
    let restored = content
        .replace(/^AI_THINKING_TIME_MIN=.*/m, minMatch?.[0] ?? 'AI_THINKING_TIME_MIN=3')
        .replace(/^AI_THINKING_TIME_MAX=.*/m, maxMatch?.[0] ?? 'AI_THINKING_TIME_MAX=8')
    if (maxGamesMatch) {
        restored = restored.replace(/^MAX_ACTIVE_GAMES=.*/m, maxGamesMatch[0])
    } else {
        restored = restored.replace(/\nMAX_ACTIVE_GAMES=3/m, '')
    }
    return restored
}

let originalEnv = ''

export default async function globalSetup() {
    // Kill any leftover active games so MAX_ACTIVE_GAMES throttle doesn't block tests
    try {
        execSync(
            `php artisan tinker --execute="DB::table('games')->whereNull('ended_at')->whereNotIn('current_phase', ['finished'])->update(['current_phase' => 'finished', 'ended_at' => now()]);"`,
            { cwd: PROJECT_ROOT, stdio: 'pipe' }
        )
        console.log('[setup] Cleaned up leftover active games')
    } catch (e) {
        console.warn('[setup] Could not clean up games:', e)
    }

    originalEnv = readFileSync(ENV_PATH, 'utf8')
    writeFileSync(ENV_PATH, patchEnv(originalEnv))
    console.log('[setup] Patched AI_THINKING_TIME to 0 and MAX_ACTIVE_GAMES to 3 for browser tests')
}

export async function globalTeardown() {
    if (originalEnv) {
        writeFileSync(ENV_PATH, restoreEnv(readFileSync(ENV_PATH, 'utf8'), originalEnv))
        console.log('[teardown] Restored AI_THINKING_TIME and MAX_ACTIVE_GAMES from .env')
    }
}
