<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const isOpen = ref(false);
const submitted = ref(false);

const form = useForm({
    subject: '',
    message: '',
    page_url: '',
});

const handleOpenRequest = () => {
    submitted.value = false;
    form.clearErrors();
    isOpen.value = true;
};

const submit = () => {
    form.page_url = typeof window !== 'undefined' ? window.location.href : '';

    form.post('/submissions', {
        preserveScroll: true,
        onSuccess: () => {
            submitted.value = true;
            form.reset('subject', 'message');
        },
    });
};

onMounted(() => {
    window.addEventListener('open-feedback-submission-modal', handleOpenRequest);
});

onUnmounted(() => {
    window.removeEventListener('open-feedback-submission-modal', handleOpenRequest);
});
</script>

<template>
    <Dialog :open="isOpen" @update:open="isOpen = $event">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Send feedback</DialogTitle>
                <DialogDescription>
                    Share bugs, ideas, or confusing flows. We store this as a submission.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="submitted"
                class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                Thanks. Your feedback was submitted.
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="feedback-subject">Subject (optional)</Label>
                    <Input
                        id="feedback-subject"
                        v-model="form.subject"
                        type="text"
                        maxlength="255"
                        placeholder="Quick summary"
                    />
                    <InputError :message="form.errors.subject" />
                </div>

                <div class="space-y-2">
                    <Label for="feedback-message">Message</Label>
                    <textarea
                        id="feedback-message"
                        v-model="form.message"
                        rows="7"
                        maxlength="5000"
                        class="flex min-h-[140px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none transition-[color,box-shadow] placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                        placeholder="Tell us what happened and what you expected."
                    />
                    <InputError :message="form.errors.message" />
                </div>

                <DialogFooter class="gap-2 sm:justify-end">
                    <Button type="button" variant="outline" @click="isOpen = false">
                        Close
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Submit feedback
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
