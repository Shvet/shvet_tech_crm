/** UI convenience only. The server enforces every permission; hiding a link is not security. */
export function can(permissions: string[] | undefined, permission: string): boolean {
    return !!permissions && permissions.includes(permission);
}

export const NAV_ITEMS = [
    { href: '/dashboard', label: 'Dashboard', permission: 'dashboard.view' },
    { href: '/team', label: 'Team', permission: 'users.view' },
] as const;

export function visibleNav(permissions: string[] | undefined) {
    return NAV_ITEMS.filter((item) => can(permissions, item.permission));
}
