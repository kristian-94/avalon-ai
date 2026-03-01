import { test, expect, Page } from '@playwright/test'

// Pricing per million tokens: [input, output]
const MODEL_PRICING: Record<string, [number, number]> = {
    'gpt-4o':                   [2.50,  10.00],
    'gpt-4o-mini':              [0.15,   0.60],
    'gpt-4.1':                  [2.00,   8.00],
    'gpt-4.1-mini':             [0.40,   1.60],
    'gpt-4.1-nano':             [0.10,   0.40],
    'llama-3.3-70b-versatile':  [0.59,   0.79],
    'openai/gpt-oss-120b':      [0.15,   0.60],
    'llama-3.1-8b-instant':     [0.05,   0.08],
    'llama3-70b-8192':          [0.59,   0.79],
    'mixtral-8x7b-32768':       [0.24,   0.24],
}

function estimateCost(model: string | undefined, promptTokens: number, completionTokens: number): string {
    const pricing = model ? MODEL_PRICING[model] : undefined
    if (!pricing) return model ? `unknown model "${model}"` : 'unknown model'
    const cost = (promptTokens / 1_000_000) * pricing[0] + (completionTokens / 1_000_000) * pricing[1]
    return `$${cost.toFixed(4)}`
}

/**
 * Full game integration test — human plays through an entire Avalon game.
 *
 * Requires:
 *   - `composer dev` running (server on :8000 + queue worker + vite)
 *   - AI_PROVIDER=openai (or random) in .env
 *
 * Stuck detection: if no new chat message arrives for 10s and no action panel
 * is visible, the test fails immediately with a screenshot.
 */

const BASE_URL    = 'http://localhost:8000'
const GAME_TIMEOUT = 600_000  // 10 min hard ceiling
const STUCK_MS    = 10_000    // bail if nothing happens for 10s

async function countMessages(page: Page): Promise<number> {
    return page.locator('.rounded-lg.p-3').count()
}

async function visiblePanel(page: Page): Promise<'voting' | 'proposal' | 'mission' | 'assassination' | null> {
    if (await page.getByRole('button', { name: /Approve/i }).isVisible().catch(() => false))       return 'voting'
    if (await page.getByRole('button', { name: /Propose Team/i }).isVisible().catch(() => false))  return 'proposal'
    if (await page.getByRole('button', { name: /Play Success/i }).isVisible().catch(() => false))  return 'mission'
    if (await page.locator('text=Choose who you think is Merlin').isVisible().catch(() => false))  return 'assassination'
    return null
}

