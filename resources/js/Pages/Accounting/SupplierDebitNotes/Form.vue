<!--
══════════════════════════════════════════════════════════════════
  Accounting/SupplierDebitNotes/Form.vue

  Create + Edit — a Debit Note is only editable while status =
  draft (see SupplierDebitNoteService header). Posting and cancelling
  both happen from the Index page.

  Picking a posted, still-open Supplier Bill fetches its lines
  (bill-lines) — the user picks which lines this debit note reduces.
  Only Fresh Expense / Accrual Settlement lines are offered — the
  backend (billLines()) already filters out Goods lines, since
  reversing those needs a dedicated inventory design not built yet
  (see SupplierDebitNoteService header). supplier_bill_id, currency,
  and exchange_rate are inherited from the bill and locked once set.

  @layout    AppLayout
  @route     accounting.supplier-debit-notes.create / .edit
  @props     debitNote
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.supplierDebitNotes.form.debitNotes'), href: route('accounting.supplier-debit-notes.index') }, { label: isEdit ? t('common.edit') : t('accounting.supplierDebitNotes.form.new') }]"
      :title="isEdit ? t('accounting.supplierDebitNotes.form.editDebitNote') : t('accounting.supplierDebitNotes.form.newDebitNote')"
      :subtitle="t('accounting.supplierDebitNotes.form.decreasesPartOfAPostedSupplier')"
    />

    <form @submit.prevent="submit">

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.supplierDebitNotes.form.debitNoteInformation') }}</div>
            <div class="form-section-sub">{{ t('accounting.supplierDebitNotes.form.whichBillThisDebitNoteDecreases') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-6-4-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierDebitNotes.form.selectSupplier') }}</label>
              <SelectBox v-model="partnerId" :options="suppliers" value-key="id" :label-fn="s => `${s.code} — ${s.name_en}`" :placeholder="t('accounting.supplierDebitNotes.form.selectSupplier')" :clearable="false" :disabled="isEdit" @change="onPartnerChange" />
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierDebitNotes.form.supplierBill') }}</label>
              <SelectBox v-model="form.supplier_bill_id" :options="eligibleBills" value-key="id" :label-fn="b => `${b.bill_code} — ${t('accounting.supplierDebitNotes.form.outstanding')}: ${fmt(b.amount_outstanding)}`" :placeholder="t('accounting.supplierDebitNotes.form.selectBill')" :clearable="false" :disabled="isEdit || !partnerId" @change="onBillChange" :class="{ 'zv-input-error': form.errors.supplier_bill_id }" />
              <p v-if="form.errors.supplier_bill_id" class="zv-field-error">{{ form.errors.supplier_bill_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.supplierDebitNotes.form.debitDate') }}</label>
              <input v-model="form.debit_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.debit_date }"/>
              <p v-if="form.errors.debit_date" class="zv-field-error">{{ form.errors.debit_date }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-8-4 mt-4">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.supplierDebitNotes.form.reason') }}</label>
              <input v-model="form.debit_reason" type="text" class="zv-input" :placeholder="t('accounting.supplierDebitNotes.form.eGGoodsReturnedOverchargeClaim')"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.supplierDebitNotes.form.supplierCreditNoteReference') }}</label>
              <input v-model="form.supplier_credit_note_reference" type="text" class="zv-input"/>
              <p class="field-hint">{{ t('accounting.supplierDebitNotes.form.supplierCreditNoteReferenceHint') }}</p>
            </div>
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
            <div class="form-section-title">{{ t('accounting.supplierDebitNotes.form.debitedLines') }}</div>
            <div class="form-section-sub">{{ t('accounting.supplierDebitNotes.form.pickWhichBillLinesThisDebit') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div v-if="!form.supplier_bill_id" class="text-sm opacity-50" style="padding: 12px 0;">
            {{ t('accounting.supplierDebitNotes.form.selectASupplierBillAboveTo') }}
          </div>

          <template v-else>
            <div class="zv-alert zv-alert-info mb-3">
              <span class="zv-alert-icon">ℹ</span>
              <span>{{ t('accounting.supplierDebitNotes.form.goodsLinesNotSupportedYet') }}</span>
            </div>

            <div class="zv-table-wrap">
              <table class="zv-table">
                <thead>
                  <tr>
                    <th>{{ t('accounting.supplierDebitNotes.form.billLine') }}</th>
                    <th class="zv-table-num">{{ t('accounting.supplierDebitNotes.form.qty') }}</th>
                    <th class="zv-table-num">{{ t('accounting.supplierDebitNotes.form.unitPrice') }}</th>
                    <th class="zv-table-num">{{ t('accounting.supplierDebitNotes.form.vAT') }}</th>
                    <th class="zv-table-num">{{ t('accounting.supplierDebitNotes.form.lineTotal') }}</th>
                    <th class="sdn-th-actions"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(line, idx) in form.lines" :key="idx">
                    <td>
                      <SelectBox v-model="line.supplier_bill_line_id" :options="billLines" value-key="id" :label-fn="bl => bl.description" :placeholder="t('accounting.supplierDebitNotes.form.selectLine')" :clearable="false" size="sm" @change="onBillLineChange(line)" />
                    </td>
                    <td><input v-model="line.quantity" type="number" step="0.0001" min="0.0001" class="zv-input text-end" style="width:6rem;"/></td>
                    <td><input v-model="line.unit_price" type="number" step="0.01" min="0" class="zv-input text-end" style="width:8rem;"/></td>
                    <td><input v-model="line.vat_rate" type="number" step="0.01" min="0" max="100" class="zv-input text-end" style="width:5rem;"/></td>
                    <td class="zv-table-num">{{ fmt(lineTotal(line)) }}</td>
                    <td class="sdn-th-actions">
                      <button type="button" class="zv-btn-remove-row" :title="t('accounting.supplierDebitNotes.form.removeLine')" @click="removeLine(idx)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
              {{ t('accounting.supplierDebitNotes.form.addLine') }}
            </button>
          </template>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="sb-totals">
            <div><span>{{ t('accounting.supplierDebitNotes.form.subtotal') }}</span><strong>{{ fmt(subtotal) }}</strong></div>
            <div><span>{{ t('accounting.supplierDebitNotes.form.vAT2') }}</span><strong>{{ fmt(vatTotal) }}</strong></div>
            <div class="sb-totals-grand"><span>{{ t('accounting.supplierDebitNotes.form.debitTotal') }}</span><strong>{{ fmt(grandTotal) }}</strong></div>
          </div>
          <p v-if="selectedBill && grandTotal > Number(selectedBill.amount_outstanding)" class="zv-field-error mt-2">
            {{ t('accounting.supplierDebitNotes.form.thisExceedsWhatSStillOutstandingOn') }}
          </p>
          <div class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.supplierDebitNotes.form.internalNotes') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.supplier-debit-notes.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.supplierDebitNotes.form.saveAsDraft')) }}
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
  debitNote: { type: Object, default: null },
  suppliers: { type: Array,  default: () => [] },
  preselectedPartnerId: { type: [Number, String], default: null },
  preselectedBillId:    { type: [Number, String], default: null },
})

const isEdit = computed(() => !!props.debitNote?.id)
const partnerId = ref(isEdit.value ? props.debitNote.partner_id : (props.preselectedPartnerId ?? ''))

function blankLine() {
  return { supplier_bill_line_id: '', description: '', quantity: '1', unit_price: '0', vat_rate: '0' }
}

const form = useForm(isEdit.value ? {
  supplier_bill_id:                props.debitNote.supplier_bill_id,
  debit_date:                      props.debitNote.debit_date?.toString().slice(0, 10),
  debit_reason:                    props.debitNote.debit_reason ?? '',
  supplier_credit_note_reference:  props.debitNote.supplier_credit_note_reference ?? '',
  internal_notes:                  props.debitNote.internal_notes ?? '',
  lines: (props.debitNote.lines ?? []).map(l => ({
    supplier_bill_line_id: l.supplier_bill_line_id,
    description: l.description,
    quantity: l.quantity,
    unit_price: l.unit_price,
    vat_rate: l.vat_rate,
  })),
} : {
  supplier_bill_id: props.preselectedBillId ?? '',
  debit_date: todayLocal(),
  debit_reason: '',
  supplier_credit_note_reference: '',
  internal_notes: '',
  lines: [],
})

const eligibleBills = ref([])
const billLines = ref([])
const selectedBill = ref(isEdit.value ? { amount_outstanding: null } : null)

// Guards against a slow reply for a previous selection overwriting the current one.
let partnerReq = 0
let lineReq = 0

async function onPartnerChange() {
  ++lineReq // any in-flight line fetch belongs to the old party
  form.supplier_bill_id = ''
  form.lines = []
  billLines.value = []
  eligibleBills.value = []
  selectedBill.value = null
  const seq = ++partnerReq
  if (! partnerId.value) return
  const res = await fetch(route('accounting.supplier-debit-notes.eligible-bills', { partner_id: partnerId.value }), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  if (seq !== partnerReq) return // a newer party was picked while this reply was travelling
  eligibleBills.value = data.bills ?? []
}

async function onBillChange() {
  form.lines = []
  const seq = ++lineReq
  if (! form.supplier_bill_id) { billLines.value = []; return }
  const res = await fetch(route('accounting.supplier-debit-notes.bill-lines', { supplier_bill_id: form.supplier_bill_id }), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  if (seq !== lineReq) return
  billLines.value = data.lines ?? []
  selectedBill.value = data.bill
}

function onBillLineChange(line) {
  const bl = billLines.value.find(l => l.id === Number(line.supplier_bill_line_id))
  if (! bl) return
  line.description = bl.description
  line.unit_price = bl.unit_price
  line.vat_rate = bl.vat_rate
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
    form.put(route('accounting.supplier-debit-notes.update', props.debitNote.id))
  } else {
    form.post(route('accounting.supplier-debit-notes.store'))
  }
}

// Edit mode: pre-load this debit note's bill lines so the picker has options
if (isEdit.value && form.supplier_bill_id) {
  fetch(route('accounting.supplier-debit-notes.bill-lines', { supplier_bill_id: form.supplier_bill_id }), { headers: { Accept: 'application/json' } })
    .then(res => res.json())
    .then(data => { billLines.value = data.lines ?? []; selectedBill.value = data.bill })
}

// Fresh create, arrived via the "Debit Note" button on the Invoices
// Report — same reasoning as SupplierCreditNotes/Form.vue's
// equivalent block: NOT calling onPartnerChange() itself, since it
// resets form.supplier_bill_id to '', wiping out the preselected value.
if (! isEdit.value && props.preselectedPartnerId && props.preselectedBillId) {
  fetch(route('accounting.supplier-debit-notes.eligible-bills', { partner_id: props.preselectedPartnerId }), { headers: { Accept: 'application/json' } })
    .then(res => res.json())
    .then(data => { eligibleBills.value = data.bills ?? [] })
  onBillChange()
}
</script>

<style>
.sdn-th-actions { width: 3rem; text-align: end; }
</style>
