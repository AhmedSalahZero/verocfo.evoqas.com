<script setup>
/**
 * One inbox, two tabs:
 *   - "Chat"   : ordinary chat with colleagues (route: messages.start / messages.send)
 *   - "Support": "Super Message" tickets to the Super Admin
 *                (route: messages.support.start), shown ticket-style
 *                with an Open / In Progress / Resolved status.
 *
 * ── How this page stays live ──────────────────────────────────────
 * By polling, not by broadcasting. No broadcast driver is configured
 * (BROADCAST_CONNECTION=log with no Pusher key), so window.Echo doesn't
 * exist in the browser and the Echo listeners below never fire. They're
 * kept so that configuring Pusher later is a config change rather than a
 * rewrite — see MessagingController.
 *
 * Two loops, both built on createPoller (composables/usePoller.js — the
 * response-ordering, abort and backoff guarantees live there):
 *
 *   inboxPoller  — the conversation lists and the sidebar badge, so a
 *                  message in a thread you don't have open still shows
 *                  up.
 *   threadPoller — a delta for the open thread only: messages newer than
 *                  the highest id we hold, plus any message we already
 *                  hold whose deleted flag moved.
 *
 * No <AppLayout> wrapper here: app.js assigns the layout persistently,
 * and wrapping would tear the shell down on every visit.
 *
 * @route     messages.index
 * @layout    AppLayout (applied in app.js)
 * @controller MessagingController::index
 */
import { ref, computed, nextTick, watch, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useToasts } from '@/composables/useToasts';
import { createPoller } from '@/composables/usePoller';
import { useUnreadMessages } from '@/composables/useUnreadMessages';

const props = defineProps({
    isSuperAdmin: Boolean,
    conversations: Object, // { direct: [...], support: [...] }
    contacts: Array,       // [{ id, name, email }]
    routes: Object,        // server-built, locale-correct URLs — see MessagingController::index()
});

const { t } = useI18n();

// routes.show / send / read / status come from the server with a literal
// "__ID__" placeholder swapped in per-conversation (see props.routes docblock
// in the controller) — this fills it in with a real conversation id.
function urlFor(template, id) {
    return template.replace('__ID__', id);
}
function deleteMessageUrl(conversationId, messageId) {
    return props.routes.deleteMessage.replace('__ID__', conversationId).replace('__MSG_ID__', messageId);
}

const { pushToast } = useToasts();
const { setUnreadMessages, clearUnreadMessages } = useUnreadMessages();
const page = usePage();

const currentUserId = computed(() => page.props.auth?.user?.id);
const currentUserName = computed(() => page.props.auth?.user?.name ?? '');

const activeTab = ref('direct'); // 'direct' | 'support'
const lists = ref({
    direct: props.conversations.direct ?? [],
    support: props.conversations.support ?? [],
});

const currentList = computed(() => lists.value[activeTab.value]);

const selected = ref(null);       // the selected conversation summary object
const messages = ref([]);
const loadingMessages = ref(false);
const messageBody = ref('');
const messagesEnd = ref(null);
const messagesPane = ref(null);
/** A message landed while the user was reading further up the thread. */
const newMessagesBelow = ref(false);
let currentChannelName = null;
let pendingSeed = 0;

/**
 * Where the open thread's delta picks up from: the highest message id we
 * hold, and the server's own clock reading from the last response (used
 * as `since` for deletions — never the browser's clock, which is
 * compared against nothing on the server side).
 */
let threadCursor = { id: 0, since: null };

/** Fingerprint of the last inbox snapshot, echoed back so an unchanged inbox is a cheap answer. */
const inboxVersion = ref(null);

function statusLabel(status) {
    return status ? t(`messaging.status.${status}`) : status;
}
function statusBadgeClass(status) {
    return {
        open: 'zv-badge zv-badge-amber',
        in_progress: 'zv-badge zv-badge-blue',
        resolved: 'zv-badge zv-badge-green',
    }[status] ?? 'zv-badge';
}

function isMine(message) {
    return message.user?.id === currentUserId.value;
}

