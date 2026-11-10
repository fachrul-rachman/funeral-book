import { createInertiaApp } from '@inertiajs/react';

const appName = 'Lestari Memorial Park';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
});
