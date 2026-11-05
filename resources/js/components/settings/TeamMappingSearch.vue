<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTeamMappingsContext } from '@/composables/useTeamMappings';
const { entityTitle, searchQuery } = useTeamMappingsContext();
</script>
<template>
    <div class="grid gap-2">
        <Label for="search">Search</Label>
        <Input
            id="search"
            v-model="searchQuery"
            :placeholder="`Search by ${entityTitle.toLowerCase()} name...`"
            class="w-full"
        />
    </div>
</template>