function bubbleStyle(message) {
    if (message.deleted) {
        return 'background: transparent; border: 1px dashed var(--zv-border); color: var(--zv-text-muted); font-style: italic;';
    }
    if (message.failed) {
        return 'background: var(--zv-bg-hover); color: var(--zv-text-primary); border: 1px solid var(--zv-danger);';
    }
    const base = isMine(message)
        ? 'background: var(--zv-green-hover); color: #fff;'
        : 'background: var(--zv-bg-hover); color: var(--zv-text-primary);';

    return message.pending ? `${base} opacity: 0.55;` : base;
}

/* ── Scroll position ─────────────────────────────────────────────
   Auto-scrolling on every refresh used to yank the view down while
   someone was reading older messages. Only follow the conversation if
   they were already at the bottom (or it's their own message); otherwise
   offer them the jump instead of taking it. ───────────────────────── */

/** Within ~80px of the bottom counts as "following along". */
function isNearBottom() {
    const pane = messagesPane.value;
    if (!pane) return true;
    return pane.scrollHeight - pane.scrollTop - pane.clientHeight < 80;
}

function scrollToBottom() {
    newMessagesBelow.value = false;
    nextTick(() => messagesEnd.value?.scrollIntoView({ block: 'end' }));
}

function onPaneScroll() {
    if (isNearBottom()) newMessagesBelow.value = false;
}

/* ── Merging polled data into what's on screen ───────────────────── */

function markListItemUnread(conversationId) {
    for (const key of ['direct', 'support']) {
        const item = lists.value[key].find(c => c.id === conversationId);
        if (item) item.unread = true;
    }
}

function markListItemRead(conversationId) {
    for (const key of ['direct', 'support']) {
        const item = lists.value[key].find(c => c.id === conversationId);
        if (item) item.unread = false;
    }
}

/**
 * Add messages we don't already hold. Shared by the delta poll and the
 * Echo listener so the two paths can't drift apart, and id-based so a
 * message can never appear twice — which matters because our own POST
 * response and the next poll can both carry the same message.
 *
 * Returns whether anything was actually new (drives the poller's
 * adaptive interval).
 */
function appendMessages(incoming, conversationId) {
    const fresh = incoming.filter(m => !messages.value.some(held => held.id === m.id));
    if (fresh.length === 0) return false;

    // Read before mutating: pushing changes scrollHeight.
    const following = isNearBottom();

    // A poll can beat our own send response back, in which case our
    // optimistic placeholder is still on screen. Retire it here rather
    // than briefly show the same message twice; deliver() copes with
    // finding it already gone.
    for (const message of fresh) {
        if (!isMine(message)) continue;
        const placeholder = messages.value.findIndex(held => held.pending && held.body === message.body);
        if (placeholder !== -1) messages.value.splice(placeholder, 1);
    }

    messages.value.push(...fresh);
    threadCursor.id = Math.max(threadCursor.id, ...fresh.map(m => m.id));

    if (following || fresh.every(isMine)) scrollToBottom();
    else newMessagesBelow.value = true;

    // We're looking at this thread, so it isn't unread however the list
    // snapshot was computed a moment ago.
    markListItemRead(conversationId);

    return true;
}

function applyThreadUpdates(data) {
    if (!data) return false;
    // createPoller guarantees ordering; this guards identity — the user
    // may have switched threads while the request was in flight.
    if (selected.value?.id !== data.conversationId) return false;

    threadCursor.since = data.server_time ?? threadCursor.since;

    let changed = false;

    // Deletions (and any future edit) to messages we already hold. A
    // message only ever goes to deleted, so re-applying is harmless.
    for (const patch of data.changed ?? []) {
        const held = messages.value.find(m => m.id === patch.id);
        if (held && held.deleted !== patch.deleted) {
            held.deleted = patch.deleted;
            held.body = patch.body;
            changed = true;
        }
    }

    if (appendMessages(data.messages ?? [], data.conversationId)) changed = true;

    if (typeof data.last_id === 'number' && data.last_id > threadCursor.id) {
        threadCursor.id = data.last_id;
    }

    // A Super Admin moving a ticket to Resolved reaches the reporter here.
    if (data.conversation && data.conversation.status !== selected.value.status) {
        selected.value.status = data.conversation.status;
        const item = lists.value.support.find(c => c.id === data.conversationId);
        if (item) item.status = data.conversation.status;
        changed = true;
    }

    // Whatever moved in here moved the list previews and the badge too.
    if (changed) inboxPoller.poke();

    return changed;
}

