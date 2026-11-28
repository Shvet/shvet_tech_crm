/** UI convenience only. The server enforces every permission; hiding a link is not security. */
export function can(permissions: string[] | undefined, permission: string): boolean {
    return !!permissions && permissions.includes(permission);
}

export function canAny(permissions: string[] | undefined, wanted: readonly string[]): boolean {
    return wanted.some((p) => can(permissions, p));
}

const VIEW_LEADS = ['leads.view_all', 'leads.view_team', 'leads.view_own'] as const;

export const NAV_ITEMS = [
    { href: '/dashboard', label: 'Dashboard', anyOf: ['dashboard.view'] },
    { href: '/leads', label: 'Leads', anyOf: VIEW_LEADS },
    { href: '/pipeline', label: 'Pipeline', anyOf: VIEW_LEADS },
    { href: '/tasks', label: 'Follow-ups', anyOf: ['tasks.manage'] },
    { href: '/inbox', label: 'WhatsApp inbox', anyOf: ['conversations.view'] },
    { href: '/reports', label: 'Reports', anyOf: ['reports.view'] },
    { href: '/team', label: 'Team', anyOf: ['users.view'] },
    { href: '/settings/pipeline', label: 'Pipeline stages', anyOf: ['pipeline.manage'] },
    { href: '/integrations/meta', label: 'Meta Lead Ads', anyOf: ['integrations.manage'] },
    { href: '/integrations/whatsapp', label: 'WhatsApp setup', anyOf: ['integrations.manage'] },
] as const;

export function visibleNav(permissions: string[] | undefined) {
    return NAV_ITEMS.filter((item) => canAny(permissions, item.anyOf));
}
