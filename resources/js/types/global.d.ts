import type { Auth } from '@/types/auth';
import type {
    BatchResult,
    DecisionResult,
    QualificationFlash,
} from '@/types/job-posting';
import type { ScheduleFlash } from '@/types/sources';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
        flashDataType: {
            batch_result?: BatchResult;
            decision_result?: DecisionResult;
            qualification_result?: QualificationFlash;
            schedule_result?: ScheduleFlash;
        };
    }
}
