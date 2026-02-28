export * from './auth';
export * from './navigation';
export * from './ui';
export * from './models';
export * from './subscription';
export * from './onboarding';
export * from './sports';
export * from './sport-team';

import type { Auth } from './auth';
import type { SubscriptionInfo } from './subscription';

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    auth: Auth;
    subscription: SubscriptionInfo;
    sidebarOpen: boolean;
    [key: string]: unknown;
};
