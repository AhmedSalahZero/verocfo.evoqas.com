<!--
══════════════════════════════════════════════════════════════════
  Components/Charts/AgingDivergingBarChart.vue

  Shared diverging horizontal bar chart used by Cash Forecast's
  Invoice Aging and Cheque Aging sections — Past Due extends left
  (negative), Current Due and Coming Due extend right (positive),
  Current Due in its own distinct color so it never reads as "just
  another coming-due bucket."

  Ported from CashVero's own AgingDivergingBarChart.vue (amCharts4)
  — this version uses ApexCharts (see DonutChart3D.vue's header for
  why). Each row already carries its own resolved hex color
  (colorVar) from AgingBucketCalculator::formatForChart() — no
  runtime CSS-variable resolution needed here, ApexCharts takes a
  per-point color list directly.

  @layout    (component, no layout)
  @props     data: [{ category, value, colorVar }]
══════════════════════════════════════════════════════════════════
-->
<template>
  <div :style="{ height: typeof height === 'number' ? height + 'px' : height }">
    <div v-if="!(data || []).length" class="zv-chart-empty">
      {{ t('financialInstitutions.dashboard.common.noDataForThisSelection') }}
    </div>
    <apexchart v-else type="bar" :height="height" :options="chartOptions" :series="series" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'

const apexchart = VueApexCharts
const { t } = useI18n()

const props = defineProps({
  data: { type: Array, default: () => [] }, // [{ category, value, colorVar }]
  height: { type: [Number, String], default: 320 },
})

const series = computed(() => [{ name: t('financialInstitutions.dashboard.forecast.amount'), data: (props.data || []).map(r => Math.round(Number(r.value))) }])
const categories = computed(() => (props.data || []).map(r => r.category))
const colors = computed(() => (props.data || []).map(r => r.colorVar || '#1490A8'))

const chartOptions = computed(() => ({
  chart: { type: 'bar', fontFamily: 'inherit', toolbar: { show: false }, animations: { speed: 350 } },
  plotOptions: {
    bar: {
      horizontal: true,
      borderRadius: 3,
      distributed: true,
      barHeight: '65%',
    },
  },
  colors: colors.value,
  dataLabels: { enabled: false },
  legend: { show: false },
  grid: { borderColor: 'var(--zv-border-md, #e5e7eb)', strokeDashArray: 3 },
  xaxis: {
    categories: categories.value,
    labels: {
      style: { colors: 'var(--zv-text-secondary, #4b5563)', fontSize: '11px' },
      formatter: (val) => Number(val).toLocaleString('en-US'),
    },
  },
  yaxis: {
    labels: { style: { colors: 'var(--zv-text-secondary, #4b5563)', fontSize: '11px' } },
  },
  tooltip: {
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
