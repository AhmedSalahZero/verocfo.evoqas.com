<!--
══════════════════════════════════════════════════════════════════
  Accounting/CreditNotes/Form.vue

  Create + Edit — a Credit Note is only editable while status =
  draft (see CreditNoteService header). Posting and cancelling both
  happen from the Index page.

  Picking a posted, still-open Customer Invoice fetches its lines
  (invoice-lines) — the user picks which lines (or partial
  quantities) this credit note reverses. customer_invoice_id,
  currency, and exchange_rate are inherited from the invoice and are
  locked once set — a Credit Note is a partial reversal of one
  specific transaction, not a new one.

  @layout    AppLayout
  @route     accounting.credit-notes.create / .edit
  @props     creditNote
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.creditNotes.form.creditNotes'), href: route('accounting.credit-notes.index') }, { label: isEdit ? t('common.edit') : t('accounting.creditNotes.form.new') }]"
      :title="isEdit ? t('accounting.creditNotes.form.editCreditNote') : t('accounting.creditNotes.form.newCreditNote')"
      :subtitle="t('accounting.creditNotes.form.reversesPartOfAPostedCustomer')"
    />

    <form @submit.prevent="submit">

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 15l-3-3m0 0l3-3m-3 3h12M3 12a9 9 0 1018 0 9 9 0 00-18 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.creditNotes.form.creditNoteInformation') }}</div>
            <div class="form-section-sub">{{ t('accounting.creditNotes.form.whichInvoiceThisCreditNoteReverses') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-6-4-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.customer') }}</label>
              <SelectBox v-model="partnerId" :options="customers" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.creditNotes.form.selectCustomer')" :clearable="false" :disabled="isEdit" @change="onPartnerChange" />
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.creditNotes.form.customerInvoice') }}</label>
              <SelectBox v-model="form.customer_invoice_id" :options="eligibleInvoices" value-key="id" :label-fn="inv => `${inv.invoice_code} — ${t('accounting.creditNotes.form.outstanding')}: ${fmt(inv.amount_outstanding)}`" :placeholder="t('accounting.creditNotes.form.selectInvoice')" :clearable="false" :disabled="isEdit || !partnerId" @change="onInvoiceChange" :class="{ 'zv-input-error': form.errors.customer_invoice_id }" />
              <p v-if="form.errors.customer_invoice_id" class="zv-field-error">{{ form.errors.customer_invoice_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.creditNotes.form.creditDate') }}</label>
              <input v-model="form.credit_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.credit_date }"/>
              <p v-if="form.errors.credit_date" class="zv-field-error">{{ form.errors.credit_date }}</p>
            </div>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.creditNotes.form.reason') }}</label>
            <input v-model="form.credit_reason" type="text" class="zv-input" :placeholder="t('accounting.creditNotes.form.eGGoodsReturnedPricingCorrection')"/>
          </div>
        </div>
      </div>

      <!-- Lines -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.creditNotes.form.creditedLines') }}</div>
            <div class="form-section-sub">{{ t('accounting.creditNotes.form.pickWhichInvoiceLinesOrPartial') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div v-if="!form.customer_invoice_id" class="text-sm opacity-50" style="padding: 12px 0;">
            {{ t('accounting.creditNotes.form.selectACustomerInvoiceAboveTo') }}
          </div>

          <div v-else class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.creditNotes.form.invoiceLine') }}</th>
                  <th class="zv-table-num">{{ t('accounting.creditNotes.form.qty') }}</th>
                  <th class="zv-table-num">{{ t('accounting.creditNotes.form.unitPrice') }}</th>
                  <th class="zv-table-num">{{ t('accounting.creditNotes.form.vAT') }}</th>
                  <th class="zv-table-num">{{ t('accounting.creditNotes.form.lineTotal') }}</th>
                  <th class="cn-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td>
                    <SelectBox v-model="line.customer_invoice_line_id" :options="invoiceLines" value-key="id" :label-fn="il => il.description" :placeholder="t('accounting.creditNotes.form.selectLine')" :clearable="false" size="sm" @change="onInvoiceLineChange(line)" />
                  </td>
                  <td><input v-model="line.quantity" type="number" step="0.0001" min="0.0001" class="zv-input text-end" style="width:6rem;"/></td>
                  <td><input v-model="line.unit_price" type="number" step="0.01" min="0" class="zv-input text-end" style="width:8rem;"/></td>
                  <td><input v-model="line.vat_rate" type="number" step="0.01" min="0" max="100" class="zv-input text-end" style="width:5rem;"/></td>
                  <td class="zv-table-num">{{ fmt(lineTotal(line)) }}</td>
                  <td class="cn-th-actions">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.creditNotes.form.removeLine')" @click="removeLine(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button v-if="form.customer_invoice_id" type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('accounting.creditNotes.form.addLine') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="sb-totals">
            <div><span>{{ t('accounting.creditNotes.form.subtotal') }}</span><strong>{{ fmt(subtotal) }}</strong></div>
            <div><span>{{ t('accounting.creditNotes.form.vAT2') }}</span><strong>{{ fmt(vatTotal) }}</strong></div>
            <div class="sb-totals-grand"><span>{{ t('accounting.creditNotes.form.creditTotal') }}</span><strong>{{ fmt(grandTotal) }}</strong></div>
          </div>
          <p v-if="selectedInvoice && grandTotal > Number(selectedInvoice.amount_outstanding)" class="field-hint mt-2">
            {{ t('accounting.creditNotes.form.thisExceedsWhatSStillOutstandingOn') }}
          </p>
          <div class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.creditNotes.form.internalNotes') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.credit-notes.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.creditNotes.form.saveAsDraft')) }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sum as sumMoney, lineSubtotal, lineVat } from '@/Utils/money'
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  creditNote: { type: Object, default: null },
  customers:  { type: Array,  default: () => [] },
  preselectedPartnerId: { type: [Number, String], default: null },
  preselectedInvoiceId: { type: [Number, String], default: null },
})

