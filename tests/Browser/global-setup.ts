import { readFileSync, writeFileSync } from 'fs'
import { resolve } from 'path'

const ENV_PATH = resolve(__dirname, '../../.env')

function patchEnv(content: string): string {
    return content
        .replace(/^AI_THINKING_TIME_MIN=.*/m, 'AI_THINKING_TIME_MIN=0')
        .replace(/^AI_THINKING_TIME_MAX=.*/m, 'AI_THINKING_TIME_MAX=0')
}

function restoreEnv(content: string, original: string): string {
    const minMatch = original.match(/^AI_THINKING_TIME_MIN=.*/m)
    const maxMatch = original.match(/^AI_THINKING_TIME_MAX=.*/m)
    return content
        .replace(/^AI_THINKING_TIME_MIN=.*/m, minMatch?.[0] ?? 'AI_THINKING_TIME_MIN=3')
        .replace(/^AI_THINKING_TIME_MAX=.*/m, maxMatch?.[0] ?? 'AI_THINKING_TIME_MAX=8')
}

let originalEnv = ''

export default async function globalSetup() {
    originalEnv = readFileSync(ENV_PATH, 'utf8')
    writeFileSync(ENV_PATH, patchEnv(originalEnv))
    console.log('[setup] Patched AI_THINKING_TIME to 0 for browser tests')
}

export async function globalTeardown() {
    if (originalEnv) {
        writeFileSync(ENV_PATH, restoreEnv(readFileSync(ENV_PATH, 'utf8'), originalEnv))
        console.log('[teardown] Restored AI_THINKING_TIME from .env')
    }
}
