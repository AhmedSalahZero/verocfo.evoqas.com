<!--
══════════════════════════════════════════════════════════════════
  Accounting/InternalSettlements/Form.vue

  Create and Edit share this one page (mode detected by whether
  props.internalSettlement exists) — same convention as every other
  module. Only a DRAFT is ever editable; a posted settlement shows a
  read-only view with Set to Draft / Cancel actions, same pattern as
  Customer Collection / Supplier Payment.

  Create: pick a partner+currency pair (only ones where the SAME
  partner has both an open AR balance and an open AP balance in that
  SAME currency — see InternalSettlementService::
  eligiblePartnerCurrencyPairs()), pick an amount up to the smaller of
  the two, then allocate that exact amount across specific customer
  invoices AND specific supplier bills.

  @layout    AppLayout
  @route     accounting.internal-settlements.create / .edit
  @props     internalSettlement, eligiblePairs, periodClosed
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="isEdit ? internalSettlement.settlement_code : t('accounting.internalSettlements.form.newInternalSettlement')"
      :subtitle="t('accounting.internalSettlements.form.netAPartnersReceivableAgainstTheir')"
    />

    <div v-if="isEdit && internalSettlement.status === 'draft'" class="zv-alert zv-alert-warning mb-4">
      {{ t('accounting.internalSettlements.form.thisSettlementWasReopenedAndIs') }}
    </div>

    <form @submit.prevent="submit">
      <!-- ══════════════════ Header ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header"><div class="form-section-title">{{ t('accounting.internalSettlements.form.settlementDetails') }}</div></div>
        <div class="form-section-body">
          <div class="field-grid field-grid-8-2-2">
            <div class="field-group" v-if="!isEdit">
              <label class="zv-label zv-label-required">{{ t('accounting.internalSettlements.form.partnerCurrency') }}</label>
              <SelectBox v-model="selectedPairKey" :options="eligiblePairs" :value-fn="p => p.partner_id + '-' + p.currency_id" :label-fn="p => `${p.partner_name} — ${p.currency_code} (${t('accounting.internalSettlements.form.maxAmount')}: ${fmt(p.max_amount)})`" :placeholder="t('accounting.internalSettlements.form.selectAPartner')" :clearable="false" @change="onPairChange" />
            </div>
            <div class="field-group" v-else>
              <label class="zv-label">{{ t('common.partner') }}</label>
              <input type="text" class="zv-input" disabled :value="internalSettlement.partner?.name_en" />
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.internalSettlements.form.settlementDate') }}</label>
              <input v-model="form.settlement_date" type="date" class="zv-input" :disabled="readOnly" />
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.amount') }}</label>
              <input
                v-model="form.amount" type="number" step="0.01" min="0.01"
                class="zv-input" :disabled="readOnly"
                :max="selectedPair?.max_amount"
              />
              <p v-if="selectedPair" class="field-hint">
                {{ t('accounting.internalSettlements.form.customerBalance') }}: {{ fmt(selectedPair.ar_total) }} — {{ t('accounting.internalSettlements.form.supplierDue') }}: {{ fmt(selectedPair.ap_total) }} — {{ t('accounting.internalSettlements.form.maxAmount') }}: {{ fmt(selectedPair.max_amount) }}
              </p>
            </div>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.internalSettlements.form.internalNotes') }}</label>
            <textarea v-model="form.internal_notes" class="zv-input" rows="2" :disabled="readOnly"></textarea>
          </div>
        </div>
      </div>

      <!-- ══════════════════ Customer Invoices to reduce ══════════════════ -->
      <div class="form-section mb-5" v-if="activePartnerId">
        <div class="form-section-header"><div class="form-section-title">{{ t('accounting.internalSettlements.form.customerInvoicesToReduce') }}</div></div>
        <div class="form-section-body">
          <div v-if="!openInvoices.length" class="text-sm opacity-50" style="padding: 12px 0;">{{ t('accounting.internalSettlements.form.noOpenInvoicesInThisCurrency') }}</div>
          <div v-else class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.internalSettlements.form.invoiceNumber') }}</th>
                  <th>{{ t('accounting.internalSettlements.form.invoiceDate') }}</th>
                  <th class="zv-table-num">{{ t('accounting.internalSettlements.form.netBalance') }}</th>
                  <th class="zv-table-num">{{ t('accounting.internalSettlements.form.allocateAmount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="inv in openInvoices" :key="inv.id">
                  <td><span class="zv-code">{{ inv.invoice_code }}</span></td>
                  <td>{{ inv.invoice_date }}</td>
                  <td class="zv-table-num">{{ fmt(inv.amount_outstanding) }}</td>
                  <td class="zv-table-num">
                    <input
                      v-model="customerAllocAmounts[inv.id]" type="number" step="0.01" min="0" :max="inv.amount_outstanding"
                      class="zv-input text-end" style="width: 9rem;" placeholder="0.00" :disabled="readOnly"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="cc-settlement-summary mt-4">
            <div :class="{ 'cc-summary-warn': !customerAllocationMatches }">
              <span>{{ t('accounting.internalSettlements.form.totalAllocated') }}</span><strong>{{ fmt(totalCustomerAllocated) }}</strong> / {{ fmt(form.amount || 0) }}
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ Supplier Bills to reduce ══════════════════ -->
      <div class="form-section mb-5" v-if="activePartnerId">
        <div class="form-section-header"><div class="form-section-title">{{ t('accounting.internalSettlements.form.supplierBillsToReduce') }}</div></div>
        <div class="form-section-body">
          <div v-if="!openBills.length" class="text-sm opacity-50" style="padding: 12px 0;">{{ t('accounting.internalSettlements.form.noOpenBillsInThisCurrency') }}</div>
          <div v-else class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.internalSettlements.form.billNumber') }}</th>
                  <th>{{ t('accounting.internalSettlements.form.billDate') }}</th>
                  <th class="zv-table-num">{{ t('accounting.internalSettlements.form.netBalance') }}</th>
                  <th class="zv-table-num">{{ t('accounting.internalSettlements.form.allocateAmount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="bill in openBills" :key="bill.id">
                  <td><span class="zv-code">{{ bill.bill_code }}</span></td>
                  <td>{{ bill.bill_date }}</td>
                  <td class="zv-table-num">{{ fmt(bill.amount_outstanding) }}</td>
                  <td class="zv-table-num">
                    <input
                      v-model="supplierAllocAmounts[bill.id]" type="number" step="0.01" min="0" :max="bill.amount_outstanding"
                      class="zv-input text-end" style="width: 9rem;" placeholder="0.00" :disabled="readOnly"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="cc-settlement-summary mt-4">
            <div :class="{ 'cc-summary-warn': !supplierAllocationMatches }">
              <span>{{ t('accounting.internalSettlements.form.totalAllocated') }}</span><strong>{{ fmt(totalSupplierAllocated) }}</strong> / {{ fmt(form.amount || 0) }}
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ Journal Entry (posted, read-only) ══════════════════ -->
      <div class="form-section mb-5" v-if="isEdit && internalSettlement.journal_entry">
        <div class="form-section-header"><div class="form-section-title">{{ t('accounting.internalSettlements.form.journalEntry') }}</div></div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead><tr><th>{{ t('accounting.internalSettlements.form.account') }}</th><th class="zv-table-num">{{ t('accounting.internalSettlements.form.debit') }}</th><th class="zv-table-num">{{ t('accounting.internalSettlements.form.credit') }}</th></tr></thead>
            <tbody>
              <tr v-for="line in internalSettlement.journal_entry.lines" :key="line.id">
                <td><span class="zv-code">{{ line.chart_of_account?.code }}</span><span class="zv-account-name">{{ line.chart_of_account?.name_en }}</span></td>
                <td class="zv-table-num">{{ fmt(line.debit) }}</td>
                <td class="zv-table-num">{{ fmt(line.credit) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.internal-settlements.index')" class="zv-btn zv-btn-ghost">{{ t('accounting.internalSettlements.form.close') }}</Link>

          <button
            v-if="isEdit && internalSettlement.status === 'posted' && !periodClosed"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('accounting.internalSettlements.form.setToDraft') }}</button>

          <button
            v-if="isEdit && internalSettlement.status === 'draft'"
            type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete"
          >{{ deleting ? t('common.deleting') : t('common.delete') }}</button>

          <button
            v-if="isEdit && internalSettlement.status === 'posted' && !periodClosed"
            type="button" class="zv-btn zv-btn-danger" @click="confirmCancel"
          >{{ t('common.cancel') }}</button>

          <button
            v-if="!readOnly"
            type="submit" class="zv-btn zv-btn-primary" :disabled="form.processing || !canSubmit"
          >{{ form.processing ? t('common.saving') : t('common.save') }}</button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { todayLocal } from '@/Utils/dates'
import { useI18n } from 'vue-i18n'
import { Link, router, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  internalSettlement: { type: Object, default: null },
  eligiblePairs:       { type: Array, default: () => [] },
  periodClosed:        { type: Boolean, default: false },
})

const isEdit = computed(() => !!props.internalSettlement?.id)
const readOnly = computed(() => isEdit.value && props.internalSettlement.status !== 'draft')

const selectedPairKey = ref('')
const activePartnerId = ref(isEdit.value ? props.internalSettlement.partner_id : null)
const activeCurrencyId = ref(isEdit.value ? props.internalSettlement.currency_id : null)

const selectedPair = computed(() => {
  if (! activePartnerId.value || ! activeCurrencyId.value) return null
  return props.eligiblePairs.find(p => p.partner_id === activePartnerId.value && p.currency_id === activeCurrencyId.value) ?? null
})

const form = useForm(isEdit.value ? {
  settlement_date: props.internalSettlement.settlement_date,
  amount: props.internalSettlement.amount,
  internal_notes: props.internalSettlement.internal_notes ?? '',
  partner_id: props.internalSettlement.partner_id,
  currency_id: props.internalSettlement.currency_id,
  customer_allocations: [],
  supplier_allocations: [],
} : {
  settlement_date: todayLocal(),
  amount: '',
  internal_notes: '',
  partner_id: '',
  currency_id: '',
  customer_allocations: [],
  supplier_allocations: [],
})

const openInvoices = ref([])
const openBills = ref([])
const customerAllocAmounts = reactive({})
const supplierAllocAmounts = reactive({})

async function fetchOpenInvoicesAndBills() {
  if (! activePartnerId.value || ! activeCurrencyId.value) { openInvoices.value = []; openBills.value = []; return }
  const res = await fetch(route('accounting.internal-settlements.open-invoices-and-bills', {
    partner_id: activePartnerId.value, currency_id: activeCurrencyId.value,
  }), { headers: { Accept: 'application/json' }, cache: 'no-store' })
  const data = await res.json()
  openInvoices.value = data.invoices ?? []
  openBills.value = data.bills ?? []
}

function onPairChange() {
  // Amounts typed for the previous partner's documents must not carry over.
  Object.keys(customerAllocAmounts).forEach(k => delete customerAllocAmounts[k])
  Object.keys(supplierAllocAmounts).forEach(k => delete supplierAllocAmounts[k])
  const [partnerId, currencyId] = selectedPairKey.value.split('-').map(Number)
  activePartnerId.value = partnerId
  activeCurrencyId.value = currencyId
  form.partner_id = partnerId
  form.currency_id = currencyId
  fetchOpenInvoicesAndBills()
}

const totalCustomerAllocated = computed(() =>
  Object.values(customerAllocAmounts).reduce((sum, v) => sum + (parseFloat(v) || 0), 0)
)
const totalSupplierAllocated = computed(() =>
  Object.values(supplierAllocAmounts).reduce((sum, v) => sum + (parseFloat(v) || 0), 0)
)

const customerAllocationMatches = computed(() => Math.abs(totalCustomerAllocated.value - (parseFloat(form.amount) || 0)) < 0.005)
const supplierAllocationMatches = computed(() => Math.abs(totalSupplierAllocated.value - (parseFloat(form.amount) || 0)) < 0.005)

const canSubmit = computed(() =>
  !!form.amount && parseFloat(form.amount) > 0 && customerAllocationMatches.value && supplierAllocationMatches.value
)

function submit() {
  form.customer_allocations = Object.entries(customerAllocAmounts)
    .filter(([, amount]) => parseFloat(amount) > 0)
    .map(([customer_invoice_id, allocated_amount]) => ({ customer_invoice_id: Number(customer_invoice_id), allocated_amount }))

  form.supplier_allocations = Object.entries(supplierAllocAmounts)
    .filter(([, amount]) => parseFloat(amount) > 0)
    .map(([supplier_bill_id, allocated_amount]) => ({ supplier_bill_id: Number(supplier_bill_id), allocated_amount }))

  if (isEdit.value) {
    form.put(route('accounting.internal-settlements.update', props.internalSettlement.id))
  } else {
    form.post(route('accounting.internal-settlements.store'))
  }
}

function confirmSetToDraft() {
  if (! confirm(t('accounting.internalSettlements.form.reopenThisSettlementForEditingThis'))) return
  router.patch(route('accounting.internal-settlements.set-to-draft', props.internalSettlement.id), {}, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('accounting.internalSettlements.form.deleteThisSettlementThisIsPermanent'))) return
  deleting.value = true
  router.delete(route('accounting.internal-settlements.destroy', props.internalSettlement.id), {
    onFinish: () => { deleting.value = false },
  })
}

function confirmCancel() {
  if (! confirm(t('accounting.internalSettlements.form.cancelThisSettlement'))) return
  router.patch(route('accounting.internal-settlements.cancel', props.internalSettlement.id))
}

// Edit mode (a reopened draft): pre-load the open invoices/bills for
// this settlement's own partner+currency (which, now that this
// settlement's own allocations were reversed by setToDraft(), will
// correctly include the ones it originally touched), and pre-fill
// the allocation inputs from what's already on record.
if (isEdit.value) {
  fetchOpenInvoicesAndBills()
  props.internalSettlement.customerAllocations?.forEach(a => { customerAllocAmounts[a.customer_invoice_id] = a.allocated_amount })
  props.internalSettlement.supplierAllocations?.forEach(a => { supplierAllocAmounts[a.supplier_bill_id] = a.allocated_amount })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.cc-settlement-summary { display: flex; gap: 1.5rem; font-size: 0.875rem; }
.cc-summary-warn strong { color: #ef4444; }
</style>
