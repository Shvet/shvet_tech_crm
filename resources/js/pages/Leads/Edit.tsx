import { Head } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import LeadForm, { type LeadFormOptions } from '../../components/LeadForm';
import type { Lead } from '../../types';

export default function Edit({ lead, options }: { lead: Lead; options: LeadFormOptions }) {
    return (
        <AppLayout title={`Edit ${lead.full_name}`}>
            <Head title={`Edit ${lead.full_name}`} />
            <LeadForm mode="edit" lead={lead} options={options} />
        </AppLayout>
    );
}