/**
 * Fold a polled snapshot into the list already on screen, matching on
 * id instead of swapping the array: `selected` and the row the user is
 * hovering both point into these objects, and Vue shouldn't re-render
 * every row because one preview line changed. Rebuilding in the
 * snapshot's order preserves the server's "newest activity first".
 */
function mergeList(key, incoming) {
    const held = new Map(lists.value[key].map(c => [c.id, c]));

    lists.value[key] = incoming.map((fresh) => {
        const current = held.get(fresh.id);
        if (!current) return fresh;

        Object.assign(current, fresh);
        if (selected.value?.id === current.id) current.unread = false;

        return current;
    });
}

function applyInboxSnapshot(data) {
    if (data?.version) inboxVersion.value = data.version;
    if (!data || data.changed === false) return false;

    setUnreadMessages(data.unread_total);
    mergeList('direct', data.conversations?.direct ?? []);
    mergeList('support', data.conversations?.support ?? []);

    return true;
}

/* ── The two polling loops ───────────────────────────────────────── */

const inboxPoller = createPoller({
    interval: 8000,
    maxInterval: 30000,
    request: (signal) => window.axios
        .get(props.routes.poll, { signal, params: { version: inboxVersion.value } })
        .then(response => response.data),
    onResult: applyInboxSnapshot,
});

/**
 * With a broadcasting driver configured, Echo delivers messages
 * instantly and this drops to a slow safety net for anything a dropped
 * socket missed, instead of switching off entirely.
 */
const liveDriverActive = Boolean(window.Echo);

const threadPoller = createPoller({
    interval: liveDriverActive ? 30000 : 3000,
    maxInterval: liveDriverActive ? 60000 : 15000,
    request: (signal) => {
        const conversationId = selected.value?.id;
        if (!conversationId) return Promise.resolve(null);

        return window.axios.get(urlFor(props.routes.updates, conversationId), {
            signal,
            params: {
                after_id: threadCursor.id,
                since: threadCursor.since,
                // Fetch and mark-read in one trip. The poller only runs
                // while this thread is open and the tab is visible, so
                // anything it returns has genuinely been seen.
                mark_read: 1,
            },
        }).then(response => ({ conversationId, ...response.data }));
    },
    onResult: applyThreadUpdates,
});

const connectionLost = computed(() => inboxPoller.failing.value || threadPoller.failing.value);

/* ── Optional realtime path (only if Pusher is ever configured) ──── */

function leaveChannel() {
    if (currentChannelName && window.Echo) {
        window.Echo.leave(currentChannelName);
    }
    currentChannelName = null;
}

function joinChannel(conversationId) {
    if (!window.Echo) return; // no broadcasting driver configured yet
    currentChannelName = `conversation.${conversationId}`;
    window.Echo.private(currentChannelName).listen('.message.sent', (payload) => {
        if (selected.value?.id === conversationId) {
            appendMessages([payload], conversationId);
        } else {
            // Message arrived for a conversation that's not open right now —
            // just flag it unread in the list.
            markListItemUnread(conversationId);
        }
    }).listen('.message.deleted', (payload) => {
        if (selected.value?.id === conversationId) {
            const m = messages.value.find(m => m.id === payload.id);
            if (m) {
                m.deleted = true;
                m.body = null;
            }
        }
    });
}

/* ── Opening a conversation ──────────────────────────────────────── */

