<!--
══════════════════════════════════════════════════════════════════
  Components/EmptyState.vue

  Shared "no records yet" block — found in 22 files, each with its
  own copy of the icon + title + message markup.

  A default icon is provided (a generic "empty box" outline) so most
  pages can skip passing one entirely; pages that want a more
  specific icon (matching their own entity) can still pass their own
  SVG path via the `iconPath` prop without needing the whole markup
  block duplicated.

  @layout    — (plain component, no layout)
  @route     — n/a
  @props     title:    string — required, e.g. 'No partners found.'
              message:  string — optional, the smaller line under
                        the title
              iconPath: string — optional, a single SVG <path> `d`
                        attribute to swap in a different icon
  @slots     action — an optional button below the message (e.g.
                       "Clear filters", "Add one first")
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-empty">
    <svg class="w-10 h-10 zv-empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="iconPath || DEFAULT_ICON_PATH"/>
    </svg>
    <div class="zv-empty-title">{{ title }}</div>
    <div v-if="message" class="zv-empty-sub">{{ message }}</div>
    <div v-if="$slots.action" class="zv-empty-action">
      <slot name="action" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  message: { type: String, default: null },
  iconPath: { type: String, default: null },
})

// Generic "empty box" outline — a safe default for any entity type
// when a page doesn't want to supply its own icon.
const DEFAULT_ICON_PATH = 'M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0l-2 6a2 2 0 01-2 2H8a2 2 0 01-2-2l-2-6m16 0H4'
</script>
