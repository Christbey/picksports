import v2 from '@/routes/v2';
import type { ApiV2SportSlug } from '@/types';
import type { RouteQueryOptions } from '@/wayfinder';

export const payloadInspectorProfiles = [
    'dashboard',
    'live-scoreboard',
    'sport-predictions',
    'player-props',
    'admin-healthcheck-cards',
    'user-bets',
    'cbb-brackets',
    'settings-admin',
    'alert-preferences',
] as const;

export type PayloadInspectorProfile = (typeof payloadInspectorProfiles)[number];

type PayloadInspectorUrlOptions = {
    date?: string;
    includePayload?: boolean;
    includeWarnings?: boolean;
    profile: PayloadInspectorProfile;
    sport?: ApiV2SportSlug;
    sports?: ApiV2SportSlug[];
};

type PayloadInspectorProfileLink = {
    description: string;
    href: string;
    profile: PayloadInspectorProfile;
    title: string;
};

const profileLabels: Record<
    PayloadInspectorProfile,
    Pick<PayloadInspectorProfileLink, 'description' | 'title'>
> = {
    dashboard: {
        title: 'Dashboard Payload',
        description: 'Validate the migrated dashboard API v2 contracts.',
    },
    'live-scoreboard': {
        title: 'Live Scoreboard Payload',
        description: 'Validate the live rail payload used in the app shell.',
    },
    'sport-predictions': {
        title: 'Prediction Payload',
        description: 'Validate sport prediction list and field access payloads.',
    },
    'player-props': {
        title: 'Player Props Payload',
        description: 'Validate player prop market payloads and freshness.',
    },
    'admin-healthcheck-cards': {
        title: 'Healthcheck Payload',
        description: 'Validate admin healthcheck card payload contracts.',
    },
    'user-bets': {
        title: 'User Bets Payload',
        description: 'Validate saved pick and bet tracker payload contracts.',
    },
    'cbb-brackets': {
        title: 'CBB Brackets Payload',
        description: 'Validate March Madness bracket and group payloads.',
    },
    'settings-admin': {
        title: 'Admin Settings Payload',
        description: 'Validate admin settings support payloads.',
    },
    'alert-preferences': {
        title: 'Alert Preferences Payload',
        description: 'Validate alert preference and web push payloads.',
    },
};

const routeOptions = (
    options: PayloadInspectorUrlOptions,
): RouteQueryOptions => {
    const query: Record<string, boolean | string> = {
        profile: options.profile,
    };

    if (options.date) {
        query.date = options.date;
    }

    if (options.sports?.length) {
        query.sports = options.sports.join(',');
    } else if (options.sport) {
        query.sports = options.sport;
    }

    if (options.includePayload !== undefined) {
        query.include_payload = options.includePayload;
    }

    if (options.includeWarnings !== undefined) {
        query.include_warnings = options.includeWarnings;
    }

    return { query };
};

export function usePayloadInspector() {
    const urlFor = (options: PayloadInspectorUrlOptions): string =>
        v2.admin.payloadInspector.url(routeOptions(options));

    const profileLinks = (
        defaults: Omit<PayloadInspectorUrlOptions, 'profile'> = {},
    ): PayloadInspectorProfileLink[] =>
        payloadInspectorProfiles.map((profile) => ({
            ...profileLabels[profile],
            href: urlFor({ ...defaults, profile }),
            profile,
        }));

    return {
        profileLinks,
        profiles: payloadInspectorProfiles,
        urlFor,
    };
}
