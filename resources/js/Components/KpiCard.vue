<!--
══════════════════════════════════════════════════════════════════
  Components/KpiCard.vue

  Shared KPI/stat card — the small summary cards at the top of list
  pages (found in 7 files today, e.g. Clean Overdraft's "Facilities /
  Total Limit / Total Drawn / Avg. Utilization" row). This is exactly
  the shape most Analytics report screens will need repeatedly, so
  it's one of the highest-value pieces to have ready before that
  stream starts.

  Handles the plain case (a label + one number) via props. For the
  two more elaborate cases already seen in the app — a multi-currency
  breakdown (several currency/amount lines in one card, because
  amounts in different currencies must never be summed together) and
  the utilization ring — the value area is a slot, so those stay
  exactly as flexible as they are today instead of being forced into
  a shape that doesn't fit.

  Sept 2026 (Accounting design pass): added the optional `icon` /
  `tone` pair. With an icon the card switches to the horizontal
  layout — tinted icon box, then label over value — borrowed from
  cashvero's KPI card, which is what makes a row of four of them
  scannable instead of four identical grey blocks. Without an icon
  the card renders exactly as it always has, so none of the pages
  already using it change.

  @layout    — (plain component, no layout)
  @route     — n/a
  @props     label: string — required, e.g. 'Total Limit'
              value: string|number — the headline number; ignored if
                     the default slot is used instead
              icon:  string — optional raw SVG <path> content (the
                     path element(s) only, same convention as
                     PageHeader's `icon`). Switches the card to the
                     icon layout.
              tone:  string — icon box tint: blue (default) | navy |
                     gold | green | amber | danger. Ignored with no
                     icon. Pick by meaning, not variety: green for a
                     settled/received figure, amber for something
                     outstanding, danger for overdue.
  @slots     default — overrides the value area entirely (for the
                       multi-currency stack or a utilization ring)
              sub — the smaller line under the value (e.g.
                    '3 active', 'Unweighted, per facility')
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-kpi-card" :class="{ 'zv-kpi-card-icon': icon }">
    <div v-if="icon" class="zv-kpi-icon" :class="`zv-kpi-icon-${tone}`">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" v-html="icon"></svg>
    </div>

    <div class="zv-kpi-body">
      <div class="zv-kpi-label">{{ label }}</div>

      <div v-if="$slots.default"><slot /></div>
      <div v-else class="zv-kpi-value">{{ value }}</div>

      <div v-if="$slots.sub" class="zv-kpi-sub"><slot name="sub" /></div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], default: null },
  icon: { type: String, default: null },
  tone: { type: String, default: 'blue' },
})
</script>
