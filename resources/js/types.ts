export interface SharedProps {
    auth: { user: { name: string; email: string } } | null;
    tenant: { id: string; name: string; role: string | null; permissions: string[] } | null;
    flash: { status: string | null };
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
