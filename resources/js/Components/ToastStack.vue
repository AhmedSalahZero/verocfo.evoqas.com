<script setup>
/**
 * ToastStack.vue
 * ------------------------------------------------------------------
 * The visible half of useToasts. Purely presentational — the parent
 * (AppLayout) owns the array and handles `dismiss`.
 *
 * Styled from .zv-toast / .zv-toast-container, which were already in
 * app.css with nothing rendering them; the success/danger tinting uses
 * the same tokens as .zv-alert-success / .zv-alert-danger so a toast
 * and an inline alert read as the same product.
 */
defineProps({
    toasts: { type: Array, default: () => [] }, // [{ id, type: 'success'|'error', message, duration }]
})
const emit = defineEmits(['dismiss'])

function tone(type) {
    return type === 'success'
        ? { bg: 'var(--zv-green-light)', border: 'var(--zv-green-border)', text: 'var(--zv-green-text)', bar: 'var(--zv-green)' }
        : { bg: 'var(--zv-danger-light)', border: 'var(--zv-danger-border)', text: 'var(--zv-danger-text)', bar: 'var(--zv-danger)' }
}
</script>

<template>
    <div class="zv-toast-container">
        <TransitionGroup name="zv-toast">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="zv-toast zv-toast-item"
                :style="{ background: tone(toast.type).bg, borderColor: tone(toast.type).border, color: tone(toast.type).text }"
            >
                <span class="zv-toast-icon">{{ toast.type === 'success' ? '✓' : '⚠' }}</span>
                <p class="zv-toast-text">{{ toast.message }}</p>
                <button type="button" class="zv-toast-close" @click="emit('dismiss', toast.id)">✕</button>
                <span class="zv-toast-progress" :style="{ background: tone(toast.type).bar, animationDuration: `${toast.duration}ms` }"></span>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.zv-toast-item     { position: relative; overflow: hidden; align-items: flex-start; }
.zv-toast-icon     { line-height: 1.2; }
.zv-toast-text     { flex: 1; margin: 0; line-height: 1.35; }
.zv-toast-close    { opacity: 0.6; line-height: 1; }
.zv-toast-close:hover { opacity: 1; }

.zv-toast-progress {
    position: absolute;
    inset-block-end: 0;
    inset-inline-start: 0;
    height: 2px;
    width: 100%;
    animation-name: zv-toast-shrink;
    animation-timing-function: linear;
    animation-fill-mode: forwards;
}
@keyframes zv-toast-shrink { from { width: 100%; } to { width: 0%; } }

.zv-toast-enter-active, .zv-toast-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.zv-toast-enter-from, .zv-toast-leave-to       { opacity: 0; transform: translateX(24px); }
.zv-toast-leave-active                          { position: absolute; width: 100%; }
</style>
