/** The page-balance judgment of a rendered resume (App\Support\ResumeDocument\LayoutQuality). */
export type LayoutQuality = {
    status: 'balanced' | 'light' | 'underfilled' | 'one_page' | 'unmeasured';
    /** `review` means a person should look; it never blocks the PDF. */
    severity: 'review' | 'informational' | null;
    pages: number | null;
    fill: number | null;
    summary: string;
    findings: {
        code: string;
        severity: 'review' | 'informational';
        message: string;
    }[];
    room: {
        bullets_to_balance: number;
        projected_fill: number | null;
        available: number | null;
        candidates: {
            key: string;
            role_id: number | null;
            statement: string;
            project: string | null;
        }[];
    } | null;
};
