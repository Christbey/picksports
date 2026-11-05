<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { usePayloadInspector } from '@/composables/usePayloadInspector';
const payloadInspector = usePayloadInspector();
const payloadInspectorLinks = payloadInspector.profileLinks({
    includePayload: true,
    includeWarnings: true,
});
const adminAreas = [
    {
        title: 'Users',
        description: 'View total users, new users today, and submission inbox.',
        href: '/admin/users',
    },
    {
        title: 'Submissions',
        description:
            'Jump directly to form submissions in the admin users view.',
        href: '/admin/users#submissions',
    },
    {
        title: 'Prop Exports',
        description:
            'Export player prop cards optimized for Instagram and Facebook.',
        href: '/settings/prop-exports',
    },
    {
        title: 'Subscriptions',
        description: 'Manage user plans and billing access.',
        href: '/admin/subscriptions',
    },
    {
        title: 'Tiers',
        description: 'Configure subscription tiers and feature flags.',
        href: '/admin/tiers',
    },
    {
        title: 'Permissions',
        description: 'Control role capabilities and overrides.',
        href: '/admin/permissions',
    },
    {
        title: 'Healthchecks',
        description: 'Monitor heartbeat and data validation status.',
        href: '/admin/healthchecks',
    },
    {
        title: 'NFL Model Monitor',
        description:
            'Review model lineage, shadow decisions, and settlement performance.',
        href: '/admin/nfl-model-monitoring',
    },
    {
        title: 'MLB Model Monitor',
        description:
            'Track MLB artifacts, live shadow results, data coverage, and inference health.',
        href: '/admin/mlb-model-monitoring',
    },
    {
        title: 'Payload Inspector',
        description: 'Validate API v2 payload contracts for migrated surfaces.',
        href: payloadInspector.urlFor({
            profile: 'dashboard',
            includePayload: true,
        }),
        external: true,
        links: payloadInspectorLinks,
    },
    {
        title: 'Prediction Access Debug',
        description:
            'Inspect tier and permission-based prediction field access.',
        href: '/debug/prediction-access',
    },
    {
        title: 'Team Mappings',
        description: 'Resolve odds provider and internal team mapping gaps.',
        href: '/settings/team-mappings?provider=odds',
    },
    {
        title: 'CFBD Team Mappings',
        description:
            'Resolve CollegeFootballData team names to internal CFB teams.',
        href: '/settings/team-mappings?provider=cfbd&sport=americanfootball_ncaaf',
    },
    {
        title: 'Player Mappings',
        description:
            'Resolve odds provider player names when fuzzy matching fails.',
        href: '/settings/player-mappings',
    },
];
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div
            v-for="area in adminAreas"
            :key="area.href"
            class="rounded-xl border border-sidebar-border bg-white p-5 dark:bg-sidebar"
        >
            <h3 class="text-sm font-semibold">{{ area.title }}</h3>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ area.description }}
            </p>
            <div
                v-if="'links' in area"
                class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2"
            >
                <a
                    v-for="link in area.links"
                    :key="link.profile"
                    :href="link.href"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded-md border border-sidebar-border px-3 py-2 text-xs font-medium text-foreground transition hover:border-primary hover:text-primary"
                >
                    {{ link.title }}
                </a>
            </div>
            <Button as-child variant="outline" class="mt-4">
                <a
                    v-if="area.external"
                    :href="area.href"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Open
                </a>
                <Link v-else :href="area.href">Open</Link>
            </Button>
        </div>
    </div>
</template>
