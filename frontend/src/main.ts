import { createApp } from 'vue';
import { configureApiClient } from '@/composables/useApiClient';
import { initializeTheme } from '@/composables/useAppearance';
import './style.css';
import App from './App.vue';
import { router } from './router';
configureApiClient(import.meta.env.VITE_API_ORIGIN || '');
initializeTheme();
createApp(App).use(router).mount('#app');