async function selectConversation(item) {
    leaveChannel();
    threadPoller.stop();
    selected.value = item;
    messages.value = [];
    threadCursor = { id: 0, since: null };
    newMessagesBelow.value = false;
    loadingMessages.value = true;

    try {
        const { data } = await window.axios.get(urlFor(props.routes.show, item.id));
        // Clicked away while this was loading — the newer click owns the pane.
        if (selected.value?.id !== item.id) return;

        messages.value = data.messages;
        threadCursor = { id: data.last_id ?? 0, since: data.server_time ?? null };
        selected.value = { ...item, ...data.conversation };
        scrollToBottom();

        await window.axios.post(urlFor(props.routes.read, item.id));
        if (selected.value?.id !== item.id) return;

        item.unread = false;
        // The badge just dropped; don't make anyone wait out the idle interval.
        inboxPoller.poke();
        joinChannel(item.id);
        threadPoller.start();
    } catch (e) {
        pushToast('error', t('messaging.errors.loadConversation'));
    } finally {
        if (selected.value?.id === item.id) loadingMessages.value = false;
    }
}

/* ── Sending ─────────────────────────────────────────────────────── */

function sendMessage() {
    const body = messageBody.value.trim();
    if (!body || !selected.value) return;
    messageBody.value = '';
    deliver(selected.value.id, body);
}

/**
 * Optimistic: the bubble appears immediately, greyed out, and either
 * settles into the real message or turns into something retryable.
 */
async function deliver(conversationId, body, retryId = null) {
    const placeholderId = retryId ?? `pending-${++pendingSeed}`;

    if (retryId) {
        const held = messages.value.find(m => m.id === retryId);
        if (held) {
            held.pending = true;
            held.failed = false;
        }
    } else {
        messages.value.push({
            id: placeholderId,
            body,
            deleted: false,
            pending: true,
            failed: false,
            created_at: new Date().toISOString(),
            user: { id: currentUserId.value, name: currentUserName.value },
        });
        scrollToBottom();
    }

    try {
        const { data } = await window.axios.post(urlFor(props.routes.send, conversationId), { body });

        const index = messages.value.findIndex(m => m.id === placeholderId);
        if (index !== -1) {
            // A poll can occasionally beat our own response to the real
            // message; drop the placeholder rather than show it twice.
            const already = messages.value.some(m => m.id === data.message.id);
            messages.value.splice(index, 1, ...(already ? [] : [data.message]));
        }

        threadCursor.id = Math.max(threadCursor.id, data.message.id);
        scrollToBottom();
        // Sending is activity: pull both loops back to their base rate.
        threadPoller.poke(false);
        inboxPoller.poke();
    } catch (e) {
        const held = messages.value.find(m => m.id === placeholderId);
        if (held) {
            held.pending = false;
            held.failed = true;
        }
        pushToast('error', t('messaging.errors.send'));
    }
}

function retrySend(message) {
    if (!selected.value) return;
    deliver(selected.value.id, message.body, message.id);
}

function discardMessage(message) {
    messages.value = messages.value.filter(m => m.id !== message.id);
}

/* ── Deleting / ticket status ────────────────────────────────────── */

async function deleteMessage(message) {
    if (!confirm(t('messaging.inbox.confirmDelete'))) return;
    try {
        await window.axios.delete(deleteMessageUrl(selected.value.id, message.id));
        message.deleted = true;
        message.body = null;
        // The list preview may have been this message.
        inboxPoller.poke();
    } catch (e) {
        pushToast('error', t('messaging.errors.delete'));
    }
}

async function updateStatus(status) {
    if (!selected.value) return;
    try {
        await window.axios.patch(urlFor(props.routes.status, selected.value.id), { status });
        selected.value.status = status;
        const item = lists.value.support.find(c => c.id === selected.value.id);
        if (item) item.status = status;
        pushToast('success', t('messaging.inbox.statusUpdated'));
    } catch (e) {
        pushToast('error', t('messaging.errors.status'));
    }
}

/* ── Start a new direct chat ────────────────────────────────────── */
const showNewChat = ref(false);
const newChatUserId = ref('');
const newChatText = ref('');

