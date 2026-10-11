import { ref } from 'vue'

/**
 * Transient client-side notices ("Message could not be sent.").
 *
 * The server's own flash messages already have a home: AppLayout reads
 * page.props.flash and renders the .vero-flash banners. That path only
 * covers whole-page Inertia responses though — the Messages inbox talks
 * to the server over axios and never triggers one, so a failed send had
 * no way to say so.
 *
 * Module scope on purpose: pages push, the persistent AppLayout renders,
 * and the two are siblings with no shared ancestor below the root.
 */
const toasts = ref([])
let toastSeed = 0

export function useToasts() {
    function pushToast(type, message, duration = 5000) {
        const id = ++toastSeed
        toasts.value.push({ id, type, message, duration })
        setTimeout(() => dismissToast(id), duration)
    }

    function dismissToast(id) {
        toasts.value = toasts.value.filter(t => t.id !== id)
    }

    return { toasts, pushToast, dismissToast }
}
