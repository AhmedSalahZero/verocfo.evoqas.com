<!--
══════════════════════════════════════════════════════════════════
  Accounting/Payroll/PayrollRunIndex.vue

  Listing page for monthly Payroll Runs.

  @layout    AppLayout
  @route     hr.payroll.index
  @props     runs (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.payrollRunIndex.payrollRuns')"
      :subtitle="t('accounting.hr.payrollRunIndex.oneRunPerMonthAccrualThenSettlement')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/>'
    >
      <template #actions>
        <Link :href="route('hr.payroll.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.hr.payrollRunIndex.newRun') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="runs.total"
        results-label="run"
        @clear="clearFilters"
      >
        <SelectBox v-model="status" :options="statusOptions" value-key="value" label-key="label" :placeholder="t('accounting.hr.payrollRunIndex.allStatuses')" @update:modelValue="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="runs.data.length === 0"
        :title="t('accounting.hr.payrollRunIndex.noPayrollRunsYet')"
        :message="t('accounting.hr.payrollRunIndex.useTheButtonAboveToStartAMonths')"
        icon-path="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.payrollRunIndex.period') }}</th>
              <th>{{ t('common.date') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.payrollRunIndex.employees') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.payrollRunIndex.totalNetPay') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in runs.data" :key="r.id">
              <td class="font-semibold">{{ periodLabel(r) }}</td>
              <td>{{ formatRunDate(r.run_date) }}</td>
              <td class="zv-table-num">{{ r.lines_count ?? '—' }}</td>
              <td class="zv-table-num">{{ fmt(r.net_pay_total) }}</td>
              <td class="text-center"><StatusBadge :status="r.status" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('hr.payroll.show', r.id)" class="zv-action-btn" :title="t('common.view')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                  </Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="runs" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  runs:    { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

const MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
function periodLabel(r) { return `${MONTHS[r.period_month]} ${r.period_year}` }
function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

// run_date comes from the server as a full ISO datetime string
// (e.g. "2026-01-31T00:00:00.000000Z") — it's a date-only value with
// no meaningful time component, so we only ever show the YYYY-MM-DD
// part. See project-wide SerializesDateOnly note: not applied on the
// PayrollRun model itself because it also carries posted_at, a real
// timestamp — trimming there is done here instead, at the display layer.
function formatRunDate(d) { return d ? String(d).slice(0, 10) : '—' }

const statusOptions = [
  { value: 'draft', label: t('common.draft') },
  { value: 'posted', label: t('common.posted') },
]

const status = ref(props.filters?.status ?? '')
const hasActiveFilters = computed(() => !!status.value)

function applyFilters() {
  router.get(route('hr.payroll.index'), { status: status.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  status.value = ''
  applyFilters()
}
</script>
