<!--
══════════════════════════════════════════════════════════════════
  Components/OptionTiles.vue

  Shared clickable-option selector — replaces the hand-copied
  .zv-tile / .zv-tile-group markup found duplicated (with the same
  visual problem everywhere: oversized blocks, a bright non-brand
  cyan active fill) across ~10 files. Same idea as StatusBadge.vue —
  one place to fix the look, every page that adopts it gets the
  ZAVERO Navy/Gold/Teal treatment automatically instead of its own
  copy of the old style.

  Three variants cover every shape found in the app:
    'pill' — compact segmented control, short 1-2 word choices,
             side by side (e.g. Bank Account / Safe). Narrow width,
             not a big block.
    'card' — label + description, stacked vertically, one card per
             option in a grid (e.g. Operational Lease / Capital
             Lease, Settlement / Down Payment).
    'icon' — icon on top, short label below, grid of 3-4 (e.g.
             Money Type: Bank Deposit / Incoming Transfer / Cash in
             Safe / Cheque).

  Active state is always Navy (#0F2554) in light mode, Teal
  (#1490A8) in dark mode — the same brand pair used everywhere else
  in the app, never the old bright cyan.

  @layout    — (plain component, no layout)
  @route     — n/a
  @props     modelValue:  string|number — the selected option's value (v-model)
              options:     Array<{ value, label, description?, icon?, disabled?, accent? }>
                           — description is only shown in 'card' variant;
                           icon (raw SVG <path> content) is only shown in
                           'icon' variant; accent (see below) overrides the
                           default color for just that one option.
              variant:     'pill' | 'card' | 'icon' | 'chip' — default 'pill'.
                           'chip' is a compact, centered, label-only tile
                           (no description) — e.g. Bank Account Type
                           (Current/Time Deposit/Escrow), New/Existing.
              accent:      'navy' | 'gold' | 'teal' | 'green' | 'amber'
                           — optional, overrides the active-state color for
                           EVERY option in the group at once (e.g. Factoring's
                           Recourse Type uses 'gold' to visually separate it
                           from the primary Agreement Type choice above it).
                           Default 'navy' (Navy in light mode, Teal in dark
                           — the standard brand pair used everywhere else).
                           Per-option `accent` (in an options entry) wins
                           over this for that one option — e.g. Bank Account
                           Type's three options are each a genuinely
                           different account type, so each carries its own
                           fixed accent (teal/green/amber) that never changes
                           with the person's active selection or color theme.
              columns:     number — optional, grid columns for 'card'/'icon'/
                           'chip' variants (default: options.length, capped
                           at 4)
              disabled:    boolean — disables every option at once (e.g. a
                           read-only posted record), on top of any
                           per-option `disabled`
  @emits     update:modelValue — the newly picked option's value
══════════════════════════════════════════════════════════════════
-->
<template>
  <div
    class="ot-group"
    :class="[`ot-group-${variant}`]"
    :style="(variant !== 'pill') ? { gridTemplateColumns: `repeat(${gridColumns}, 1fr)` } : null"
  >
    <button
      v-for="opt in options" :key="opt.value"
      type="button"
      class="ot-item"
      :class="[`ot-item-${variant}`, { 'ot-item-active': modelValue === opt.value }]"
      :data-accent="opt.accent || accent || null"
      :disabled="disabled || opt.disabled"
      @click="$emit('update:modelValue', opt.value)"
    >
      <template v-if="variant === 'icon'">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="ot-icon" v-html="opt.icon"></svg>
        <span class="ot-icon-label">{{ opt.label }}</span>
      </template>
      <template v-else-if="variant === 'card'">
        <span class="ot-card-label">{{ opt.label }}</span>
        <span v-if="opt.description" class="ot-card-desc">{{ opt.description }}</span>
      </template>
      <template v-else>
        {{ opt.label }}
      </template>
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: { type: [String, Number], default: null },
  options:    { type: Array, required: true },
  variant:    { type: String, default: 'pill' }, // 'pill' | 'card' | 'icon' | 'chip'
  accent:     { type: String, default: null },   // 'navy' | 'gold' | 'teal' | 'green' | 'amber'
  columns:    { type: Number, default: null },
  disabled:   { type: Boolean, default: false },
})

defineEmits(['update:modelValue'])

const gridColumns = computed(() => props.columns ?? Math.min(props.options.length, 4))
</script>

