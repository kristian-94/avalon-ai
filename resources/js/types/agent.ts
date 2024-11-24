export type Role = 'merlin' | 'assassin' | 'loyal_servant' | 'minion'

export interface RoleKnowledge {
    knownRoles: Record<number, Role | null>  // What roles this agent knows about other players
    knownEvil: number[]                      // Which players are known to be evil
}

export interface Agent {
    id: number
    name: string
    role: Role
    roleLabel: string
    isHuman: boolean
    roleKnowledge: RoleKnowledge
    privateHistory: string[]  // Internal thoughts/reasoning
}