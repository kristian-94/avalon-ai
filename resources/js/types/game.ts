// types/game.ts
export type PlayerName = 'Merlin' | 'Morgana' | 'Assassin' | 'Percival' | 'Loyal Servant'

export interface Player {
    id: number
    name: PlayerName
    isLeader: boolean
}

interface MissionResult {
    success: boolean
    team: string[]
    votes: {
        success: number
        fail: number
    }
}

export interface Mission {
    required: number    // Number of players required
    result: MissionResult | null
    currentProposal?: {
        team: PlayerName[]
        votes: Record<PlayerName, boolean | null>  // null means hasn't voted
    }
    failsRequired: number // Usually 1, but mission 4 with 7+ players needs 2
}

export interface GameState {
    players: Player[]
    currentMission: number  // 0-4
    missions: Mission[]
    proposalCount: number   // Track number of failed proposals (5 fails = evil wins)
    gamePhase: 'team_proposal' | 'team_voting' | 'mission_voting' | 'game_end'
}