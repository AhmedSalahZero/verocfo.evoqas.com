<!--
══════════════════════════════════════════════════════════════════
  Accounting/CustomerBalances/InvoiceReport.vue

  Every posted invoice for one customer, in one currency — reached
  from the "Invoices Report" button on the Customer Balances table.

  Adjust Due Date — inline per row (no separate page/modal): moves
  due_date only, original_due_date is kept forever (see
  CustomerInvoiceService::adjustDueDate() for why — a future
  collection-pattern report needs both).

  Debit Note / Credit Note — replace what used to be a single
  "Deduct" button (per Mahmoud, September 2026): each jumps straight
  into that document's own Create form with this customer and this
  invoice already selected — same pattern as the Collect/Pay buttons
  built earlier for Customer Invoices / Supplier Bills.

  @layout    AppLayout
  @route     accounting.customer-balances.invoices
  @props     partner, currency, invoices
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <div class="mb-4">
      <Link :href="route('accounting.customer-balances.index')" class="zv-btn zv-btn-ghost zv-btn-sm">
        ← {{ t('accounting.customerBalances.invoiceReport.backToBalances') }}
      </Link>
    </div>

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.customerBalances.invoiceReport.invoicesTableFor', { name: partner.name_en, code: currency?.code })"
      :subtitle="t('accounting.customerBalances.invoiceReport.everyInvoiceForThisCustomerIn')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>'
    />

    <div class="zv-card">
      <EmptyState
        v-if="!invoices.length"
        :title="t('accounting.customerBalances.invoiceReport.noInvoicesFound')"
        :message="t('accounting.customerBalances.invoiceReport.thisCustomerHasNoPostedInvoices')"
        icon-path="M9 14l6-6m-5-2h.01M15 13h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{{ t('accounting.customerBalances.invoiceReport.projectName') }}</th>
              <th>{{ t('accounting.customerBalances.invoiceReport.invoiceDate') }}</th>
              <th>{{ t('accounting.customerBalances.invoiceReport.invoiceNumber') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.invoiceReport.invoiceAmount') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.invoiceReport.withholdAmount') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.invoiceReport.vatAmount') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.invoiceReport.totalDeductions') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.invoiceReport.totalCollections') }}</th>
              <th>{{ t('accounting.customerBalances.invoiceReport.invoiceDueDate') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerBalances.invoiceReport.netBalance') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th>{{ t('accounting.customerBalances.invoiceReport.aging') }}</th>
              <th class="ir-th-actions"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(inv, idx) in invoices" :key="inv.id">
              <tr>
                <td>{{ idx + 1 }}</td>
                <td><div class="zv-cell-clamp">{{ inv.project_name ?? '—' }}</div></td>
                <td>{{ inv.invoice_date }}</td>
                <td><span class="zv-code">{{ inv.invoice_code }}</span></td>
                <td class="zv-table-num">{{ fmt(inv.invoice_amount) }}</td>
                <td class="zv-table-num">{{ fmt(inv.withhold_amount) }}</td>
                <td class="zv-table-num">{{ fmt(inv.vat_amount) }}</td>
                <td class="zv-table-num">{{ fmt(inv.total_deductions) }}</td>
                <td class="zv-table-num">{{ fmt(inv.total_collections) }}</td>
                <td>{{ inv.due_date }}</td>
                <td class="zv-table-num"><strong>{{ fmt(inv.net_balance) }}</strong></td>
                <td class="text-center"><span class="zv-badge" :class="statusMeta(inv).badge">{{ statusMeta(inv).label }}</span></td>
                <td>{{ inv.aging_days !== null ? inv.aging_days : '—' }}</td>
                <td class="ir-th-actions">
                  <div class="ir-actions-inner">
                    <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="toggleAdjust(inv)">{{ t('accounting.customerBalances.invoiceReport.adjustDueDate') }}</button>
                    <Link :href="route('accounting.customer-debit-notes.create', { partner_id: partner.id, invoice_id: inv.id })" class="zv-btn zv-btn-warning zv-btn-sm">{{ t('accounting.customerBalances.invoiceReport.debitNote') }}</Link>
                    <Link :href="route('accounting.credit-notes.create', { partner_id: partner.id, invoice_id: inv.id })" class="zv-btn zv-btn-danger zv-btn-sm">{{ t('accounting.customerBalances.invoiceReport.creditNote') }}</Link>
                  </div>
                </td>
              </tr>
              <tr v-if="adjustingId === inv.id" class="ir-adjust-row">
                <td colspan="14">
                  <div class="ir-adjust-inline">
                    <span class="text-sm">{{ t('accounting.customerBalances.invoiceReport.originalDueDate') }}: <strong>{{ inv.original_due_date }}</strong></span>
                    <input v-model="adjustDate" type="date" class="zv-input" style="width: 12rem;" />
                    <button type="button" class="zv-btn zv-btn-primary zv-btn-sm" :disabled="!adjustDate || adjusting" @click="saveAdjust(inv)">
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
  invoices: { type: Array, default: () => [] },
})

const STATUS_BADGES = {
  collected:            { label: 'Collected', badge: 'zv-badge-green' },
  partially_collected:  { label: 'Partially Collected', badge: 'zv-badge-amber' },
  not_collected:        { label: 'Not Collected', badge: 'zv-badge-gray' },
}

function statusMeta(inv) {
  const base = STATUS_BADGES[inv.status] ?? STATUS_BADGES.not_collected
  if (inv.is_past_due) {
    return { label: base.label + ' — ' + t('accounting.customerBalances.invoiceReport.pastDue'), badge: 'zv-badge-danger' }
  }
  return base
}

const adjustingId = ref(null)
const adjustDate = ref('')
const adjusting = ref(false)

function toggleAdjust(inv) {
  if (adjustingId.value === inv.id) { adjustingId.value = null; return }
  adjustingId.value = inv.id
  adjustDate.value = inv.due_date
}

function saveAdjust(inv) {
  adjusting.value = true
  router.patch(route('accounting.customer-invoices.adjust-due-date', inv.id), { due_date: adjustDate.value }, {
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
.ir-th-actions { width: 21rem; text-align: end; white-space: nowrap; }
.ir-actions-inner { display: flex; gap: .375rem; justify-content: flex-end; flex-wrap: nowrap; }
.ir-actions-inner .zv-btn { white-space: nowrap; }
.ir-adjust-row td { background: rgba(20,144,168,0.06); padding: 0.75rem 1rem; }
.ir-adjust-inline { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
</style>
