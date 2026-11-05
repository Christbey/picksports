export interface FoundingUser {
    id: number;
    name: string;
    email: string;
    created_at: string | null;
}

export interface FoundingUsersPanel {
    enabled: boolean;
    limit: number;
    used: number;
    remaining: number;
    role: string;
    tier_slug: string;
    tier_name: string;
    users: FoundingUser[];
}

export interface GroupInvitation {
    id: number;
    email: string;
    token: string;
    invite_url: string;
    accepted_at: string | null;
    expires_at: string | null;
    created_at: string | null;
}

export interface GroupJoinLink {
    token: string;
    join_url: string;
    expires_at: string | null;
    created_at: string | null;
}

export interface GroupMember {
    id: number;
    name: string;
    email: string;
    role: string;
    joined_at: string | null;
}

export interface AdminGroup {
    id: number;
    public_id: string;
    name: string;
    season: number | null;
    members: GroupMember[];
    members_count: number;
    brackets_count: number;
    join_link: GroupJoinLink | null;
    invitations: GroupInvitation[];
}

export interface AdminSettingsProps {
    foundingUsers: FoundingUsersPanel;
    groups: AdminGroup[];
}
