import { can, visibleNav } from '../lib/permissions';

describe('permissions helpers', () => {
    it('hides navigation the role has no permission for', () => {
        expect(visibleNav(['dashboard.view']).map((i) => i.label)).toEqual(['Dashboard']);
        expect(visibleNav(['dashboard.view', 'users.view']).map((i) => i.label)).toEqual(['Dashboard', 'Team']);
    });

    it('treats missing permissions as denied', () => {
        expect(can(undefined, 'users.manage')).toBe(false);
        expect(can([], 'users.manage')).toBe(false);
        expect(can(['users.manage'], 'users.manage')).toBe(true);
    });
});
