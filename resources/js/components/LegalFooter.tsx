/** Links to the public policy pages (server-rendered, so plain anchors rather than Inertia links). */
export default function LegalFooter({ className = '' }: { className?: string }) {
    return (
        <nav aria-label="Legal" className={`flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 ${className}`}>
            <a href="/privacy" className="hover:underline">
                Privacy Policy
            </a>
            <a href="/terms" className="hover:underline">
                Terms of Service
            </a>
            <a href="/data-deletion" className="hover:underline">
                Data Deletion
            </a>
        </nav>
    );
}
