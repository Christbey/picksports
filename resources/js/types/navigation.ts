import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { Component } from 'vue';

export type BreadcrumbItem = {
    title: string;
    href?: string;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: Component;
    iconProps?: Record<string, unknown>;
    isActive?: boolean;
    items?: NavItem[];
};