async function startDirectChat() {
    if (!newChatUserId.value) {
        pushToast('error', t('messaging.errors.choosePerson'));
        return;
    }
    if (!newChatText.value.trim()) {
        pushToast('error', t('messaging.errors.writeMessage'));
        return;
    }
    try {
        const { data } = await window.axios.post(props.routes.start, {
            user_id: newChatUserId.value,
            body: newChatText.value.trim(),
        });
        showNewChat.value = false;
        newChatUserId.value = '';
        newChatText.value = '';
        await refreshAndOpen('direct', data.conversation_id);
    } catch (e) {
        pushToast('error', t('messaging.errors.startConversation'));
    }
}

/* ── Raise a new Super Message support ticket ───────────────────── */
const showNewTicket = ref(false);
const ticketSubject = ref(''); // optional — auto-filled from the message if left blank
const ticketBody = ref('');

async function startSupportTicket() {
    if (!ticketBody.value.trim()) {
        pushToast('error', t('messaging.errors.describeIssue'));
        return;
    }
    // Subject is a nice-to-have, not something the person must stop and
    // fill in — unlike Chat, there's no name to pick here at all, since
    // it always goes to the Super Admin.
    const subject = ticketSubject.value.trim() || ticketBody.value.trim().slice(0, 60);
    try {
        const { data } = await window.axios.post(props.routes.support, {
            subject,
            body: ticketBody.value.trim(),
        });
        showNewTicket.value = false;
        ticketSubject.value = '';
        ticketBody.value = '';
        await refreshAndOpen('support', data.conversation_id);
    } catch (e) {
        pushToast('error', t('messaging.errors.startSupport'));
    }
}

/**
 * After creating something new, pull the inbox once — the same endpoint
 * the background loop uses — and open the new thread.
 */
async function refreshAndOpen(tab, conversationId) {
    activeTab.value = tab;

    try {
        // No `version`: we want the full snapshot, not "nothing changed".
        const { data } = await window.axios.get(props.routes.poll);
        applyInboxSnapshot(data);
    } catch (e) {
        // Not worth an error toast — the poller catches up on its own.
    }

    const item = lists.value[tab].find(c => c.id === conversationId);
    if (item) await selectConversation(item);
}

/* ── Lifecycle ───────────────────────────────────────────────────── */

watch(selected, (next, previous) => {
    if (next && previous && next.id === previous.id) return;
    // Also covers the mobile "Back" button (selected = null), which used
    // to leave the old timer firing every few seconds against a thread
    // nobody had open any more.
    threadPoller.stop();
    newMessagesBelow.value = false;
});

onMounted(() => inboxPoller.start());

onBeforeUnmount(() => {
    leaveChannel();
    threadPoller.stop();
    inboxPoller.stop();
    // Nothing polls off this page, so the server-rendered badge on the
    // next navigation is the fresher of the two numbers.
    clearUnreadMessages();
});
</script>

