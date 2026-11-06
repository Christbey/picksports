type AutoFocusHandlers = {
    open: (event: Event) => void;
    close: (event: Event) => void;
};

export function useDialogFocusReturn(handlers: AutoFocusHandlers) {
    let opener: HTMLElement | null = null;

    function onOpenAutoFocus(event: Event): void {
        opener =
            typeof document !== 'undefined' &&
            document.activeElement instanceof HTMLElement &&
            document.activeElement !== document.body
                ? document.activeElement
                : null;
        handlers.open(event);
    }

    function onCloseAutoFocus(event: Event): void {
        handlers.close(event);
        if (!event.defaultPrevented && opener?.isConnected) {
            event.preventDefault();
            opener.focus({ preventScroll: true });
        }
        opener = null;
    }

    return { onOpenAutoFocus, onCloseAutoFocus };
}
