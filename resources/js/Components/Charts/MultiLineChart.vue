<!--
══════════════════════════════════════════════════════════════════
  Components/Charts/MultiLineChart.vue

  Shared professional multi-series line/area chart used across the
  Financial Institutions Dashboard: Cash Status' per-facility "Bank
  Movement" chart (Cash In / Cash Out / End Balance) and, later,
  Cash Forecast's Cash Flow / Accumulated Net Cash charts.

  Ported from CashVero's own MultiLineChart.vue (amCharts4) — this
  version uses ApexCharts (see DonutChart3D.vue's header for why).
  Same visual goal: a soft gradient area fill under each line,
  rounded tooltips, and a light grid — a professional look, not a
  flat sparkline.

  @layout    (component, no layout)
  @props     data: [{ date, ...fields }], series: [{ field, name, color }]
══════════════════════════════════════════════════════════════════
-->
<template>
  <div :style="{ height: typeof height === 'number' ? height + 'px' : height }">
    <div v-if="!(data || []).length" class="zv-chart-empty">
      {{ t('financialInstitutions.dashboard.common.noDataForThisSelectionYet') }}
    </div>
    <apexchart v-else type="area" :height="height" :options="chartOptions" :series="series" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'

const apexchart = VueApexCharts
const { t } = useI18n()

const props = defineProps({
  data: { type: Array, default: () => [] }, // [{ date: 'YYYY-MM-DD', ...fields }]
  seriesDef: { type: Array, required: true, default: () => [] }, // [{ field, name, color }]
  height: { type: [Number, String], default: 275 },
})

const categories = computed(() => (props.data || []).map(r => r.date))

const series = computed(() => (props.seriesDef || []).map(s => ({
  name: s.name,
  data: (props.data || []).map(r => Math.round(Number(r[s.field] || 0))),
})))

const colors = computed(() => (props.seriesDef || []).map(s => s.color || '#1490A8'))

const chartOptions = computed(() => ({
  chart: {
    type: 'area',
    fontFamily: 'inherit',
    toolbar: { show: true, tools: { download: false } },
    zoom: { enabled: true },
    animations: { speed: 350 },
  },
  colors: colors.value,
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: 2.5 },
  fill: {
    type: 'gradient',
    gradient: { shadeIntensity: 1, opacityFrom: 0.32, opacityTo: 0.02, stops: [0, 90, 100] },
  },
  markers: { size: 3, strokeWidth: 2, hover: { size: 5 } },
  grid: { borderColor: 'var(--zv-border-md, #e5e7eb)', strokeDashArray: 3 },
  xaxis: {
    categories: categories.value,
    type: 'datetime',
    labels: { style: { colors: 'var(--zv-text-secondary, #4b5563)', fontSize: '11px' } },
    axisBorder: { color: 'var(--zv-border-md, #e5e7eb)' },
  },
  yaxis: {
    labels: {
      style: { colors: 'var(--zv-text-secondary, #4b5563)', fontSize: '11px' },
      formatter: (val) => Number(val).toLocaleString('en-US'),
    },
  },
  legend: {
    position: 'top',
    horizontalAlign: 'right',
    fontSize: '11px',
    labels: { colors: 'var(--zv-text-secondary, #4b5563)' },
  },
  tooltip: {
    x: { format: 'dd MMM yyyy' },
    y: { formatter: (val) => Number(val).toLocaleString('en-US') },
  },
}))
</script>

<style scoped>
.zv-chart-empty {
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: var(--zv-text-muted, #6b7280);
}
</style>
