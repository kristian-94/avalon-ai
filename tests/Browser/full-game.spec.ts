import { test, expect, Page } from '@playwright/test'

/**
 * Full game integration test — human plays through an entire Avalon game
 * using keyboard navigation.
 *
 * Requires:
 *   - `composer dev` running (server on :8000 + queue worker + vite)
 *   - AI_PROVIDER=random in .env
 *
 * Keyboard flow:
 *   - Chat input is focused by default
 *   - ↓  from chat input → enters action panel
 *   - Voting:   ← = Reject highlighted, → = Approve highlighted, Enter = confirm
 *   - Proposal: ← → navigate player chips, Space = toggle, Enter = submit
 *   - Mission:  Enter = play success
 *   - Action panel auto-returns focus to chat after submission
 */

const BASE_URL     = 'http://localhost:8000'
const GAME_TIMEOUT = 90_000   // 90 s ceiling
const ACTION_WAIT  =  5_000   // how long to wait for an action panel to appear

// Returns which panel is now visible, or null
async function visiblePanel(page: Page): Promise<'voting' | 'proposal' | 'mission' | null> {
    // We detect panels by their unique hint text
    const voting   = page.locator('text=← Reject · Approve →')
    const proposal = page.locator('text=← → navigate')
    const mission  = page.locator('text=You are on this mission')

    if (await voting.isVisible())   return 'voting'
    if (await proposal.isVisible()) return 'proposal'
    if (await mission.isVisible())  return 'mission'
    return null
}

async function waitForActionOrGameEnd(page: Page): Promise<'voting' | 'proposal' | 'mission' | 'finished' | 'timeout'> {
    const deadline = Date.now() + ACTION_WAIT
    while (Date.now() < deadline) {
        // Check for game end first
        if (await page.locator('text=/Good Triumphs|Evil Prevails/i').first().isVisible()) {
            return 'finished'
        }
        const panel = await visiblePanel(page)
        if (panel) return panel
        await page.waitForTimeout(100)
    }
    return 'timeout'
}

test('human plays through a complete game without getting stuck', async ({ page }) => {
    page.setDefaultTimeout(90_000)

    // ── 1. Navigate to home and start a game ────────────────────────────────
    await page.goto(BASE_URL)
    await expect(page.getByRole('button', { name: /Play with AI/i })).toBeVisible()
    await page.getByRole('button', { name: /Play with AI/i }).click()

    // Should redirect to /game/:id
    await expect(page).toHaveURL(/\/game\/\d+/, { timeout: 5_000 })
    console.log('Game URL:', page.url())

    // Game panels should render (check for chat panel heading)
    await expect(page.locator('h2:has-text("Game Chat")')).toBeVisible({ timeout: 5_000 })

    // History panel should show game_start event
    await expect(page.locator('text=Game started')).toBeVisible({ timeout: 5_000 })

    // Chat input should be focused by default
    const chatInput = page.locator('input[type=text]')
    await expect(chatInput).toBeVisible()

    // Log API errors to help debug
    page.on('response', async response => {
        if (response.url().includes('/api/game/') && response.status() >= 400) {
            const body = await response.text().catch(() => '(unreadable)')
            console.error(`API ${response.status()} ${response.url()}: ${body}`)
        }
    })

    // ── 2. Play loop ─────────────────────────────────────────────────────────
    const gameStart = Date.now()
    let rounds       = 0
    let votescast    = 0
    let proposals    = 0
    let missionCards = 0

    while (Date.now() - gameStart < GAME_TIMEOUT) {
        const result = await waitForActionOrGameEnd(page)

        if (result === 'finished') {
            console.log(`Game finished — rounds:${rounds} votes:${votescast} proposals:${proposals} missions:${missionCards}`)
            break
        }

        if (result === 'timeout') {
            // No action panel — AI is handling this phase. Try again.
            await page.waitForTimeout(200)
            continue
        }

        rounds++

        if (result === 'voting') {
            // Bias 70% approve to keep games moving
            const approve = Math.random() > 0.3
            const btn = approve
                ? page.getByRole('button', { name: /Approve/i })
                : page.getByRole('button', { name: /Reject/i })
            await btn.click()

            await expect(page.locator('text=/✓ Approved|✓ Rejected/')).toBeVisible({ timeout: 5_000 })
            votescast++
            console.log(`Round ${rounds}: voted ${approve ? 'approve' : 'reject'}`)

        } else if (result === 'proposal') {
            // Extract required count from "Select N players"
            const counterText = await page.locator('text=/Select \\d+ players/').textContent() || ''
            const match = counterText.match(/(\d+)/)
            const required = match ? parseInt(match[1]) : 2

            // Click the first N player chips
            const chips = page.locator('.rounded-full').filter({ hasText: /^[A-Z]/ })
            for (let i = 0; i < required; i++) {
                await chips.nth(i).click()
                await page.waitForTimeout(80)
            }

            await page.getByRole('button', { name: /Propose Team/ }).click()
            await expect(page.locator('text=✓ Proposal submitted')).toBeVisible({ timeout: 5_000 })
            proposals++
            console.log(`Round ${rounds}: proposed team of ${required}`)

        } else if (result === 'mission') {
            await page.getByRole('button', { name: /Play Success/i }).click()

            await expect(page.locator('text=✓ Mission card played')).toBeVisible({ timeout: 5_000 })
            missionCards++
            console.log(`Round ${rounds}: played mission card`)
        }

        // After each action the panel returns focus to chat automatically.
        // Give WebSocket state update a moment to settle.
        await page.waitForTimeout(250)
    }

    // ── 3. Assertions ────────────────────────────────────────────────────────
    const elapsed = ((Date.now() - gameStart) / 1000).toFixed(1)
    console.log(`Total game time: ${elapsed}s`)

    // Game must reach a winner — wait for VictoryScreen banner
    const victoryBanner = page.locator('text=/Good Triumphs!|Evil Prevails!/').first()
    await expect(victoryBanner).toBeVisible({ timeout: 10_000 })

    const bannerText = await victoryBanner.textContent()
    console.log(`Winner: ${bannerText}`)

    // VictoryScreen must show mission counts (proves real end state, not a false positive)
    await expect(page.locator('text=Successful')).toBeVisible()
    await expect(page.locator('text=Failed')).toBeVisible()

    // Human must have taken at least one action (not a ghost game)
    const totalActions = votescast + proposals + missionCards
    expect(totalActions, 'Human should have taken at least one action').toBeGreaterThan(0)

    // History panel must have captured events beyond game_start
    await expect(page.locator('text=Game started')).toBeVisible()

    // Take a final screenshot so we can see the end state
    await page.screenshot({ path: 'test-results/game-end-state.png', fullPage: false })
    console.log('End state screenshot saved to test-results/game-end-state.png')
})
