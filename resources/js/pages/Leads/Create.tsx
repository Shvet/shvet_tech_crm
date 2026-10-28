import { Head } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import LeadForm, { type LeadFormOptions } from '../../components/LeadForm';

export default function Create({ options, submission_token }: { options: LeadFormOptions; submission_token: string }) {
    return (
        <AppLayout title="New lead">
            <Head title="New lead" />
            <LeadForm mode="create" options={options} submissionToken={submission_token} />
        </AppLayout>
    );
}