async function playGame(page: Page, roleName: string) {
    page.setDefaultTimeout(600_000)

    // ── 1. Start game ────────────────────────────────────────────────────────
    await page.goto(BASE_URL)
    await expect(page.getByRole('button', { name: /Play with AI/i })).toBeVisible()
    await page.getByRole('button', { name: /Play with AI/i }).click()

    await expect(page.getByRole('heading', { name: /Choose Your Role/i })).toBeVisible({ timeout: 3_000 })
    // Match exact bold title to avoid description text matching wrong role
    await page.locator('button').filter({ has: page.locator(`span.font-bold:text-is("${roleName}")`) }).click()

    await expect(page).toHaveURL(/\/game\/\d+/, { timeout: 10_000 })
    console.log(`[${roleName}] Game URL:`, page.url())

    await expect(page.locator('h2:has-text("Game Chat")')).toBeVisible({ timeout: 5_000 })
    await expect(page.locator('text=Game started')).toBeVisible({ timeout: 5_000 })

    page.on('response', async response => {
        if (response.url().includes('/api/game/') && response.status() >= 400) {
            const body = await response.text().catch(() => '(unreadable)')
            console.error(`API ${response.status()} ${response.url()}: ${body}`)
        }
    })

    // ── 2. Play loop ─────────────────────────────────────────────────────────
    const gameStart    = Date.now()
    let rounds         = 0
    let votescast      = 0
    let proposals      = 0
    let missionCards   = 0
    let assassinations = 0
    let lastMsgCount   = await countMessages(page)
    let lastActivityAt = Date.now()

    while (Date.now() - gameStart < GAME_TIMEOUT) {
        // Game over?
        if (await page.locator('text=/Good Triumphs|Evil Prevails/i').first().isVisible().catch(() => false)) {
            console.log(`[${roleName}] Game finished — rounds:${rounds} votes:${votescast} proposals:${proposals} missions:${missionCards} assassinations:${assassinations}`)
            break
        }

        const panel = await visiblePanel(page)

        if (!panel) {
            // No action needed — check if game is still alive via message activity
            const msgCount = await countMessages(page)
            if (msgCount !== lastMsgCount) {
                lastMsgCount   = msgCount
                lastActivityAt = Date.now()
            }
            const stuck = Date.now() - lastActivityAt
            if (stuck > STUCK_MS) {
                await page.screenshot({ path: `test-results/stuck-${roleName.toLowerCase().replace(/\s+/g, '-')}.png` })
                throw new Error(`[${roleName}] No activity for ${stuck}ms — game appears stuck`)
            }
            await page.waitForTimeout(200)
            continue
        }

        // Reset activity timer whenever a panel appears
        lastActivityAt = Date.now()
        rounds++

        if (panel === 'voting') {
            const approve = Math.random() > 0.3
            await page.getByRole('button', { name: approve ? /Approve/i : /Reject/i }).click()
            await expect(page.locator('text=/✓ Approved|✓ Rejected/').first()).toBeVisible({ timeout: 5_000 })
            votescast++
            console.log(`[${roleName}] Round ${rounds}: voted ${approve ? 'approve' : 'reject'}`)

        } else if (panel === 'proposal') {
            const counterText = await page.locator('text=/Select \\d+ players/').textContent().catch(() => '') || ''
            const required = parseInt(counterText.match(/(\d+)/)?.[1] ?? '2')
            const chips = page.locator('.rounded-full').filter({ hasText: /^[A-Z]/ })
            for (let i = 0; i < required; i++) {
                await chips.nth(i).click()
                await page.waitForTimeout(80)
            }
            await page.getByRole('button', { name: /Propose Team/ }).click()
            await expect(page.locator('text=Proposal submitted')).toBeVisible({ timeout: 5_000 })
            proposals++
            console.log(`[${roleName}] Round ${rounds}: proposed team of ${required}`)

        } else if (panel === 'mission') {
            await page.getByRole('button', { name: /Play Success/i }).click()
            await expect(page.locator('text=Mission card played')).toBeVisible({ timeout: 5_000 })
            missionCards++
            console.log(`[${roleName}] Round ${rounds}: played mission card`)

        } else if (panel === 'assassination') {
            const targets = page.locator('text=Choose who you think is Merlin').locator('..').locator('button')
            const targetName = await targets.first().textContent()
            await targets.first().click()
            await expect(page.locator('text=Assassination target chosen')).toBeVisible({ timeout: 5_000 })
            assassinations++
            console.log(`[${roleName}] Round ${rounds}: assassinated ${targetName?.trim()}`)
        }

        // Update message count after acting so the stuck timer doesn't fire immediately
        lastMsgCount   = await countMessages(page)
        lastActivityAt = Date.now()
        await page.waitForTimeout(250)
    }

    // ── 3. Assertions ────────────────────────────────────────────────────────
    const elapsed = ((Date.now() - gameStart) / 1000).toFixed(1)
    console.log(`[${roleName}] Total game time: ${elapsed}s`)

    const victoryBanner = page.locator('text=/Good Triumphs!|Evil Prevails!/').first()
    await expect(victoryBanner).toBeVisible({ timeout: 60_000 })
    await page.waitForTimeout(3_000)  // brief debrief window

    console.log(`[${roleName}] Winner: ${await victoryBanner.textContent()}`)

    // API cost summary
    const gameId = page.url().match(/\/game\/(\d+)/)?.[1]
    if (gameId) {
        const state = await page.request.get(`${BASE_URL}/api/game/${gameId}/state`).then(r => r.json()).catch(() => null)
        const usage = state?.apiUsage
        if (usage) {
            const cost = estimateCost(usage.model, usage.promptTokens, usage.completionTokens)
            console.log(`[${roleName}] API calls: ${usage.calls} | tokens: ${usage.totalTokens} | model: ${usage.model ?? 'unknown'} | est. cost: ${cost}`)
        }
    }

    await expect(page.getByText('Successful', { exact: true }).first()).toBeVisible()
    await expect(page.getByText('Failed', { exact: true }).first()).toBeVisible()
    expect(votescast + proposals + missionCards + assassinations).toBeGreaterThan(0)

    await page.screenshot({ path: `test-results/game-end-${roleName.toLowerCase().replace(/\s+/g, '-')}.png` })
    console.log(`[${roleName}] Screenshot saved`)
}

test('human plays as Merlin',        async ({ page }) => playGame(page, 'Merlin'))
test('human plays as Loyal Servant', async ({ page }) => playGame(page, 'Loyal Servant'))
test('human plays as Assassin',      async ({ page }) => playGame(page, 'Assassin'))
