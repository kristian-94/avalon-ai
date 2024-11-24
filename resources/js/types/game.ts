// types/game.ts
export type PlayerName = 'Merlin' | 'Morgana' | 'Assassin' | 'Percival' | 'Loyal Servant'

export interface Player {
    id: number
    is_human: boolean
    player_index: number
    name: string
}

export interface MissionResult {
    success: boolean
    team: string[]
    playerIndexes: number[]
    votes: {
        success: number
        fail: number
    }
}


export interface Message {
    id: number;
    content: string;
    player_id?: number;
    player_name: string;
    created_at: string;
    isSystem?: boolean;
}

export interface Mission {
    mission_number: number;
    status: string;
    required: number    // Number of players required
    result: MissionResult | null
    currentProposal?: {
        team: PlayerName[]
        votes: Record<PlayerName, boolean | null>  // null means hasn't voted
    }
    failsRequired: number
}

export interface Proposal {
    playerIndexes: number[]
    team: string[]
    proposal_number: number
    votes: Record<number, boolean> // key is player index, value is boolean vote
}

export interface GameState {
    currentPhase: 'setup' | 'team_proposal' | 'team_voting' | 'mission'
    turnCount: number
    currentLeader?: number
    currentMission?: {
        id: number
        required: number
        playerIndexes: number[]
        team?: string[]
    }
    currentProposal?: {
        team: string[]
        playerIndexes: number[]
        votes?: Record<string, boolean>
    }
    proposals: Proposal[]
    missions: Mission[]
}
