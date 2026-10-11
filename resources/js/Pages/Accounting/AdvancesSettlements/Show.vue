<!--
══════════════════════════════════════════════════════════════════
  Accounting/AdvancesSettlements/Show.vue

  Advances & Settlements — one employee's full history. Merges three
  sources into one timeline (see AdvancesSettlementsService::history()):
  advances given (Other Payment), manual repayments (Other Collection),
  and payroll deductions (Payroll Run). Per Diem never appears here —
  it's an outright expense with no balance (see Index.vue header).

  "Give New Advance" / "Record Settlement" deliberately link OUT to
  the existing Other Payment / Other Collection forms (pre-filled)
  rather than duplicating their posting logic on this page.

  @layout    AppLayout
  @route     hr.advances.show
  @props     partner, balances, history
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.hr.advancesSettlementsShow.advancesSettlements'), href: route('hr.advances.index') }, { label: partner.name_en }]"
      :title="`${partner.code} — ${partner.name_en}`"
      :subtitle="t('accounting.hr.advancesSettlementsShow.everyAdvanceGivenAndEverySettlement')"
    />

    <div class="as-balance-grid mb-5">
      <div class="zv-card as-balance-card">
        <div class="as-balance-label">{{ t('accounting.hr.advancesSettlementsShow.advanceFromSalaryBalance') }}</div>
        <div class="as-balance-figure">{{ fmt(balances.advance_balance) }}</div>
        <Link :href="givePaymentLink('advance_salary')" class="zv-btn zv-btn-primary zv-btn-sm">{{ t('accounting.hr.advancesSettlementsShow.giveNewAdvance') }}</Link>
        <Link v-if="balances.advance_balance !== '0.00'" :href="settleLink('advance_salary')" class="zv-btn zv-btn-secondary zv-btn-sm">{{ t('accounting.hr.advancesSettlementsShow.recordSettlement') }}</Link>
      </div>
      <div class="zv-card as-balance-card">
        <div class="as-balance-label">{{ t('accounting.hr.advancesSettlementsShow.custodyBalance') }}</div>
        <div class="as-balance-figure">{{ fmt(balances.custody_balance) }}</div>
        <Link :href="givePaymentLink('custody')" class="zv-btn zv-btn-primary zv-btn-sm">{{ t('accounting.hr.advancesSettlementsShow.giveNewCustody') }}</Link>
        <Link v-if="balances.custody_balance !== '0.00'" :href="settleLink('custody')" class="zv-btn zv-btn-secondary zv-btn-sm">{{ t('accounting.hr.advancesSettlementsShow.recordSettlement') }}</Link>
      </div>
    </div>

    <div class="zv-card">
      <EmptyState
        v-if="!history.length"
        :title="t('accounting.hr.advancesSettlementsShow.noHistoryYet')"
        :message="t('accounting.hr.advancesSettlementsShow.givingAnAdvanceOrCustodyWillShow')"
        icon-path="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.advancesSettlementsShow.date') }}</th>
              <th>{{ t('accounting.hr.advancesSettlementsShow.type') }}</th>
              <th>{{ t('accounting.hr.advancesSettlementsShow.description') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.advancesSettlementsShow.given') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.advancesSettlementsShow.settled') }}</th>
              <th>{{ t('accounting.hr.advancesSettlementsShow.reference') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in history" :key="i">
              <td>{{ row.date }}</td>
              <td><span class="zv-tag">{{ typeLabel(row.type) }}</span></td>
              <td>{{ kindLabel(row.kind) }}<span v-if="row.note" class="zv-text-muted"> — {{ row.note }}</span></td>
              <td class="zv-table-num">{{ row.kind === 'given' ? fmt(row.amount) : '' }}</td>
              <td class="zv-table-num">{{ row.kind === 'settled' ? fmt(row.amount) : '' }}</td>
              <td><span class="zv-code">{{ row.reference }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { Link } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'

const { t } = useI18n()

const props = defineProps({
  partner:  { type: Object, required: true },
  balances: { type: Object, required: true },
  history:  { type: Array, default: () => [] },
})

function givePaymentLink(advanceType) {
  return route('accounting.other-payments.create', {
    partner_type: 'employee',
    partner_id: props.partner.id,
    employee_advance_type: advanceType,
  })
}

function settleLink(advanceType) {
  return route('accounting.other-collections.create', {
    partner_type: 'employee',
    partner_id: props.partner.id,
    employee_advance_type: advanceType,
  })
}

function typeLabel(type) {
  return type === 'custody'
    ? t('accounting.hr.advancesSettlementsShow.custody')
    : t('accounting.hr.advancesSettlementsShow.advanceFromSalary')
}

function kindLabel(kind) {
  return kind === 'given'
    ? t('accounting.hr.advancesSettlementsShow.advanceGiven')
    : t('accounting.hr.advancesSettlementsShow.settlement')
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.as-balance-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; }
.as-balance-card { padding: 1rem 1.25rem; display: flex; flex-direction: column; align-items: flex-start; gap: 0.5rem; }
.as-balance-label { font-size: 0.8125rem; color: var(--zv-text-secondary); }
.as-balance-figure { font-size: 1.5rem; font-weight: 700; color: var(--zv-text-primary); margin-bottom: 0.25rem; }
</style>
