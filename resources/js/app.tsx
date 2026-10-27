import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import '../css/app.css';

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

createInertiaApp({
    title: (title) => (title ? `${title} · Shvet CRM` : 'Shvet CRM'),
    resolve: async (name) => {
        const page = pages[`./pages/${name}.tsx`];
        if (!page) {
            throw new Error(`Unknown page component: ${name}`);
        }
        return (await page()).default;
    },
});
