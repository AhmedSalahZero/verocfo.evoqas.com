<!--
══════════════════════════════════════════════════════════════════
  Components/FilterBar.vue

  Shared filter bar wrapper — found in 13 files. The actual filter
  controls (search box, dropdowns) are different on every page by
  necessity — a Partner list filters by type, a Facility list
  filters by bank — so those stay as a slot, not a fixed prop shape.
  What this component standardizes is the wrapper, the "Clear
  filters" button (same behavior everywhere: only shown when a
  filter is active), and the results-count text on the right —
  three small things that were each implemented slightly differently
  per page for no real reason.

  @layout    — (plain component, no layout)
  @route     — n/a
  @props     hasActiveFilters: boolean — controls whether "Clear
                                filters" is shown
              resultsCount:     number  — e.g. facilities.total
              resultsLabel:     string  — singular label, e.g.
                                'facility' — pluralized automatically
                                as '{n} facility' / '{n} facilities'
                                is NOT attempted here (English plurals
                                are irregular); pass singular/plural
                                explicitly via resultsLabelPlural if
                                it differs from resultsLabel + 's'
              resultsLabelPlural: string — optional override when
                                  plural isn't just label + 's'
  @emits     clear — user clicked "Clear filters"
  @slots     default — the filter controls themselves
              count — optional full override of the results text
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-filter-bar">
    <slot />

    <button
      v-if="hasActiveFilters"
      type="button"
      class="zv-btn zv-btn-ghost zv-btn-sm"
      @click="$emit('clear')"
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
      </svg>
      {{ t('components.filterBar.clearFilters') }}
    </button>

    <div v-if="$slots.count" class="zv-filter-count">
      <slot name="count" />
    </div>
    <div v-else-if="resultsCount !== null" class="zv-filter-count">
      {{ resultsCount }} {{ resultsCount === 1 ? resultsLabel : (resultsLabelPlural || resultsLabel + 's') }}
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
  hasActiveFilters: { type: Boolean, default: false },
  resultsCount: { type: Number, default: null },
  resultsLabel: { type: String, default: 'item' },
  resultsLabelPlural: { type: String, default: null },
})

defineEmits(['clear'])
</script>

<style>
/* Full-width results count on narrow screens — previously
   duplicated (as .cod-filter-count, .xyz-filter-count, etc.) with
   slightly different breakpoints per page. One rule, here, now. */
@media (max-width: 640px) {
  .zv-filter-count { margin-inline-start: 0; width: 100%; }
}
</style>