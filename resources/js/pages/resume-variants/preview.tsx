import { Head, Link } from '@inertiajs/react';
import AppShell from '@/layouts/app-shell';

type ResumeVariantPreviewProps = {
    resumeVariant: { id: number };
    context: {
        title: string;
        workspace_url: string | null;
        job_url: string | null;
        back_label: string;
        back_url: string | null;
    };
    downloadUrl: string;
    pdfUrl: string;
};

/**
 * Embeds the actual generated PDF (via the browser's own native PDF
 * viewer, no PDF.js) rather than an independently-paginated HTML
 * rendering — see App\Http\Controllers\ResumeVariantPreviewController's
 * own docblock for why. Page boundaries shown here are therefore always
 * byte-identical to "Download PDF", since `pdfUrl` points at the exact
 * same generator/route, only with an inline disposition. The resume
 * workspace embeds the same PDF inline; this page stays for direct links.
 */
export default function ResumeVariantPreview({
    resumeVariant,
    context,
    downloadUrl,
    pdfUrl,
}: ResumeVariantPreviewProps) {
    return (
        <AppShell>
            <Head title={`Resume preview: ${context.title}`} />

            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-4 text-sm font-medium text-neutral-500 dark:text-neutral-400">
                    {context.back_url && (
                        <Link
                            href={context.back_url}
                            className="hover:text-neutral-700 dark:hover:text-neutral-200"
                        >
                            ← {context.back_label}
                        </Link>
                    )}
                    {context.job_url && (
                        <Link
                            href={context.job_url}
                            className="hover:text-neutral-700 dark:hover:text-neutral-200"
                        >
                            Job
                        </Link>
                    )}
                </div>
                <a
                    href={downloadUrl}
                    className="inline-flex items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
                >
                    Download PDF
                </a>
            </div>

            <div
                className="rounded-lg bg-neutral-200 p-4 dark:bg-neutral-800"
                data-resume-variant={resumeVariant.id}
            >
                <iframe
                    src={pdfUrl}
                    title="Resume PDF preview"
                    className="h-[85vh] w-full rounded border border-neutral-300 bg-white dark:border-neutral-700"
                />
            </div>
        </AppShell>
    );
}
