import type { Auth } from '@/types/auth';
import type { Tenant } from '@/types/tenants';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
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
            currentTenant: Tenant | null;
            tenants: Tenant[];
            [key: string]: unknown;
        };
    }
}
