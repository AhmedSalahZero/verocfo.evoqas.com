<!--
══════════════════════════════════════════════════════════════════
  Accounting/ExpenseClaims/Index.vue

  The claim register. Rows link through to Show, which is where the
  lifecycle actions and the journal entries live — a claim has enough
  going on (approval trail, two postings, a payment) that squeezing
  its actions into a table row would hide most of them.

  The one figure surfaced here beyond the list is what the company
  currently owes its staff: the total of approved-but-unpaid claims.
  That is the number someone running payroll or treasury actually
  needs, and it is invisible if you only look at individual rows.

  @layout    AppLayout
  @route     hr.expense-claims.index
  @props     claims (paginator), employees, statuses, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.expenseClaimIndex.expenseClaims')"
      :subtitle="t('accounting.hr.expenseClaimIndex.moneyStaffSpentOnTheCompanysBehalf')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
    >
      <template #actions>
        <Link :href="route('hr.expense-claims.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.hr.expenseClaimIndex.newClaim') }}
        </Link>
      </template>
    </PageHeader>

    <div v-if="outstandingTotal !== '0.00'" class="ec-outstanding">
      <span class="ec-outstanding-label">{{ t('accounting.hr.expenseClaimIndex.outstanding') }}</span>
      <span class="ec-outstanding-value">{{ outstandingTotal }}</span>
    </div>

    <FilterBar
      :has-active-filters="hasActiveFilters"
      :results-count="claims.total"
      :results-label="t('accounting.hr.expenseClaimIndex.claim')"
      :results-label-plural="t('accounting.hr.expenseClaimIndex.claims')"
      @clear="clearFilters"
    >
      <input
        v-model="filterForm.search" type="text" class="zv-input zv-filter-search"
        :placeholder="t('accounting.hr.expenseClaimIndex.searchPlaceholder')"
        @keyup.enter="applyFilters"
      />
      <SelectBox
        v-model="filterForm.status"
        :options="statusOptions"
        :placeholder="t('accounting.hr.expenseClaimIndex.allStatuses')"
        @change="applyFilters"
      />
      <SelectBox
        v-model="filterForm.partner_id"
        :options="employees"
        value-key="id"
        label-key="name_en"
        :placeholder="t('accounting.hr.expenseClaimIndex.allEmployees')"
        @change="applyFilters"
      />
    </FilterBar>

    <div class="zv-card">
      <EmptyState
        v-if="claims.data.length === 0"
        :title="t('accounting.hr.expenseClaimIndex.noClaimsYet')"
        :message="t('accounting.hr.expenseClaimIndex.useTheButtonAboveToAddOne')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.expenseClaimIndex.claim') }}</th>
              <th>{{ t('accounting.hr.expenseClaimIndex.employee') }}</th>
              <th>{{ t('accounting.hr.expenseClaimIndex.claimDate') }}</th>
              <th class="text-right">{{ t('accounting.hr.expenseClaimIndex.amount') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in claims.data" :key="c.id" class="ec-row" @click="open(c)">
              <td>
                <div class="font-mono text-sm">{{ c.claim_code }}</div>
                <div v-if="c.title" class="text-xs opacity-70">{{ c.title }}</div>
              </td>
              <td class="font-semibold">{{ c.partner?.name_en }}</td>
              <td class="text-sm">{{ c.claim_date }}</td>
              <td class="text-right font-mono">{{ c.total_amount }} {{ c.currency?.code }}</td>
              <td class="text-center"><StatusBadge :status="c.status" /></td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="claims" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import SelectBox from '@/Components/SelectBox.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

const props = defineProps({
  claims:    { type: Object, required: true },
  employees: { type: Array,  default: () => [] },
  statuses:  { type: Array,  default: () => [] },
  filters:   { type: Object, default: () => ({}) },
})

/**
 * What the company owes staff right now. Approved means the liability
 * has been posted to Other Creditors; paid means it has been cleared.
 * Computed over the current page only — the page is a listing, not a
 * report, and a full-company figure belongs on the HR dashboard.
 */
const outstandingTotal = computed(() => {
  const sum = props.claims.data
    .filter((c) => c.status === 'approved')
    .reduce((total, c) => total + Number(c.amount_functional || 0), 0)

  return sum.toFixed(2)
})

const filterForm = ref({
  search:     props.filters.search ?? '',
  status:     props.filters.status ?? '',
  partner_id: props.filters.partner_id ?? '',
})

// The controller sends the raw enum ('draft', 'submitted', …). Rendering
// it verbatim, as this page used to, showed lowercase English in both
// languages; the label is looked up and the raw value is still what gets
// sent back as the filter.
const statusOptions = computed(() =>
  props.statuses.map(s => ({
    value: s,
    label: t(`accounting.hr.expenseClaimIndex.statusLabels.${s}`),
  })))

const hasActiveFilters = computed(() =>
  Object.values(filterForm.value).some(v => v !== '' && v !== null))

function applyFilters() {
  router.get(route('hr.expense-claims.index'), filterForm.value, { preserveState: true, replace: true })
}

function clearFilters() {
  filterForm.value = { search: '', status: '', partner_id: '' }
  applyFilters()
}

function open(claim) {
  router.get(route('hr.expense-claims.show', claim.id))
}
</script>

<style scoped>

.ec-outstanding {
  display: flex; align-items: baseline; gap: 10px;
  padding: 12px 16px; margin-bottom: 16px; border-radius: 10px;
  background: var(--zv-bg-surface, #f1f5f9); border: 1px solid var(--zv-border, #e2e8f0);
}
.ec-outstanding-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--zv-text-secondary, #475569); }
.ec-outstanding-value { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--zv-text-primary, #0f172a); }

.ec-row { cursor: pointer; }
.ec-row:hover { background: var(--zv-bg-surface, #f8fafc); }
</style>
