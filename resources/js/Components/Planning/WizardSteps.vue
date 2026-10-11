<!--
══════════════════════════════════════════════════════════════════
  Components/Planning/WizardSteps.vue

  The step-progress bar shown at the top of every Plan wizard page —
  numbered circles connected by chevrons, a checkmark for completed
  steps, the current step highlighted. Structurally the same pattern
  CFO Tools' own Financial Study wizard uses (every one of its 8 step
  pages carries this exact bar), rebuilt here in VeroCFO's real zv-*
  design tokens (light theme, Navy/Gold/Teal — Building Bible Section
  24) rather than CFO Tools' dark "mp-*" theme, per your instruction.

  Each step is a real link (not just a label) so the user can jump
  directly to any step already reached — same as clicking back
  through CFO Tools' bar. A step ahead of the furthest one completed
  is shown but not clickable, matching CFO Tools' own behavior of not
  letting you skip ahead into a step with nothing to show yet.

  @layout    — (plain component, no layout)
  @props     steps: [{ key, label, href }] — in order
              currentKey: String — which step is active
              completedKeys: String[] — which steps are done
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="wiz-steps">
        <template v-for="(step, i) in steps" :key="step.key">
            <Link
                v-if="isReachable(step.key)"
                :href="step.href"
                class="wiz-step"
                :class="{ 'wiz-step-active': step.key === currentKey, 'wiz-step-done': isCompleted(step.key) }"
            >
                <span class="wiz-step-circle">
                    <svg v-if="isCompleted(step.key)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="w-3 h-3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span v-else>{{ i + 1 }}</span>
                </span>
                <span class="wiz-step-label">{{ step.label }}</span>
            </Link>
            <span v-else class="wiz-step wiz-step-disabled">
                <span class="wiz-step-circle">{{ i + 1 }}</span>
                <span class="wiz-step-label">{{ step.label }}</span>
            </span>

            <svg v-if="i < steps.length - 1" class="wiz-step-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
        </template>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    steps: { type: Array, required: true },
    currentKey: { type: String, required: true },
    completedKeys: { type: Array, default: () => [] },
})

function isCompleted(key) {
    return props.completedKeys.includes(key)
}

// Reachable = already completed, is the current step, or is the very
// next step after the furthest one completed — same "can't skip
// ahead" rule CFO Tools' own wizard bar follows.
function isReachable(key) {
    if (key === props.currentKey || isCompleted(key)) return true
    const idx = props.steps.findIndex(s => s.key === key)
    const prevStep = props.steps[idx - 1]
    return prevStep ? isCompleted(prevStep.key) : idx === 0
}
</script>

<style scoped>
.wiz-steps {
    display: flex;
    align-items: center;
    gap: 2px;
    overflow-x: auto;
    padding-bottom: 2px;
    margin-bottom: 4px;
}
.wiz-step {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--zv-text-muted);
    text-decoration: none;
    white-space: nowrap;
    flex-shrink: 0;
    transition: var(--zv-transition);
}
.wiz-step:not(.wiz-step-disabled):hover {
    background: var(--zv-bg-hover);
    color: var(--zv-text-primary);
}
.wiz-step-circle {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    flex-shrink: 0;
    background: var(--zv-bg-surface-alt);
    color: var(--zv-text-muted);
    border: 1px solid var(--zv-border);
}
.wiz-step-done .wiz-step-circle {
    background: var(--zv-green);
    border-color: var(--zv-green);
    color: #fff;
}
.wiz-step-done .wiz-step-label { color: var(--zv-green-text); }
.wiz-step-active {
    background: var(--zv-brand-navy);
    color: #fff;
}
.wiz-step-active .wiz-step-circle {
    background: rgba(255, 191, 0, 0.9);
    border-color: rgba(255, 191, 0, 0.9);
    color: var(--zv-brand-navy);
}
.wiz-step-active .wiz-step-label { color: #fff; }
.wiz-step-disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.wiz-step-chevron {
    width: 14px;
    height: 14px;
    color: var(--zv-border-md);
    flex-shrink: 0;
    margin: 0 1px;
}
</style>
