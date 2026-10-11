import { ref } from 'vue';

/**
 * The live unread-messages count behind the sidebar "Messages" badge.
 *
 * Normally that badge comes from the server on every Inertia response
 * (HandleInertiaRequests -> unreadMessages), which means it's
 * only ever as fresh as your last page load: sit on any screen for an
 * hour and it still shows what was true when you arrived. The Messages
 * inbox is already polling for new mail, so it writes the real count in
 * here and AppLayout prefers it over the stale prop.
 *
 * Module scope on purpose — the writer (the inbox page) and the reader
 * (AppLayout's sidebar badge) are siblings, not parent and child, and
 * the writer is destroyed on every navigation away from the inbox while
 * the layout survives it. Neither could own this.
 *
 * The inbox clears it on unmount: away from that page nothing is
 * polling, and the server-rendered prop is then the fresher of the two.
 */
const unreadMessages = ref(null);

export function useUnreadMessages() {
    function setUnreadMessages(count) {
        unreadMessages.value = Number.isFinite(count) ? count : null;
    }

    function clearUnreadMessages() {
        unreadMessages.value = null;
    }

    return { unreadMessages, setUnreadMessages, clearUnreadMessages };
}
