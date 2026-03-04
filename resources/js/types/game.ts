// types/game.ts
export type PlayerName = 'Merlin' | 'Morgana' | 'Assassin' | 'Percival' | 'Loyal Servant'

export interface Player {
    id: number
    is_human: boolean
    player_index: number
    name: string
    role?: string | null
    roleLabel?: string | null
    knownEvil?: boolean
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

export interface Assassination {
    assassin: {
        name: string
        player_id: number
        player_index: number
    }
    target: {
        name: string
        player_id: number
        role: string
    }
    wasSuccessful: boolean
}

export interface GameEvent {
    id: number
    event_type: 'game_start' | 'game_end' | 'team_proposal' | 'team_vote' | 'mission_complete' | 'assassination'
    event_data: Record<string, any>
    created_at: string
}

export interface Game {
    id: number
    game_state: GameState,
    has_human_player: boolean
    winner: string | null
    turn_count: number
    ended_at: string | null // eg. 2024-11-24T22:21:22.000000Z
    started_at: string | null
}

export interface GameState {
    currentPhase: 'setup' | 'team_proposal' | 'team_discussion' | 'team_voting' | 'mission' | 'assassination' | 'debrief' | 'finished'
    turnCount: number
    currentLeader?: number
    assassination?: Assassination
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
