export * from './auth';
export * from './navigation';
export * from './ui';
export * from './models';
export * from './subscription';
export * from './sports';
export * from './sport-team';
export * from './dashboard';
export * from './api-v2';

import type { Auth } from './auth';
import type { SubscriptionInfo } from './subscription';

type ImpersonationContext = {
    active: boolean;
    impersonator: {
        id: number;
        name: string;
        email: string;
    } | null;
};

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    auth: Auth;
    impersonation: ImpersonationContext;
    subscription: SubscriptionInfo;
    sidebarOpen: boolean;
    [key: string]: unknown;
};
