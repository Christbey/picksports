<script setup lang="ts">
import { defineAsyncComponent, onMounted, onUnmounted, ref } from 'vue';

const FeedbackSubmissionModal = defineAsyncComponent(
    () => import('@/components/FeedbackSubmissionModal.vue'),
);
const shouldMount = ref(false);
const isOpen = ref(false);

const handleOpenRequest = () => {
    shouldMount.value = true;
    isOpen.value = true;
};

onMounted(() => {
    window.addEventListener(
        'open-feedback-submission-modal',
        handleOpenRequest,
    );
});

onUnmounted(() => {
    window.removeEventListener(
        'open-feedback-submission-modal',
        handleOpenRequest,
    );
});
</script>

<template>
    <FeedbackSubmissionModal v-if="shouldMount" v-model:open="isOpen" />
</template>
