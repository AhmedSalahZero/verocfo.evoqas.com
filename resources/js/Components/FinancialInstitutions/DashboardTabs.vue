<!--
══════════════════════════════════════════════════════════════════
  Components/FinancialInstitutions/DashboardTabs.vue

  Shared top-level navigation between the Financial Institutions
  Dashboard's real Inertia pages (Cash Status / LG & LC Status /
  Cash Forecast) — mirrors CashVero's own DashboardTabs.vue exactly:
  each tab is a real page with its own route and its own controller
  logic, not a client-side content switch, so this uses <Link> (a
  full Inertia visit).

  Cash Forecast is included as a tab but currently opens a "Coming
  Soon" placeholder page (see Dashboard/Forecast.vue) — per Mahmoud's
  build-order decision (Sept 2026): Cash Status + LG & LC Status
  first, Forecast later, since Forecast needs new engines (cash flow
  projection, invoice aging, cheque aging) that don't exist yet.

  @layout    (component, no layout)
  @props     active: 'cash' | 'lglc' | 'forecast'
              urls: { cash, lglc, forecast }
══════════════════════════════════════════════════════════════════
-->
<script setup>
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
  active: { type: String, required: true },
  urls: { type: Object, required: true },
})

const tabs = [
  { key: 'cash', label: 'financialInstitutions.dashboard.tabs.cashStatus', icon: '💵' },
  { key: 'lglc', label: 'financialInstitutions.dashboard.tabs.lgLcStatus', icon: '📜' },
  { key: 'forecast', label: 'financialInstitutions.dashboard.tabs.cashForecast', icon: '🔮' },
]
</script>

<template>
  <div class="fid-tabs flex items-center gap-2 flex-wrap mb-6">
    <Link
      v-for="tab in tabs"
      :key="tab.key"
      :href="urls[tab.key]"
      class="fid-tab"
      :class="{ 'fid-tab-active': active === tab.key }"
    >
      <span>{{ tab.icon }}</span>
      <span>{{ t(tab.label) }}</span>
    </Link>
  </div>
</template>

<style>
.fid-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  color: var(--zv-text-secondary, #4b5563);
  background: var(--zv-bg-surface-alt, #f3f4f6);
  border: 1px solid transparent;
  transition: all 0.15s;
}
.fid-tab:hover { background: var(--zv-bg-surface-hover, #e5e7eb); }
.fid-tab-active {
  color: #fff;
  background: var(--zv-navy, #0F2554);
}
.fid-tab-active:hover { background: var(--zv-navy, #0F2554); }
</style>
