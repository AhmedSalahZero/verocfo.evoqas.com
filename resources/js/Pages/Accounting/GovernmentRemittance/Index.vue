<!--
══════════════════════════════════════════════════════════════════
  Accounting/GovernmentRemittance/Index.vue

  Government Remittance (2026-09) — mirrors AdvancesSettlements/
  Show.vue's shape (balance cards + merged history), but company-wide
  rather than per-employee: Payroll accrues 226005 Salaries Tax
  Payable and 240 Social Insurance Payable every run; this shows how
  much has actually been remitted to the Tax Authority / Social
  Insurance Authority versus what's still outstanding.

  "Remit Tax" / "Remit Social Insurance" link OUT to Other Payment,
  pre-filled with partner_type=other and the correct debit account —
  the actual posting stays owned by OtherPaymentService, same
  reasoning as Advances & Settlements.

  @layout    AppLayout
  @route     hr.government-remittance.index
  @props     balances, history, taxAccountId, socialInsuranceAccountId
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.governmentRemittanceIndex.governmentRemittance')"
      :subtitle="t('accounting.hr.governmentRemittanceIndex.whatsBeenRemittedToTheTax')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21"/>'
    />

    <div class="gr-balance-grid mb-5">
      <div class="zv-card gr-balance-card">
        <div class="gr-balance-label">{{ t('accounting.hr.governmentRemittanceIndex.salariesTaxPayable') }}</div>
        <div class="gr-balance-figure">{{ fmt(balances.tax) }}</div>
        <Link v-if="taxAccountId" :href="remitLink(taxAccountId)" class="zv-btn zv-btn-primary zv-btn-sm">{{ t('accounting.hr.governmentRemittanceIndex.remitTax') }}</Link>
      </div>
      <div class="zv-card gr-balance-card">
        <div class="gr-balance-label">{{ t('accounting.hr.governmentRemittanceIndex.socialInsurancePayable') }}</div>
        <div class="gr-balance-figure">{{ fmt(balances.social_insurance) }}</div>
        <Link v-if="socialInsuranceAccountId" :href="remitLink(socialInsuranceAccountId)" class="zv-btn zv-btn-primary zv-btn-sm">{{ t('accounting.hr.governmentRemittanceIndex.remitSocialInsurance') }}</Link>
      </div>
    </div>

    <div class="zv-card">
      <EmptyState
        v-if="!history.length"
        :title="t('accounting.hr.governmentRemittanceIndex.noHistoryYet')"
        :message="t('accounting.hr.governmentRemittanceIndex.postAPayrollRunToAccrueTax')"
        icon-path="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.governmentRemittanceIndex.date') }}</th>
              <th>{{ t('accounting.hr.governmentRemittanceIndex.liability') }}</th>
              <th>{{ t('accounting.hr.governmentRemittanceIndex.description') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.governmentRemittanceIndex.accrued') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.governmentRemittanceIndex.remitted') }}</th>
              <th>{{ t('accounting.hr.governmentRemittanceIndex.reference') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in history" :key="i">
              <td>{{ row.date }}</td>
              <td><span class="zv-tag">{{ kindLabel(row.kind) }}</span></td>
              <td>{{ typeLabel(row.type) }}<span v-if="row.note" class="zv-text-muted"> — {{ row.note }}</span></td>
              <td class="zv-table-num">{{ row.type === 'accrued' ? fmt(row.amount) : '' }}</td>
              <td class="zv-table-num">{{ row.type === 'remitted' ? fmt(row.amount) : '' }}</td>
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

defineProps({
  balances:                  { type: Object, required: true },
  history:                   { type: Array, default: () => [] },
  taxAccountId:               { type: [Number, String], default: null },
  socialInsuranceAccountId:   { type: [Number, String], default: null },
})

function remitLink(accountId) {
  return route('accounting.other-payments.create', {
    partner_type: 'other',
    debit_account_id: accountId,
  })
}

function kindLabel(kind) {
  return kind === 'tax'
    ? t('accounting.hr.governmentRemittanceIndex.salariesTax')
    : t('accounting.hr.governmentRemittanceIndex.socialInsurance')
}

function typeLabel(type) {
  return type === 'accrued'
    ? t('accounting.hr.governmentRemittanceIndex.accruedFromPayroll')
    : t('accounting.hr.governmentRemittanceIndex.remittedToAuthority')
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.gr-balance-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; }
.gr-balance-card { padding: 1rem 1.25rem; display: flex; flex-direction: column; align-items: flex-start; gap: 0.5rem; }
.gr-balance-label { font-size: 0.8125rem; color: var(--zv-text-secondary); }
.gr-balance-figure { font-size: 1.5rem; font-weight: 700; color: var(--zv-text-primary); margin-bottom: 0.25rem; }
</style>