<style>
/* ── Shared container ─────────────────────────────────────────── */
.ot-group-pill {
  display: inline-flex;
  background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border);
  border-radius: 10px;
  padding: 3px;
  gap: 3px;
}
.ot-group-card,
.ot-group-icon,
.ot-group-chip {
  display: grid;
  gap: 10px;
}
@media (max-width: 600px) {
  .ot-group-card, .ot-group-icon, .ot-group-chip { grid-template-columns: 1fr !important; }
}

/* ── Pill variant — compact segmented control ─────────────────── */
.ot-item-pill {
  padding: 8px 22px;
  border: none;
  border-radius: 7px;
  background: transparent;
  color: var(--zv-text-muted);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: background .15s, color .15s;
}
.ot-item-pill:hover:not(:disabled):not(.ot-item-active) { color: var(--zv-text-primary); background: var(--zv-bg-input); }
.ot-item-pill.ot-item-active { background: #0F2554; color: #fff; }
[data-theme="dark"] .ot-item-pill.ot-item-active { background: #1490A8; }

/* ── Card variant — label + description ───────────────────────── */
.ot-item-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 14px 16px;
  border: 2px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  background: var(--zv-bg-input);
  text-align: start;
  cursor: pointer;
  transition: border-color .15s, background .15s;
}
.ot-item-card:hover:not(:disabled):not(.ot-item-active) { border-color: #1490A8; }
.ot-item-card.ot-item-active { border-color: #0F2554; background: rgba(15, 37, 84, 0.06); }
[data-theme="dark"] .ot-item-card.ot-item-active { border-color: #1490A8; background: rgba(20, 144, 168, 0.12); }
.ot-card-label { font-size: 13px; font-weight: 700; color: var(--zv-text-primary); }
.ot-card-desc  { font-size: 12px; color: var(--zv-text-muted); line-height: 1.4; }

/* ── Icon variant — icon on top, short label below ────────────── */
.ot-item-icon {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 14px 10px;
  border: 2px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  background: var(--zv-bg-input);
  color: var(--zv-text-muted);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  transition: border-color .15s, background .15s, color .15s;
}
.ot-item-icon:hover:not(:disabled):not(.ot-item-active) { border-color: #1490A8; }
.ot-item-icon.ot-item-active { border-color: #0F2554; background: rgba(15, 37, 84, 0.06); color: #0F2554; }
[data-theme="dark"] .ot-item-icon.ot-item-active { border-color: #1490A8; background: rgba(20, 144, 168, 0.12); color: #1490A8; }
.ot-icon { width: 20px; height: 20px; }

/* ── Shared disabled state ────────────────────────────────────── */
.ot-item:disabled { opacity: 0.5; cursor: not-allowed; }

/* ── Chip variant — compact, centered, label-only ─────────────────
   e.g. Bank Account Type (Current/Time Deposit/Escrow), New/Existing.
   Same tile shape as 'card' minus the description line. */
.ot-item-chip {
  text-align: center;
  padding: 10px;
  border: 2px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  background: var(--zv-bg-input);
  color: var(--zv-text-primary);
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: border-color .15s, background .15s, color .15s;
}
.ot-item-chip:hover:not(:disabled):not(.ot-item-active) { border-color: #1490A8; }
.ot-item-chip.ot-item-active { background: #0F2554; border-color: #0F2554; color: #fff; }
[data-theme="dark"] .ot-item-chip.ot-item-active { background: #1490A8; border-color: #1490A8; }

/* ── Named accent overrides ───────────────────────────────────────
   Fixed colors, deliberately the SAME in light and dark mode — these
   are meaningful, semantic distinctions (a real account type, or a
   choice pulled out from the primary Navy one for visual separation
   on the same form), not a theme-driven brand color, so they don't
   swap on dark mode the way the default Navy→Teal pair does.
   Applied via a plain `data-accent` attribute (set from either the
   group-level `accent` prop or a per-option `accent` field) — no
   CSS custom-property inheritance tricks, so there's no cascade
   ambiguity between a group-level accent and the dark-mode default. */
.ot-item-card.ot-item-active[data-accent="gold"] {
  border-color: #FFBF00;
  background: rgba(255, 191, 0, 0.08);
}
.ot-item-chip.ot-item-active[data-accent="teal"]  { background: #1490A8; border-color: #1490A8; }
.ot-item-chip.ot-item-active[data-accent="green"] { background: #10b981; border-color: #10b981; }
.ot-item-chip.ot-item-active[data-accent="amber"] { background: #f59e0b; border-color: #f59e0b; }
</style>
