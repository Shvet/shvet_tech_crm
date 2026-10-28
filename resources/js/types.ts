export interface SharedProps {
    auth: { user: { name: string; email: string } } | null;
    tenant: { id: string; name: string; timezone: string; currency: string; role: string | null; permissions: string[] } | null;
    flash: { status: string | null; duplicates?: DuplicateMatch[] | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface Member {
    id: number;
    name: string;
    email: string;
    role: string | null;
    status: 'invited' | 'active' | 'suspended' | 'removed';
    joined_at: string | null;
}

export type Priority = 'low' | 'medium' | 'high';
export type ActivityType = 'call' | 'meeting' | 'note' | 'email' | 'other';

export interface Stage {
    id: number;
    name: string;
    key: string;
    outcome: 'open' | 'won' | 'lost';
    color: string | null;
    inactive?: boolean;
}

export interface Option {
    id: number;
    name: string;
}

export interface Lead {
    id: string;
    full_name: string;
    phone: string | null;
    email: string | null;
    company_name: string | null;
    priority: Priority;
    estimated_value: string | null;
    source: { id: number; name: string; key: string } | null;
    stage: Stage | null;
    owner: Option | null;
    created_at: string;
    updated_at: string;
    stage_changed_at: string | null;
    last_contacted_at: string | null;
    archived_at: string | null;
    can?: {
        update: boolean;
        assign: boolean;
        change_stage: boolean;
        log_activity: boolean;
        create_task: boolean;
        archive: boolean;
        restore: boolean;
    };
}

export interface Task {
    id: string;
    title: string;
    description: string | null;
    priority: Priority;
    status: 'open' | 'completed';
    due_at: string;
    is_overdue: boolean;
    completed_at: string | null;
    outcome: string | null;
    assignee: Option | null;
    lead?: { id: string; name: string };
    can: { update: boolean; complete: boolean };
}

export type TimelineItem =
    | { kind: 'activity'; id: string; at: string; type: ActivityType; subject: string | null; body: string | null; user: string | null }
    | { kind: 'stage'; id: string; at: string; from: string | null; to: string | null; user: string | null };

export interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: PageLink[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface DuplicateMatch {
    visible: boolean;
    id?: string;
    full_name?: string;
    matches: string[];
    archived?: boolean;
}

export interface PipelineCard {
    id: string;
    full_name: string;
    company_name: string | null;
    priority: Priority;
    estimated_value: string | null;
    owner: string | null;
    stage_id: number;
    last_contacted_at: string | null;
    overdue_tasks: number;
    can_move: boolean;
}

export interface PipelineColumn {
    stage: Stage;
    count: number;
    has_more: boolean;
    cards: PipelineCard[];
}

export interface DashboardMetrics {
    totals: { leads: number; new_this_week: number; open: number; won: number; lost: number; open_value: string; unassigned: number | null };
    stages: (Stage & { count: number; value: string })[];
    recent_activity: { id: number; type: ActivityType; subject: string | null; occurred_at: string; user: string | null; lead: { id: string; name: string } }[];
    tasks: { overdue: number; today: number; upcoming: number };
}
