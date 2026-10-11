<!--
══════════════════════════════════════════════════════════════════
  Accounting/Payroll/EosbProvisionRunIndex.vue

  Listing page for monthly EOSB Provision runs — a separate run from
  Payroll Run itself (see EosbProvisionRunService header).

  @layout    AppLayout
  @route     hr.eosb-provision.index
  @props     runs (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.eosbProvisionRunIndex.eosbProvision')"
      :subtitle="t('accounting.hr.eosbProvisionRunIndex.oneRunPerMonthSeparateFromPayroll')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/>'
    >
      <template #actions>
        <Link :href="route('hr.eosb-provision.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.hr.eosbProvisionRunIndex.newRun') }}
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
        <SelectBox v-model="status" :options="statusOptions" value-key="value" label-key="label" :placeholder="t('accounting.hr.eosbProvisionRunIndex.allStatuses')" @update:modelValue="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="runs.data.length === 0"
        :title="t('accounting.hr.eosbProvisionRunIndex.noEosbProvisionRunsYet')"
        :message="t('accounting.hr.eosbProvisionRunIndex.useTheButtonAboveToStartAMonths')"
        icon-path="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.eosbProvisionRunIndex.period') }}</th>
              <th>{{ t('common.date') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.eosbProvisionRunIndex.employees') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.eosbProvisionRunIndex.totalProvision') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in runs.data" :key="r.id">
              <td class="font-semibold">{{ periodLabel(r) }}</td>
              <td>{{ formatRunDate(r.run_date) }}</td>
              <td class="zv-table-num">{{ r.lines_count ?? '—' }}</td>
              <td class="zv-table-num">{{ fmt(r.provision_total) }}</td>
              <td class="text-center"><StatusBadge :status="r.status" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('hr.eosb-provision.show', r.id)" class="zv-action-btn" :title="t('common.view')">
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

// Same date-only display fix as Payroll Run's own Index page — see
// that file's comment for why this isn't handled on the model itself.
function formatRunDate(d) { return d ? String(d).slice(0, 10) : '—' }

const statusOptions = [
  { value: 'draft', label: t('common.draft') },
  { value: 'posted', label: t('common.posted') },
]

const status = ref(props.filters?.status ?? '')
const hasActiveFilters = computed(() => !!status.value)

function applyFilters() {
  router.get(route('hr.eosb-provision.index'), { status: status.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  status.value = ''
  applyFilters()
}
</script>
