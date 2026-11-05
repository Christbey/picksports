export const Head = { props: ['title'], render: () => null };
export const router = {
    get: (...args) => globalThis.__adminRouter.get(...args),
    reload: (...args) => globalThis.__adminRouter.reload(...args),
};
