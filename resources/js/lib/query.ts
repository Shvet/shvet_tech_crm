/** Drops empty values so URLs stay clean (/leads?q=priya instead of /leads?q=priya&stage=&owner=). */
export function cleanQuery(params: Record<string, string | number | boolean | null | undefined>): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === undefined || value === '' || value === false) continue;
        out[key] = value === true ? '1' : String(value);
    }
    return out;
}
