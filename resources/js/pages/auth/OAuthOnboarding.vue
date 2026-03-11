<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AuthBase from '@/layouts/AuthLayout.vue';

defineProps<{
    submitUrl: string;
    termsUrl: string;
}>();
</script>

<template>
    <AuthBase
        title="Finish setting up your account"
        description="Before you can continue, confirm that you meet the age requirement."
    >
        <Head title="Complete setup" />

        <Form :action="submitUrl" method="post" v-slot="{ errors, processing }" class="flex flex-col gap-6">
            <div class="grid gap-4">
                <label for="age_verified" class="flex items-start gap-3 text-sm leading-5 text-muted-foreground">
                    <input
                        id="age_verified"
                        type="checkbox"
                        name="age_verified"
                        value="1"
                        required
                        class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                    />
                    <span>
                        I confirm that I am at least 18 years of age and agree to the
                        <Link :href="termsUrl" target="_blank" class="text-primary hover:underline">Terms of Service</Link>.
                    </span>
                </label>

                <InputError :message="errors.age_verified" />

                <Button type="submit" class="w-full" :disabled="processing">
                    Complete setup
                </Button>
            </div>
        </Form>
    </AuthBase>
</template>
