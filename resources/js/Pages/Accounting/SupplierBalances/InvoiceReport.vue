<!--
══════════════════════════════════════════════════════════════════
  Accounting/SupplierBalances/InvoiceReport.vue

  Payable-side mirror of Customer Balances/InvoiceReport.vue. Every
  posted bill for one supplier, in one currency — reached from the
  "Invoices Report" button on the Supplier Balances table.

  Debit Note / Credit Note buttons jump straight into that document's
  own Create form with this supplier and this bill already selected.
  Adjust Due Date is inline per row, keeps original_due_date forever.
  Aging: Paid → dash, anything else → Today minus Bill Date.

  @layout    AppLayout
  @route     accounting.supplier-balances.invoices
  @props     partner, currency, bills
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <div class="mb-4">
      <Link :href="route('accounting.supplier-balances.index')" class="zv-btn zv-btn-ghost zv-btn-sm">
        ← {{ t('accounting.supplierBalances.invoiceReport.backToBalances') }}
      </Link>
    </div>

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.supplierBalances.invoiceReport.invoicesTableFor', { name: partner.name_en, code: currency?.code })"
      :subtitle="t('accounting.supplierBalances.invoiceReport.everyBillForThisSupplierIn')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>'
    />

    <div class="zv-card">
      <EmptyState
        v-if="!bills.length"
        :title="t('accounting.supplierBalances.invoiceReport.noBillsFound')"
        :message="t('accounting.supplierBalances.invoiceReport.thisSupplierHasNoPostedBills')"
        icon-path="M9 14l6-6m-5-2h.01M15 13h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{{ t('accounting.supplierBalances.invoiceReport.projectName') }}</th>
              <th>{{ t('accounting.supplierBalances.invoiceReport.billDate') }}</th>
              <th>{{ t('accounting.supplierBalances.invoiceReport.billNumber') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.invoiceReport.billAmount') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.invoiceReport.withholdAmount') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.invoiceReport.vatAmount') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.invoiceReport.totalDeductions') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.invoiceReport.totalPayments') }}</th>
              <th>{{ t('accounting.supplierBalances.invoiceReport.billDueDate') }}</th>
              <th class="zv-table-num">{{ t('accounting.supplierBalances.invoiceReport.netBalance') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th>{{ t('accounting.supplierBalances.invoiceReport.aging') }}</th>
              <th class="sir-th-actions"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(bill, idx) in bills" :key="bill.id">
              <tr>
                <td>{{ idx + 1 }}</td>
                <td><div class="zv-cell-clamp">{{ bill.project_name ?? '—' }}</div></td>
                <td>{{ bill.bill_date }}</td>
                <td><span class="zv-code">{{ bill.bill_code }}</span></td>
                <td class="zv-table-num">{{ fmt(bill.bill_amount) }}</td>
                <td class="zv-table-num">{{ fmt(bill.withhold_amount) }}</td>
                <td class="zv-table-num">{{ fmt(bill.vat_amount) }}</td>
                <td class="zv-table-num">{{ fmt(bill.total_deductions) }}</td>
                <td class="zv-table-num">{{ fmt(bill.total_payments) }}</td>
                <td>{{ bill.due_date }}</td>
                <td class="zv-table-num"><strong>{{ fmt(bill.net_balance) }}</strong></td>
                <td class="text-center"><span class="zv-badge" :class="statusMeta(bill).badge">{{ statusMeta(bill).label }}</span></td>
                <td>{{ bill.aging_days !== null ? bill.aging_days : '—' }}</td>
                <td class="sir-th-actions">
                  <div class="sir-actions-inner">
                    <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="toggleAdjust(bill)">{{ t('accounting.supplierBalances.invoiceReport.adjustDueDate') }}</button>
                    <Link :href="route('accounting.supplier-debit-notes.create', { partner_id: partner.id, bill_id: bill.id })" class="zv-btn zv-btn-warning zv-btn-sm">{{ t('accounting.supplierBalances.invoiceReport.debitNote') }}</Link>
                    <Link :href="route('accounting.supplier-credit-notes.create', { partner_id: partner.id, bill_id: bill.id })" class="zv-btn zv-btn-danger zv-btn-sm">{{ t('accounting.supplierBalances.invoiceReport.creditNote') }}</Link>
                  </div>
                </td>
              </tr>
              <tr v-if="adjustingId === bill.id" class="sir-adjust-row">
                <td colspan="14">
                  <div class="sir-adjust-inline">
                    <span class="text-sm">{{ t('accounting.supplierBalances.invoiceReport.originalDueDate') }}: <strong>{{ bill.original_due_date }}</strong></span>
                    <input v-model="adjustDate" type="date" class="zv-input" style="width: 12rem;" />
                    <button type="button" class="zv-btn zv-btn-primary zv-btn-sm" :disabled="!adjustDate || adjusting" @click="saveAdjust(bill)">
                      {{ adjusting ? t('common.saving') : t('common.save') }}
                    </button>
                    <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="adjustingId = null">{{ t('common.cancel') }}</button>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'

const { t } = useI18n()

const props = defineProps({
  partner:  { type: Object, required: true },
  currency: { type: Object, default: null },
  bills:    { type: Array, default: () => [] },
})

const STATUS_BADGES = {
  paid:            { label: 'Paid', badge: 'zv-badge-green' },
  partially_paid:  { label: 'Partially Paid', badge: 'zv-badge-amber' },
  not_paid:        { label: 'Not Paid', badge: 'zv-badge-gray' },
}

function statusMeta(bill) {
  const base = STATUS_BADGES[bill.status] ?? STATUS_BADGES.not_paid
  if (bill.is_past_due) {
    return { label: base.label + ' — ' + t('accounting.supplierBalances.invoiceReport.pastDue'), badge: 'zv-badge-danger' }
  }
  return base
}

const adjustingId = ref(null)
const adjustDate = ref('')
const adjusting = ref(false)

function toggleAdjust(bill) {
  if (adjustingId.value === bill.id) { adjustingId.value = null; return }
  adjustingId.value = bill.id
  adjustDate.value = bill.due_date
}

function saveAdjust(bill) {
  adjusting.value = true
  router.patch(route('accounting.supplier-bills.adjust-due-date', bill.id), { due_date: adjustDate.value }, {
    preserveScroll: true,
    onSuccess: () => { adjustingId.value = null },
    onFinish: () => { adjusting.value = false },
  })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.sir-th-actions { width: 21rem; text-align: end; white-space: nowrap; }
.sir-actions-inner { display: flex; gap: .375rem; justify-content: flex-end; flex-wrap: nowrap; }
.sir-actions-inner .zv-btn { white-space: nowrap; }
.sir-adjust-row td { background: rgba(20,144,168,0.06); padding: 0.75rem 1rem; }
.sir-adjust-inline { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
</style>
