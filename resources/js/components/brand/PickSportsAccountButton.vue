<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';
const props = withDefaults(
    defineProps<{
        authenticated: boolean;
        canRegister: boolean;
        compact?: boolean;
    }>(),
    { compact: false },
);
const href = computed(() =>
    props.authenticated
        ? dashboard()
        : props.canRegister
          ? register()
          : login(),
);
const label = computed(() =>
    props.authenticated
        ? props.compact
            ? 'Dashboard'
            : 'Open dashboard'
        : props.canRegister
          ? 'Create account'
          : 'Log in',
);
</script>
<template>
    <Button as-child :size="compact ? 'sm' : 'lg'">
        <Link :href="href"
            >{{ label
            }}<ArrowRight v-if="!compact" aria-hidden="true" class="size-4"
        /></Link>
    </Button>
</template>
