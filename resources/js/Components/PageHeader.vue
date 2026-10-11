<!--
══════════════════════════════════════════════════════════════════
  Components/PageHeader.vue

  Shared page header — the breadcrumb/eyebrow + title + subtitle +
  action-button block at the top of nearly every list and form page.
  This is the single most duplicated pattern in the whole app
  (found in 48 files) — same structure, hand-copied every time.

  Two ways to identify the page's place in the app, since both are
  used today:
    - `eyebrow` — a single small label above the title (most pages:
      "Settings", "Financial Institutions")
    - `breadcrumbs` — an array of {label, href} for pages that need
      a multi-level trail (e.g. Clean Overdraft's bank-scoped view:
      "Banks / NBE / Clean Overdraft"). Only one of the two is
      normally used; if both are given, breadcrumbs takes priority.

  The action area (usually a "+ New X" button, but sometimes
  something more complex like Clean Overdraft's bank-picker dropdown)
  is a slot, not a fixed prop — so pages that need something more
  than a plain link aren't forced to fight the component's shape.

  Fix (July 2026): icon box and the header's primary "New X" button
  now match Purchase Orders' original header design (brand navy
  button, gold-tinted icon box) instead of the more generic look this
  component used before — see the style block below for details.
  This was a pure CSS change; no prop or slot shape changed.

  @layout    — (plain component, no layout)
  @route     — n/a
  @props     eyebrow:     string — small label above the title
              breadcrumbs: Array  — [{ label, href? }] — href omitted
                           on the last/current item
              title:       string — required, the page's H1
              subtitle:    string — optional, shown under the title
              icon:        string — optional raw SVG <path> content;
                           shows a rounded icon box (July 2026 —
                           added to match the Banks page style,
                           opt-in so existing pages are unaffected)
  @slots     actions — the button(s)/controls on the right side
              subtitle — for a subtitle with inline elements (e.g. a
                         bank-name chip) instead of plain text
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page-header">
    <div class="zv-page-header-main">
      <div v-if="icon" class="zv-page-header-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5" v-html="icon"></svg>
      </div>
      <div>
        <div v-if="breadcrumbs && breadcrumbs.length" class="zv-breadcrumb">
          <template v-for="(crumb, idx) in breadcrumbs" :key="idx">
            <Link v-if="crumb.href" :href="crumb.href" class="zv-text-blue">{{ crumb.label }}</Link>
            <span v-else>{{ crumb.label }}</span>
            <span v-if="idx < breadcrumbs.length - 1" class="zv-breadcrumb-sep">/</span>
          </template>
        </div>
        <div v-else-if="eyebrow" class="zv-section-eyebrow">{{ eyebrow }}</div>

        <h1 class="zv-page-title">{{ title }}</h1>

        <p v-if="$slots.subtitle" class="zv-page-subtitle"><slot name="subtitle" /></p>
        <p v-else-if="subtitle" class="zv-page-subtitle">{{ subtitle }}</p>
      </div>
    </div>

    <div v-if="$slots.actions" class="zv-page-header-actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
  eyebrow: { type: String, default: null },
  breadcrumbs: { type: Array, default: null },
  title: { type: String, required: true },
  subtitle: { type: String, default: null },
  // Optional — raw SVG path content (the <path> element(s) only).
  // When given, shows a rounded icon box to the left of the title,
  // matching the Banks page style. Omit for the plain text-only
  // header most pages already use.
  icon: { type: String, default: null },
})
</script>

<style>
.zv-page-header-main {
  display: flex;
  align-items: flex-start;
  gap: 0.875rem;
}
.zv-page-header-icon {
  flex-shrink: 0;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: var(--zv-radius-md);
  background-color: var(--zv-brand-gold-bg);
  color: var(--zv-brand-gold-text);
  display: flex;
  align-items: center;
  justify-content: center;
}
[data-theme="dark"] .zv-page-header-icon {
  background-color: rgba(255, 191, 0, 0.15);
  color: #FFBF00;
}

/* Fix (July 2026): the header's own "New X" action originally used
   the generic --zv-blue (via .zv-btn-primary), while Purchase
   Orders' one-off header design used the brand navy button instead
   — the two looked inconsistent side by side. Scoped to
   .zv-page-header-actions specifically, so this only re-colors the
   primary button living in a page header — every other
   .zv-btn-primary in the app (forms, modals, table row actions)
   is untouched. */
.zv-page-header-actions .zv-btn-primary {
  background-color: var(--zv-brand-navy);
  border-color: var(--zv-brand-navy);
}
.zv-page-header-actions .zv-btn-primary:hover {
  background-color: var(--zv-brand-navy-hover);
  border-color: var(--zv-brand-navy-hover);
}
</style>
