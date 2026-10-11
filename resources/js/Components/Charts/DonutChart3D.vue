<!--
══════════════════════════════════════════════════════════════════
  Components/Charts/DonutChart3D.vue

  Shared glossy 3D-style donut chart used across the Financial
  Institutions Dashboard (Cash Status' "Available Room by Bank",
  LG & LC Status' "Outstanding per Type" / "Outstanding per
  Financial Institution").

  Ported from CashVero's own DonutChart3D.vue (amCharts4 with a
  RadialGradientModifier for the glossy lit-from-above look) — this
  version uses ApexCharts instead (no charting library existed in
  VeroCFO at all; Mahmoud approved "any library, as long as it has
  the same professional design and 3D donut" rather than matching
  amCharts4 specifically). The 3D look here comes from a per-slice
  radial gradient (light center → darker edge) plus a soft drop
  shadow on the whole donut — the same visual idea as CashVero's
  RadialGradientModifier, achieved with ApexCharts' own gradient +
  dropShadow options instead.

  @layout    (component, no layout)
  @props     data: [{ category, value }], colors, height, totalLabel
══════════════════════════════════════════════════════════════════
-->
<template>
  <div :style="{ height: typeof height === 'number' ? height + 'px' : height }">
    <div v-if="!hasData" class="zv-chart-empty">
      {{ t('financialInstitutions.dashboard.common.noDataForThisSelection') }}
    </div>
    <apexchart v-else type="donut" :height="height" :options="chartOptions" :series="series" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'

const apexchart = VueApexCharts
const { t } = useI18n()

const props = defineProps({
  data: { type: Array, default: () => [] }, // [{ category, value }]
  colors: { type: Array, default: () => ['#1490A8', '#16a34a', '#b45309', '#0F2554', '#dc2626', '#7c3aed'] },
  height: { type: [Number, String], default: 260 },
  showTotal: { type: Boolean, default: true },
  totalLabel: { type: String, default: '' },
  valuePrefix: { type: String, default: '' },
})

const rows = computed(() => (props.data || []).filter(r => Number(r.value) > 0))
const hasData = computed(() => rows.value.length > 0)

const series = computed(() => rows.value.map(r => Math.round(Number(r.value))))

const total = computed(() => rows.value.reduce((s, r) => s + Number(r.value || 0), 0))

const chartOptions = computed(() => ({
  // ── The "3D" glossy look — per-slice radial gradient (bright
  // center fading to a darker edge) + a soft drop shadow under the
  // whole donut. Same visual goal as CashVero's amCharts4
  // RadialGradientModifier, done with ApexCharts' own fill/gradient
  // and chart.dropShadow options.
  chart: {
    type: 'donut',
    fontFamily: 'inherit',
    animations: { speed: 400 },
    dropShadow: { enabled: true, top: 3, left: 0, blur: 6, opacity: 0.22 },
  },
  labels: rows.value.map(r => r.category),
  colors: props.colors,
  stroke: { width: 2, colors: ['var(--zv-bg-card, #fff)'] },
  dataLabels: { enabled: false },
  legend: { show: false },
  tooltip: {
    y: {
      formatter: (val) => `${props.valuePrefix}${Number(val).toLocaleString('en-US')}`,
    },
  },
  fill: {
    type: 'gradient',
    gradient: {
      shade: 'light',
      type: 'radial',
      shadeIntensity: 0.55,
      inverseColors: true,
      opacityFrom: 1,
      opacityTo: 0.82,
      stops: [0, 90, 100],
    },
  },
  states: {
    hover: { filter: { type: 'lighten', value: 0.06 } },
  },
  plotOptions: {
    pie: {
      donut: {
        size: '58%',
        labels: {
          show: props.showTotal,
          total: {
            show: true,
            label: props.totalLabel || t('financialInstitutions.dashboard.common.total'),
            fontSize: '11px',
            color: 'var(--zv-text-muted, #6b7280)',
            formatter: () => `${props.valuePrefix}${Math.round(total.value).toLocaleString('en-US')}`,
          },
          value: {
            fontSize: '16px',
            fontWeight: 700,
            color: 'var(--zv-navy, #0F2554)',
            formatter: (val) => `${props.valuePrefix}${Number(val).toLocaleString('en-US')}`,
          },
        },
      },
    },
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
