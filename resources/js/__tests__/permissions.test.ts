import { can, visibleNav } from '../lib/permissions';

describe('permissions helpers', () => {
    it('hides navigation the role has no permission for', () => {
        expect(visibleNav(['dashboard.view']).map((i) => i.label)).toEqual(['Dashboard']);
        expect(visibleNav(['dashboard.view', 'users.view']).map((i) => i.label)).toEqual(['Dashboard', 'Team']);
    });

    it('builds the CRM navigation from the role permissions', () => {
        const agent = ['dashboard.view', 'leads.view_own', 'leads.create', 'tasks.manage'];
        const manager = ['dashboard.view', 'leads.view_team', 'tasks.manage', 'users.view'];
        expect(visibleNav(agent).map((i) => i.label)).toEqual(['Dashboard', 'Leads', 'Pipeline', 'Follow-ups']);
        expect(visibleNav(manager).map((i) => i.label)).toEqual(['Dashboard', 'Leads', 'Pipeline', 'Follow-ups', 'Team']);
        expect(visibleNav(['leads.view_all']).map((i) => i.label)).toEqual(['Leads', 'Pipeline']);
    });

    it('treats missing permissions as denied', () => {
        expect(can(undefined, 'users.manage')).toBe(false);
        expect(can([], 'users.manage')).toBe(false);
        expect(can(['users.manage'], 'users.manage')).toBe(true);
    });
});
