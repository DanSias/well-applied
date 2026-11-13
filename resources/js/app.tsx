import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/toaster';
import { registerFlashToasts } from '@/lib/flash-toasts';

const appName = import.meta.env.VITE_APP_NAME || 'Well Applied';

// Flash feedback (decisions, batches) becomes a toast, once, wherever it arrives. Registered once for the app's life.
const stopFlashToasts = registerFlashToasts();
import.meta.hot?.dispose(stopFlashToasts);

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
    // The one toast outlet lives above the pages, so it survives Inertia navigation and never mounts twice.
    withApp: (app) => (
        <>
            {app}
            <Toaster />
        </>
    ),
});
