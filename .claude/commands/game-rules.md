# Avalon Game Rules Reference

**The Resistance: Avalon** — 5 players, social deduction, Good vs Evil.

## Roles
- **3 Good**: Loyal Servants + Merlin (sees all evil players, must hide identity)
- **2 Evil**: Minions + Assassin (can win by identifying Merlin at the end)

## Game Flow
1. **team_proposal**: Leader selects players for quest (sizes: 2→3→2→3→3 across quests 1-5)
2. **team_voting**: All players vote approve/reject — majority needed (tie = reject)
3. **mission**: Selected players play Success or Fail cards secretly
   - Good MUST play Success; Evil may play Success or Fail
   - Quest fails if any Fail card is played
4. **assassination**: If Good wins 3 quests, Assassin gets one guess to identify Merlin

## Victory Conditions
- **Good**: 3 successful quests AND Assassin misidentifies Merlin
- **Evil**: 3 failed quests OR 5 consecutive rejected proposals OR Assassin identifies Merlin

## Phase Timeouts
- `setup`: 5 min, `team_proposal`: 3 min, `team_voting`: 2 min, `mission`: 2 min, `assassination`: 3 min
- Timeout triggers `forcePhaseTransition()` in `GameLoop.php`
