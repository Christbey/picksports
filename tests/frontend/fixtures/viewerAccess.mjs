import { ref } from 'vue';
const isAdmin = ref(false);
export const setViewerAdmin = (value) => {
    isAdmin.value = value;
};
export const useViewerAccess = () => ({ isAdmin });