<template>
    <div class="zv-page msg-page">
        <div class="zv-page-header">
            <h1 class="zv-page-title">{{ t('messaging.inbox.title') }}</h1>
            <span v-if="connectionLost" class="zv-text-muted" style="font-size: 0.75rem;">{{ t('messaging.inbox.reconnecting') }}</span>
        </div>

        <div class="zv-card msg-shell">
            <!-- Left: conversation list -->
            <div class="msg-list-pane" :class="{ 'msg-hide-sm': selected }">
                <div class="zv-tabs">
                    <button
                        type="button"
                        class="zv-tab"
                        :class="{ active: activeTab === 'direct' }"
                        @click="activeTab = 'direct'"
                    >{{ t('messaging.inbox.chatTab') }}</button>
                    <button
                        type="button"
                        class="zv-tab"
                        :class="{ active: activeTab === 'support' }"
                        @click="activeTab = 'support'"
                    >{{ isSuperAdmin ? t('messaging.inbox.supportInbox') : t('messaging.inbox.superMessage') }}</button>
                </div>

                <div class="msg-list-actions">
                    <button v-if="activeTab === 'direct'" type="button" class="zv-btn zv-btn-primary zv-btn-sm msg-block-btn" @click="showNewChat = true">
                        + {{ t('messaging.inbox.newMessage') }}
                    </button>
                    <button v-else type="button" class="zv-btn zv-btn-warning zv-btn-sm msg-block-btn" @click="showNewTicket = true">
                        + {{ t('messaging.inbox.reportIssue') }}
                    </button>
                </div>

                <div class="msg-list-scroll">
                    <div v-if="currentList.length === 0" class="zv-text-muted msg-list-empty">
                        {{ t('messaging.inbox.noConversations') }}
                    </div>
                    <button
                        v-for="item in currentList"
                        :key="item.id"
                        type="button"
                        class="msg-list-row"
                        :class="{ 'msg-list-row-active': selected?.id === item.id }"
                        @click="selectConversation(item)"
                    >
                        <span class="zv-avatar msg-avatar">{{ (item.title || '?').charAt(0).toUpperCase() }}</span>
                        <span class="msg-list-body">
                            <span class="msg-list-top">
                                <span class="msg-list-title">{{ item.title }}</span>
                                <span v-if="item.unread" class="msg-unread-dot"></span>
                            </span>
                            <span v-if="item.type === 'support'" :class="statusBadgeClass(item.status)" class="msg-list-status">
                                {{ statusLabel(item.status) }}
                            </span>
                            <span class="msg-list-preview">
                                {{ item.last_message?.body || t('messaging.inbox.noMessagesYet') }}
                            </span>
                        </span>
                    </button>
                </div>
            </div>

            <!-- Right: selected conversation -->
            <div class="msg-thread-pane" :class="{ 'msg-hide-sm': !selected }">
                <template v-if="selected">
                    <div class="msg-thread-header">
                        <div class="msg-thread-heading">
                            <button type="button" class="msg-back zv-text-muted" @click="selected = null">&larr; {{ t('messaging.inbox.back') }}</button>
                            <div class="msg-thread-title">{{ selected.title }}</div>
                        </div>
                        <select
                            v-if="isSuperAdmin && selected.type === 'support'"
                            class="zv-select msg-status-select"
                            :value="selected.status"
                            @change="updateStatus($event.target.value)"
                        >
                            <option value="open">{{ t('messaging.status.open') }}</option>
                            <option value="in_progress">{{ t('messaging.status.in_progress') }}</option>
                            <option value="resolved">{{ t('messaging.status.resolved') }}</option>
                        </select>
                    </div>

                    <div class="msg-thread-body">
                        <div ref="messagesPane" class="msg-messages" @scroll.passive="onPaneScroll">
                            <div v-if="loadingMessages" class="zv-text-muted msg-loading">{{ t('messaging.inbox.loading') }}</div>
                            <div v-for="m in messages" :key="m.id" class="msg-row" :class="isMine(m) ? 'msg-row-mine' : ''">
                                <div class="msg-bubble-line" :class="isMine(m) ? 'msg-bubble-line-mine' : ''">
                                    <div class="msg-bubble" :style="bubbleStyle(m)">
                                        <div v-if="!m.deleted && !isMine(m)" class="msg-author">{{ m.user.name }}</div>
                                        <div v-if="m.deleted" class="msg-text">{{ t('messaging.inbox.messageDeleted') }}</div>
                                        <div v-else class="msg-text">{{ m.body }}</div>
                                    </div>
                                    <button
                                        v-if="!m.deleted && !m.pending && !m.failed && isMine(m)"
                                        type="button"
                                        class="msg-delete zv-text-muted"
                                        :title="t('messaging.inbox.deleteMessage')"
                                        @click="deleteMessage(m)"
                                    >🗑</button>
                                </div>
                                <div v-if="m.failed" class="msg-failed" :class="isMine(m) ? 'msg-meta-mine' : ''">
                                    <span>{{ t('messaging.inbox.notSent') }}</span>
                                    <button type="button" class="msg-link" @click="retrySend(m)">{{ t('messaging.inbox.retry') }}</button>
                                    <button type="button" class="msg-link zv-text-muted" @click="discardMessage(m)">{{ t('messaging.inbox.discard') }}</button>
                                </div>
                                <div v-else class="msg-meta zv-text-muted" :class="isMine(m) ? 'msg-meta-mine' : ''">
                                    {{ m.pending ? t('messaging.inbox.sending') : new Date(m.created_at).toLocaleString() }}
                                </div>
                            </div>
                            <div ref="messagesEnd"></div>
                        </div>

                        <!-- Offer the jump instead of yanking the view down mid-read. -->
                        <button
                            v-if="newMessagesBelow"
                            type="button"
                            class="zv-btn zv-btn-primary zv-btn-sm msg-jump"
                            @click="scrollToBottom"
                        >{{ t('messaging.inbox.newMessagesBelow') }} &darr;</button>
                    </div>

                    <form class="msg-composer" @submit.prevent="sendMessage">
                        <input v-model="messageBody" class="zv-input" :placeholder="t('messaging.inbox.typeMessage')" />
                        <button type="submit" class="zv-btn zv-btn-primary">{{ t('messaging.inbox.send') }}</button>
                    </form>
                </template>
                <div v-else class="msg-empty zv-text-muted">
                    {{ t('messaging.inbox.selectConversation') }}
                </div>
            </div>
        </div>

        <!-- New direct message modal -->
        <div v-if="showNewChat" class="zv-modal-backdrop" @click.self="showNewChat = false">
            <div class="zv-modal zv-modal-sm">
                <div class="zv-modal-header">
                    <h2 class="zv-modal-title">{{ t('messaging.inbox.newMessage') }}</h2>
                    <button type="button" class="zv-modal-close" @click="showNewChat = false">✕</button>
                </div>
                <div class="zv-modal-body">
                    <select v-model="newChatUserId" class="zv-select msg-full">
                        <option value="" disabled>{{ t('messaging.inbox.choosePerson') }}</option>
                        <option v-for="c in contacts" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <textarea v-model="newChatText" rows="3" class="zv-textarea msg-full" :placeholder="t('messaging.inbox.writeMessage')"></textarea>
                </div>
                <div class="zv-modal-footer">
                    <button type="button" class="zv-btn zv-btn-secondary" @click="showNewChat = false">{{ t('messaging.inbox.cancel') }}</button>
                    <button type="button" class="zv-btn zv-btn-primary" @click="startDirectChat">{{ t('messaging.inbox.send') }}</button>
                </div>
            </div>
        </div>

        <!-- New support ticket modal -->
        <div v-if="showNewTicket" class="zv-modal-backdrop" @click.self="showNewTicket = false">
            <div class="zv-modal zv-modal-sm">
                <div class="zv-modal-header">
                    <h2 class="zv-modal-title">{{ t('messaging.inbox.reportIssue') }}</h2>
                    <button type="button" class="zv-modal-close" @click="showNewTicket = false">✕</button>
                </div>
                <div class="zv-modal-body">
                    <p class="zv-text-muted msg-hint">{{ t('messaging.inbox.supportHint') }}</p>
                    <input v-model="ticketSubject" class="zv-input msg-full" :placeholder="t('messaging.inbox.subjectPlaceholder')" />
                    <textarea v-model="ticketBody" rows="4" class="zv-textarea msg-full" :placeholder="t('messaging.inbox.describeProblem')"></textarea>
                </div>
                <div class="zv-modal-footer">
                    <button type="button" class="zv-btn zv-btn-secondary" @click="showNewTicket = false">{{ t('messaging.inbox.cancel') }}</button>
                    <button type="button" class="zv-btn zv-btn-warning" @click="startSupportTicket">{{ t('messaging.inbox.submit') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* The inbox is the one screen that fills the viewport instead of
   scrolling the page: the list and the thread each scroll on their own. */
.msg-page        { display: flex; flex-direction: column; height: calc(100vh - 7rem); }
.msg-shell       { flex: 1; min-height: 0; display: flex; overflow: hidden; }

.msg-list-pane   { width: 20rem; flex-shrink: 0; border-inline-end: 1px solid var(--zv-border); display: flex; flex-direction: column; }
.msg-list-actions{ padding: 0.5rem; }
.msg-block-btn   { width: 100%; justify-content: center; }
.msg-list-scroll { flex: 1; overflow-y: auto; }
.msg-list-empty  { padding: 1rem; font-size: 0.8125rem; text-align: center; }

.msg-list-row    { width: 100%; text-align: start; padding: 0.75rem; border-bottom: 1px solid var(--zv-border); display: flex; align-items: flex-start; gap: 0.5rem; background: none; }
.msg-list-row:hover        { background: var(--zv-bg-hover); }
.msg-list-row-active       { background: var(--zv-bg-hover); }
.msg-avatar      { flex-shrink: 0; }
.msg-list-body   { min-width: 0; flex: 1; }
.msg-list-top    { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.msg-list-title  { font-size: 0.8125rem; font-weight: 600; color: var(--zv-text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.msg-unread-dot  { width: 0.5rem; height: 0.5rem; border-radius: 9999px; flex-shrink: 0; background: var(--zv-danger); }
.msg-list-status { font-size: 0.625rem; margin-top: 0.125rem; }
.msg-list-preview{ display: block; font-size: 0.75rem; color: var(--zv-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 0.125rem; }

.msg-thread-pane { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.msg-thread-header { border-bottom: 1px solid var(--zv-border); padding: 0.75rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.msg-thread-heading{ min-width: 0; }
.msg-thread-title  { font-weight: 600; color: var(--zv-text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.msg-status-select { font-size: 0.8125rem; width: auto; }
.msg-back        { display: none; font-size: 0.75rem; margin-bottom: 0.25rem; background: none; }

.msg-thread-body { flex: 1; min-height: 0; position: relative; display: flex; flex-direction: column; }
.msg-messages    { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem; }
.msg-loading     { font-size: 0.8125rem; text-align: center; }

.msg-row         { max-width: 75%; }
.msg-row-mine    { margin-inline-start: auto; text-align: end; }
.msg-bubble-line { display: inline-flex; align-items: center; gap: 0.25rem; }
.msg-bubble-line-mine { flex-direction: row-reverse; }
.msg-bubble      { display: inline-block; border-radius: var(--zv-radius-md); padding: 0.5rem 0.75rem; font-size: 0.8125rem; }
.msg-author      { font-size: 0.6875rem; font-weight: 600; margin-bottom: 0.125rem; opacity: 0.7; }
.msg-text        { white-space: pre-wrap; overflow-wrap: anywhere; }
.msg-delete      { opacity: 0; transition: opacity 0.15s ease; font-size: 0.75rem; padding: 0 0.25rem; background: none; }
.msg-row:hover .msg-delete { opacity: 1; }
.msg-delete:hover          { color: var(--zv-danger); }

.msg-meta        { font-size: 0.625rem; margin-top: 0.125rem; }
.msg-meta-mine   { justify-content: flex-end; }
.msg-failed      { font-size: 0.625rem; margin-top: 0.125rem; display: flex; align-items: center; gap: 0.5rem; color: var(--zv-danger); }
.msg-link        { text-decoration: underline; background: none; }

.msg-jump        { position: absolute; bottom: 0.75rem; left: 50%; transform: translateX(-50%); border-radius: 9999px; box-shadow: var(--zv-shadow-md); }

.msg-composer    { border-top: 1px solid var(--zv-border); padding: 0.75rem; display: flex; gap: 0.5rem; }
.msg-composer .zv-input { flex: 1; }
.msg-empty       { flex: 1; display: flex; align-items: center; justify-content: center; font-size: 0.8125rem; }

.msg-full        { width: 100%; }
.zv-modal-body .msg-full + .msg-full { margin-top: 0.75rem; }
.msg-hint        { font-size: 0.75rem; margin-bottom: 0.75rem; }

/* Phone: one pane at a time, with the Back button to return to the list. */
@media (max-width: 640px) {
    .msg-list-pane { width: 100%; border-inline-end: none; }
    .msg-hide-sm   { display: none; }
    .msg-back      { display: block; }
    .msg-row       { max-width: 90%; }
}
</style>