const isEdit = computed(() => !!props.creditNote?.id)
const partnerId = ref(isEdit.value ? props.creditNote.partner_id : (props.preselectedPartnerId ?? ''))

function blankLine() {
  return { customer_invoice_line_id: '', description: '', quantity: '1', unit_price: '0', vat_rate: '0' }
}

const form = useForm(isEdit.value ? {
  customer_invoice_id: props.creditNote.customer_invoice_id,
  credit_date:         props.creditNote.credit_date?.toString().slice(0, 10),
  credit_reason:       props.creditNote.credit_reason ?? '',
  internal_notes:      props.creditNote.internal_notes ?? '',
  lines: (props.creditNote.lines ?? []).map(l => ({
    customer_invoice_line_id: l.customer_invoice_line_id,
    description: l.description,
    quantity: l.quantity,
    unit_price: l.unit_price,
    vat_rate: l.vat_rate,
  })),
} : {
  customer_invoice_id: props.preselectedInvoiceId ?? '',
  credit_date: todayLocal(),
  credit_reason: '',
  internal_notes: '',
  lines: [],
})

const eligibleInvoices = ref([])
const invoiceLines = ref([])
const selectedInvoice = ref(isEdit.value ? { amount_outstanding: null } : null)

// Guards against a slow reply for a previous selection overwriting the current one.
let partnerReq = 0
let lineReq = 0

async function onPartnerChange() {
  ++lineReq // any in-flight line fetch belongs to the old party
  form.customer_invoice_id = ''
  form.lines = []
  invoiceLines.value = []
  eligibleInvoices.value = []
  selectedInvoice.value = null
  const seq = ++partnerReq
  if (! partnerId.value) return
  const res = await fetch(route('accounting.credit-notes.eligible-invoices', { partner_id: partnerId.value }), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  if (seq !== partnerReq) return // a newer party was picked while this reply was travelling
  eligibleInvoices.value = data.invoices ?? []
}

async function onInvoiceChange() {
  form.lines = []
  const seq = ++lineReq
  if (! form.customer_invoice_id) { invoiceLines.value = []; return }
  const res = await fetch(route('accounting.credit-notes.invoice-lines', { customer_invoice_id: form.customer_invoice_id }), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  if (seq !== lineReq) return
  invoiceLines.value = data.lines ?? []
  selectedInvoice.value = data.invoice
}

function onInvoiceLineChange(line) {
  const il = invoiceLines.value.find(l => l.id === Number(line.customer_invoice_line_id))
  if (! il) return
  line.description = il.description
  line.unit_price = il.unit_price
  line.vat_rate = il.vat_rate
}

function addLine() {
  form.lines.push(blankLine())
}

function removeLine(idx) {
  form.lines.splice(idx, 1)
}

function lineTotal(line) {
  // exact decimal maths, same rounding as the server (Utils/money.js)
  const sub = lineSubtotal(line.quantity, line.unit_price)
  return sumMoney([sub, lineVat(sub, line.vat_rate)])
}

// Audit L6: exact decimal maths that follows the server's rounding (see Utils/money.js),
// so the preview equals the saved document to the cent.
const subtotal = computed(() => sumMoney(form.lines.map(l => lineSubtotal(l.quantity, l.unit_price))))
const vatTotal  = computed(() => sumMoney(form.lines.map(l => lineVat(lineSubtotal(l.quantity, l.unit_price), l.vat_rate))))
const grandTotal = computed(() => sumMoney([subtotal.value, vatTotal.value]))

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  if (isEdit.value) {
    form.put(route('accounting.credit-notes.update', props.creditNote.id))
  } else {
    form.post(route('accounting.credit-notes.store'))
  }
}

// Edit mode: pre-load this credit note's invoice lines so the picker has options
if (isEdit.value && form.customer_invoice_id) {
  fetch(route('accounting.credit-notes.invoice-lines', { customer_invoice_id: form.customer_invoice_id }), { headers: { Accept: 'application/json' } })
    .then(res => res.json())
    .then(data => { invoiceLines.value = data.lines ?? []; selectedInvoice.value = data.invoice })
}

// Fresh create, arrived via the "Credit Note" button on the Invoices
// Report with both a customer and an invoice already known — fetch
// the eligible-invoices list (so the dropdown has options and shows
// the right one selected) AND the invoice's own lines in one go,
// same as onPartnerChange() + onInvoiceChange() would do if the
// person had clicked through manually. NOT calling onPartnerChange()
// itself — it resets form.customer_invoice_id to '', which would
// wipe out the very value we're trying to preselect.
if (! isEdit.value && props.preselectedPartnerId && props.preselectedInvoiceId) {
  fetch(route('accounting.credit-notes.eligible-invoices', { partner_id: props.preselectedPartnerId }), { headers: { Accept: 'application/json' } })
    .then(res => res.json())
    .then(data => { eligibleInvoices.value = data.invoices ?? [] })
  onInvoiceChange()
}
</script>

<style>
.cn-th-actions { width: 3rem; text-align: end; }
</style>
