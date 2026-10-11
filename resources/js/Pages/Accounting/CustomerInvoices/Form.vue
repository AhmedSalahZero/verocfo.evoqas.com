<!--
══════════════════════════════════════════════════════════════════
  Accounting/CustomerInvoices/Form.vue

  Create + Edit — an invoice is only editable while status = draft
  (see CustomerInvoiceService header). Posting and cancelling both
  happen from the Index page; a posted invoice is corrected via a
  Credit Note instead of being reopened here.

  Picking a Sales Order fetches every one of its lines, each
  annotated with how much is already invoiced (eligible-so-lines) —
  the user adds whichever lines (or partial quantities) this invoice
  covers. Revenue account defaults from the SO line but is
  overridable per invoice line.

  Audit H18: a service line can carry a service period — when it runs
  beyond the invoice month the revenue is spread month by month
  (see RevenueScheduleService).

  @layout    AppLayout
  @route     accounting.customer-invoices.create / .edit
  @props     customerInvoice, customers, currencies,
             functionalCurrencyId, revenueAccounts, costCenters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.customerInvoices.form.customerInvoices'), href: route('accounting.customer-invoices.index') }, { label: isEdit ? customerInvoice.invoice_code : t('accounting.customerInvoices.form.new') }]"
      :title="isEdit ? customerInvoice.invoice_code : t('accounting.customerInvoices.form.newCustomerInvoice')"
      :subtitle="t('accounting.customerInvoices.form.oncePostedThisRecognizesRevenueAnd')"
    />

    <!-- Audit C7: invoice created from Opening Balances -->
    <div v-if="isEdit && customerInvoice.is_opening_balance" class="zv-alert zv-alert-warning mb-4">
      {{ t('accounting.customerInvoices.form.openingBalanceNotice') }} {{ customerInvoice.internal_notes }}
    </div>

    <!-- Status context banner — same pattern already proven on Customer Collection. -->
    <div v-if="isEdit && customerInvoice.status === 'posted' && !customerInvoice.is_opening_balance" class="zv-alert zv-alert-info mb-4">
      <span v-if="!periodClosed">{{ t('accounting.customerInvoices.form.thisInvoiceIsPostedAndRead') }}</span>
      <span v-else>{{ t('accounting.customerInvoices.form.thisInvoiceIsPostedAndIts') }}</span>
    </div>
    <div v-else-if="isEdit && customerInvoice.status === 'cancelled'" class="zv-alert zv-alert-danger mb-4">
      {{ t('accounting.customerInvoices.form.thisInvoiceWasCancelledItCannot') }}
    </div>

    <form @submit.prevent="submit">

      <fieldset :disabled="isReadOnly" style="border: 0; padding: 0; margin: 0;">

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.customerInvoices.form.invoiceInformation') }}</div>
            <div class="form-section-sub">{{ t('accounting.customerInvoices.form.whichSalesOrderThisInvoiceBills') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-6-4-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.customer') }}</label>
              <SelectBox v-model="form.partner_id" :options="customers" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.customerInvoices.form.selectCustomer')" :clearable="false" :disabled="isEdit" @change="onPartnerChange" :class="{ 'zv-input-error': form.errors.partner_id }" />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.customerInvoices.form.salesOrder') }}</label>
              <SelectBox v-model="form.sales_order_id" :options="eligibleSalesOrders" value-key="id" :label-fn="so => `${so.so_code} — ${t('accounting.customerInvoices.form.remaining')}: ${fmt(so.so_value - so.invoiced_amount)}`" :placeholder="t('accounting.customerInvoices.form.selectSalesOrder')" :clearable="true" :disabled="isEdit || !form.partner_id" @change="onSoChange" :class="{ 'zv-input-error': form.errors.sales_order_id }" />
              <p v-if="form.errors.sales_order_id" class="zv-field-error">{{ form.errors.sales_order_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.customerInvoices.form.invoiceDate') }}</label>
              <input v-model="form.invoice_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.invoice_date }"/>
              <p v-if="form.errors.invoice_date" class="zv-field-error">{{ form.errors.invoice_date }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-2-narrow mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.customerInvoices.form.currency') }}</label>
              <SelectBox v-model="form.currency_id" :options="currencies" value-key="id" :label-fn="c => c.code" @change="onCurrencyChange" />
            </div>
            <div class="field-group" v-if="isForeignCurrency">
              <label class="zv-label zv-label-required">{{ t('accounting.customerInvoices.form.exchangeRate') }}</label>
              <input v-model="form.exchange_rate" type="number" step="0.000001" min="0" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.exchange_rate }" :placeholder="t('accounting.customerInvoices.form.exchangeRateAuto')"/>
              <p v-if="form.errors.exchange_rate" class="zv-field-error">{{ form.errors.exchange_rate }}</p>
              <p class="field-hint">{{ t('accounting.customerInvoices.form.exchangeRateHint') }}</p>
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
            <div class="form-section-title">{{ t('accounting.customerInvoices.form.invoiceLines') }}</div>
            <div class="form-section-sub">{{ t('accounting.customerInvoices.form.pickWhichSalesOrderLinesOr') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <p v-if="!form.sales_order_id" class="field-hint" style="margin-bottom: 12px;">
            {{ t('accounting.customerInvoices.form.serviceInvoiceHint') }}
          </p>

          <div v-if="form.lines.length" class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ form.sales_order_id ? t('accounting.customerInvoices.form.sOLine') : t('accounting.customerInvoices.form.description') }}</th>
                  <th class="zv-table-num">{{ t('accounting.customerInvoices.form.qty') }}</th>
                  <th class="zv-table-num">{{ t('accounting.customerInvoices.form.unitPrice') }}</th>
                  <th class="zv-table-num">{{ t('accounting.customerInvoices.form.vAT') }}</th>
                  <th>{{ t('accounting.customerInvoices.form.revenueAccount') }}</th>
                  <th class="zv-table-num">{{ t('accounting.customerInvoices.form.lineTotal') }}</th>
                  <th class="ci-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <template v-for="(line, idx) in form.lines" :key="idx">
                <tr>
                  <td>
                    <!-- The only option in Accounting whose label carried
                         markup: a <span v-if> appended the delivered
                         quantity for stock lines. A label is a string, so
                         the conditional moved into the expression itself
                         and renders the same two variants it always did. -->
                    <SelectBox
                      v-if="form.sales_order_id"
                      v-model="line.sales_order_line_id"
                      :options="soLines"
                      value-key="id"
                      :label-fn="sl => `${sl.description} (${t('accounting.customerInvoices.form.invoiced')} ${sl.invoiced_quantity}/${sl.quantity}${sl.has_stock ? `, ${t('accounting.customerInvoices.form.delivered')} ${sl.delivered_quantity}` : ''})`"
                      :placeholder="t('accounting.customerInvoices.form.selectLine')"
                      :clearable="false"
                      size="sm"
                      @change="onSoLineChange(line)"
                    />
                    <input v-else v-model="line.description" type="text" class="zv-input" :disabled="isReadOnly" />
                  </td>
                  <td><input v-model="line.quantity" type="number" step="0.0001" min="0.0001" class="zv-input text-end" style="width:6rem;"/></td>
                  <td><input v-model="line.unit_price" type="number" step="0.01" min="0" class="zv-input text-end" style="width:8rem;"/></td>
                  <td><input v-model="line.vat_rate" type="number" step="0.01" min="0" max="100" class="zv-input text-end" style="width:5rem;"/></td>
                  <td>
                    <SelectBox v-model="line.revenue_account_id" :options="revenueAccounts" value-key="id" :label-fn="a => `${a.code} — ${a.name_en}`" size="sm" />
                  </td>
                  <td class="zv-table-num">{{ fmt(lineTotal(line)) }}</td>
                  <td class="ci-th-actions">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.customerInvoices.form.removeLine')" @click="removeLine(idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
                <!-- Audit H18: service lines only — a contract invoiced for more than this month -->
                <tr v-if="isServiceLine(line)" class="ci-period-row">
                  <td colspan="7">
                    <div class="ci-period">
                      <span class="ci-period-label">{{ t('accounting.revenueSchedules.servicePeriod') }}</span>
                      <label>{{ t('accounting.revenueSchedules.from') }} <input v-model="line.service_period_start" type="date" class="zv-input" :disabled="isReadOnly"/></label>
                      <label>{{ t('accounting.revenueSchedules.to') }} <input v-model="line.service_period_end" type="date" class="zv-input" :disabled="isReadOnly"/></label>
                      <span class="field-hint">{{ t('accounting.revenueSchedules.servicePeriodHint') }}</span>
                    </div>
                  </td>
                </tr>
                </template>
              </tbody>
            </table>
          </div>

          <button v-if="!isReadOnly" type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('accounting.customerInvoices.form.addLine') }}
          </button>
          <p v-if="form.errors.lines" class="zv-field-error mt-2">{{ form.errors.lines }}</p>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="ci-totals-notes-row">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.customerInvoices.form.internalNotes') }}</label>
              <textarea v-model="form.internal_notes" rows="3" class="zv-textarea"></textarea>
            </div>
            <div class="sb-totals">
              <div><span>{{ t('accounting.customerInvoices.form.subtotal') }}</span><strong>{{ fmt(subtotal) }} {{ selectedCurrencyCode }}</strong></div>
              <div><span>{{ t('accounting.customerInvoices.form.vAT2') }}</span><strong>{{ fmt(vatTotal) }} {{ selectedCurrencyCode }}</strong></div>
              <div class="sb-totals-grand"><span>{{ t('accounting.customerInvoices.form.invoiceTotal') }}</span><strong>{{ fmt(grandTotal) }} {{ selectedCurrencyCode }}</strong></div>
            </div>
          </div>
        </div>
      </div>

      </fieldset>

      <!-- ══════════════════ Linked Collections (edit mode, posted or reopened) ══════════════════
           Split exactly like Customer Collection's own "Applied To
           Invoices" section, mirrored from the other direction: a
           link that shares the collection's own original journal
           entry can't be unlinked here (would mean reversing the
           WHOLE collection) — only Set to Draft on the collection
           itself does that. A link with its own separate journal
           entry (a later "Apply") can be unlinked right here. -->
      <div v-if="isEdit && customerInvoice.allocations?.length" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.customerInvoices.form.linkedCollections') }}</div>
          <div class="form-section-sub">{{ t('accounting.customerInvoices.form.moneyAlreadyMatchedAgainstThisInvoice') }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.customerInvoices.form.collection') }}</th><th class="zv-table-num">{{ t('accounting.customerInvoices.form.appliedAmount') }}</th><th class="ci-linked-actions"></th></tr>
            </thead>
            <tbody>
              <tr v-for="alloc in customerInvoice.allocations" :key="alloc.id">
                <td><span class="zv-code">{{ alloc.customer_payment?.payment_code }}</span></td>
                <td class="zv-table-num">{{ fmt(alloc.allocated_amount) }}</td>
                <td class="ci-linked-actions">
                  <button
                    v-if="alloc.journal_entry_id !== alloc.customer_payment?.journal_entry_id"
                    type="button" class="zv-btn zv-btn-danger zv-btn-sm" :disabled="unallocating === alloc.id"
                    @click="confirmUnallocate(alloc)"
                  >{{ unallocating === alloc.id ? t('accounting.customerInvoices.form.removing') : t('accounting.customerInvoices.form.unlink') }}</button>
                  <span v-else class="field-hint">{{ t('accounting.customerInvoices.form.partOfThatCollectionSOriginalPosting') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════ Other Unapplied Collections — link from here ══════════════════
           Confirmed with Mahmoud: the invoice's own page should be
           able to pull a collection onto itself, not only the other
           way around. Same allocate() action Customer Collection's
           own "Apply" button already calls — this is just a different
           starting point for the exact same match. Never moves cash —
           purely which invoice the already-received money is
           credited against. -->
      <div v-if="isEdit && customerInvoice.status === 'posted' && unappliedCollections.length" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.customerInvoices.form.otherUnappliedCollections') }}</div>
          <div class="form-section-sub">{{ t('accounting.customerInvoices.form.thisCustomerSOtherCollectionsThatStill') }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.customerInvoices.form.collection') }}</th><th>{{ t('accounting.customerInvoices.form.date') }}</th><th class="zv-table-num">{{ t('accounting.customerInvoices.form.unapplied') }}</th><th class="zv-table-num">{{ t('accounting.customerInvoices.form.amountToLink') }}</th><th class="ci-linked-actions"></th></tr>
            </thead>
            <tbody>
              <tr v-for="pmt in unappliedCollections" :key="pmt.id">
                <td><span class="zv-code">{{ pmt.payment_code }}</span></td>
                <td>{{ pmt.payment_date }}</td>
                <td class="zv-table-num">{{ fmt(pmt.unallocated_amount) }} {{ pmt.receive_currency?.code }}</td>
                <td class="zv-table-num">
                  <input v-model="linkAmounts[pmt.id]" type="number" step="0.01" min="0" :max="pmt.unallocated_amount" class="zv-input text-end" style="width: 8rem;" placeholder="0.00"/>
                </td>
                <td class="ci-linked-actions">
                  <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" :disabled="linking === pmt.id" @click="submitLink(pmt)">
                    {{ linking === pmt.id ? t('accounting.customerInvoices.form.linking') : t('accounting.customerInvoices.form.link') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="linkError" class="zv-field-error mt-2">{{ linkError }}</p>
        </div>
      </div>

      <!-- ══════════════════ Journal Entry (read-only, edit mode only) ══════════════════ -->
      <div v-if="isEdit && customerInvoice.journal_entry" class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-title">{{ t('accounting.customerInvoices.form.journalEntry') }} — {{ customerInvoice.journal_entry.entry_number }}</div>
        </div>
        <div class="form-section-body">
          <table class="zv-table">
            <thead>
              <tr><th>{{ t('accounting.customerInvoices.form.account') }}</th><th class="zv-table-num">{{ t('accounting.customerInvoices.form.debit') }}</th><th class="zv-table-num">{{ t('accounting.customerInvoices.form.credit') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="line in customerInvoice.journal_entry.lines" :key="line.id">
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
          <Link :href="route('accounting.customer-invoices.index')" class="zv-btn zv-btn-ghost">{{ t('accounting.customerInvoices.form.close') }}</Link>

          <!-- Posted, period open → the reopen action -->
          <button
            v-if="isEdit && customerInvoice.status === 'posted' && !periodClosed"
            type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
          >{{ t('accounting.customerInvoices.form.setToDraft') }}</button>

          <!-- Draft → Save (covers both a brand-new draft and a reopened one) -->
          <button
            v-if="!isEdit || customerInvoice.status === 'draft'"
            type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary"
          >{{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.customerInvoices.form.saveAsDraft')) }}</button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sum as sumMoney, lineSubtotal, lineVat } from '@/Utils/money'
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  customerInvoice:       { type: Object, default: null },
  customers:             { type: Array,  default: () => [] },
  currencies:            { type: Array,  default: () => [] },
  functionalCurrencyId:  { type: [Number, String], default: null },
  revenueAccounts:       { type: Array,  default: () => [] },
  costCenters:           { type: Array,  default: () => [] },
  periodClosed:          { type: Boolean, default: false },
})

const isEdit = computed(() => !!props.customerInvoice?.id)

// A posted invoice is read-only until reopened via "Set to Draft" —
// same rule already proven on Customer Collection/Supplier Payment.
// A draft (whether brand-new or reopened) is always fully editable.
const isReadOnly = computed(() => isEdit.value && props.customerInvoice.status !== 'draft')

function blankLine() {
  return { sales_order_line_id: '', product_id: null, description: '', quantity: '1', unit_price: '0', vat_rate: '0', revenue_account_id: '', service_period_start: '', service_period_end: '' }
}

const form = useForm(isEdit.value ? {
  sales_order_id:  props.customerInvoice.sales_order_id,
  partner_id:      props.customerInvoice.partner_id,
  invoice_date:    props.customerInvoice.invoice_date?.toString().slice(0, 10),
  currency_id:     props.customerInvoice.currency_id,
  exchange_rate:   props.customerInvoice.exchange_rate,
  payment_term_id: props.customerInvoice.payment_term_id ?? '',
  internal_notes:  props.customerInvoice.internal_notes ?? '',
  lines: (props.customerInvoice.lines ?? []).map(l => ({
    sales_order_line_id: l.sales_order_line_id,
    product_id: l.product_id,
    description: l.description,
    quantity: l.quantity,
    unit_price: l.unit_price,
    vat_rate: l.vat_rate,
    revenue_account_id: l.revenue_account_id,
    service_period_start: l.service_period_start ? String(l.service_period_start).slice(0, 10) : '',
    service_period_end: l.service_period_end ? String(l.service_period_end).slice(0, 10) : '',
  })),
} : {
  sales_order_id: '',
  partner_id: '',
  invoice_date: todayLocal(),
  currency_id: props.functionalCurrencyId ?? '',
  exchange_rate: '1',
  payment_term_id: '',
  internal_notes: '',
  lines: [],
})

const isForeignCurrency = computed(() => !!form.currency_id && String(form.currency_id) !== String(props.functionalCurrencyId))
const selectedCurrencyCode = computed(() => props.currencies.find(c => c.id === Number(form.currency_id))?.code ?? '')

const eligibleSalesOrders = ref([])
const soLines = ref([])

// Only the answer to the LATEST request may fill a list. Without this, a slow
// reply for the customer (or order) picked a moment ago arrived last and
// replaced the list of the one picked now — leaving a Sales Order of the
// wrong customer on screen.
let soListReq = 0
let soLinesReq = 0

async function onPartnerChange() {
  const myReq = ++soListReq
  soLinesReq++                       // also cancels any pending lines reply
  form.sales_order_id = ''
  form.lines = []
  soLines.value = []
  eligibleSalesOrders.value = []
  if (! form.partner_id) return
  const res = await fetch(route('accounting.customer-invoices.eligible-sales-orders', { partner_id: form.partner_id }), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  if (myReq !== soListReq) return
  eligibleSalesOrders.value = data.orders ?? []
}

async function onSoChange() {
  const myReq = ++soLinesReq
  form.lines = []
  soLines.value = []
  if (! form.sales_order_id) return
  const res = await fetch(route('accounting.customer-invoices.eligible-so-lines', { sales_order_id: form.sales_order_id }), { headers: { Accept: 'application/json' } })
  const data = await res.json()
  if (myReq !== soLinesReq) return
  soLines.value = data.lines ?? []
}

function onSoLineChange(line) {
  const sl = soLines.value.find(s => s.id === Number(line.sales_order_line_id))
  if (! sl) return
  line.product_id = sl.product_id
  line.description = sl.description
  line.unit_price = sl.unit_price
  line.revenue_account_id = sl.revenue_account_id
  // VAT comes from Tax Settings (product → category → company default),
  // resolved by the server. Before, it always started at 0%.
  if (sl.vat_rate !== undefined && sl.vat_rate !== null) line.vat_rate = String(sl.vat_rate)
}

// Audit H18: the service-period row shows for service (non-stock) lines,
// or whenever a period was already entered.
function isServiceLine(line) {
  if (line.service_period_start || line.service_period_end) return true
  const sl = soLines.value.find(s => s.id === Number(line.sales_order_line_id))
  return !!sl && !sl.has_stock
}

function addLine() {
  form.lines.push(blankLine())
}

function removeLine(idx) {
  form.lines.splice(idx, 1)
}

// A foreign currency must NOT start at "1": the server treats any typed rate
// above 0 as the user's own rate, so the pre-filled 1 booked USD invoices at
// 1 EGP. Left empty, the server uses the rate table for the invoice date (or
// refuses with a clear "add the rate in Settings › Currencies" message).
async function onCurrencyChange() {
  form.exchange_rate = isForeignCurrency.value ? '' : '1'
}

function lineTotal(line) {
  // Same exact rounding as the totals below and the server (Utils/money.js).
  const sub = lineSubtotal(line.quantity, line.unit_price)
  return Number(sumMoney([sub, lineVat(sub, line.vat_rate)]))
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
    form.put(route('accounting.customer-invoices.update', props.customerInvoice.id))
  } else {
    form.post(route('accounting.customer-invoices.store'))
  }
}

// Fix (found via Mahmoud's report): eligibleSalesOrders only ever got
// filled in by onPartnerChange() — the Customer field's own @change
// handler — which never fires just from opening an existing invoice.
// The Sales Order dropdown had no matching <option> for the invoice's
// own value, so it silently rendered blank in both edit and read-only
// view. Seeded directly from the already-loaded invoice — no extra
// request needed, and this also avoids a real edge case: the
// eligible-sales-orders endpoint deliberately excludes an SO that's
// already fully invoiced, which this invoice's own posting may well
// have been the one to cause.
if (isEdit.value && props.customerInvoice.sales_order) {
  eligibleSalesOrders.value = [props.customerInvoice.sales_order]
}

// Edit mode: pre-load this invoice's SO lines so the picker has options
if (isEdit.value && form.sales_order_id) {
  fetch(route('accounting.customer-invoices.eligible-so-lines', { sales_order_id: form.sales_order_id }), { headers: { Accept: 'application/json' } })
    .then(res => res.json())
    .then(data => { soLines.value = data.lines ?? [] })
}

// ── Set to Draft ───────────────────────────────────────────────────

function confirmSetToDraft() {
  if (! confirm(t('accounting.customerInvoices.form.reopenThisInvoiceForEditingThis'))) return

  // Same fix already proven on Customer Collection: the server
  // redirects back to this exact edit URL, and Inertia landing on a
  // page it's already displaying updates props in place instead of
  // re-running this component's setup — forcing a full reload here
  // guarantees everything (SO lines, linked collections) re-fetches
  // correctly against the now-reversed state.
  // Fix (real bug, confirmed by testing): onSuccess used to fire — and
  // reload unconditionally — even when the server correctly REJECTED
  // this (e.g. this invoice is linked to a collection's original
  // posting, and must be reopened from that collection instead). A
  // rejected request still redirects back successfully in Inertia's
  // eyes, so onSuccess fired anyway; the forced reload right after
  // then wiped out the one-time flash error before it was ever seen —
  // looking exactly like "nothing happened," with no visible reason
  // why. Only reload when the response genuinely carries no error.
  // Audit H5: a written reason is required — and a second person must approve.
  const reason = window.prompt(t('accounting.reopen.reasonPromptApproval'))
  if (reason === null) return
  if (reason.trim().length < 10) { window.alert(t('accounting.reopen.reasonTooShort')); return }
  router.patch(route('accounting.customer-invoices.set-to-draft', props.customerInvoice.id), { reason: reason.trim() }, {
    preserveScroll: true, // Audit H5: stays posted until a second person approves
  })
}

// ── Linked Collections — Unlink ──────────────────────────────────

const unallocating = ref(null)
function confirmUnallocate(alloc) {
  if (! confirm(t('accounting.customerInvoices.form.unlinkThisCollectionFromThisInvoice'))) return
  unallocating.value = alloc.id
  router.delete(route('accounting.customer-payment-allocations.unallocate', alloc.id), {
    preserveScroll: true,
    onFinish: () => { unallocating.value = null },
  })
}

// ── Other Unapplied Collections — link from here ──────────────────

const unappliedCollections = ref([])
const linkAmounts = ref({})
const linking = ref(null)
const linkError = ref('')

async function fetchUnappliedCollections() {
  if (! props.customerInvoice?.partner_id) return
  const res = await fetch(route('accounting.customer-invoices.unapplied-collections', { partner_id: props.customerInvoice.partner_id }), {
    headers: { Accept: 'application/json' }, cache: 'no-store',
  })
  const data = await res.json()
  unappliedCollections.value = data.payments ?? []
}

if (isEdit.value && props.customerInvoice.status === 'posted') {
  fetchUnappliedCollections()
}

function submitLink(pmt) {
  const amount = linkAmounts.value[pmt.id]
  if (! amount || Number(amount) <= 0) return

  linkError.value = ''
  linking.value = pmt.id

  fetch(route('accounting.customer-payments.allocate', pmt.id), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
      'Accept': 'application/json',
    },
    body: JSON.stringify({ customer_invoice_id: props.customerInvoice.id, amount }),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { linkError.value = data.message || t('accounting.customerInvoices.form.somethingWentWrongPleaseTryAgain'); return }
      router.reload({ preserveScroll: true })
    })
    .catch(() => { linkError.value = t('accounting.customerInvoices.form.somethingWentWrongPleaseTryAgain') })
    .finally(() => { linking.value = null })
}
</script>

<style>
.ci-th-actions { width: 3rem; text-align: end; }

/* Fix: Linked Collections / Other Unapplied Collections action columns
   were reusing .ci-th-actions (3rem — sized for a tiny icon-only
   remove button in Invoice Lines), crushing a full sentence-length
   hint into a ~48px-wide strip and wrapping it across 5+ lines with a
   huge gap of unused space beside it. This column needs real room —
   for either a button or an explanatory sentence. */
.ci-linked-actions { width: 18rem; text-align: end; }
.ci-linked-actions .field-hint { text-align: end; display: block; margin: 0; }

/* Fix: this page used .sb-totals (Supplier Bill's class name) without
   ever actually defining it here — the whole totals block has been
   rendering completely unstyled (plain stacked divs, no alignment) —
   this is the first time it's had real CSS on this page.
   Also fixes the actual design request: Notes now sits beside the
   totals instead of stacked underneath it, using the wide open space
   next to it rather than wrapping into a cramped, narrow column. */
.ci-totals-notes-row { display: flex; gap: 2rem; align-items: flex-start; }
.ci-totals-notes-row .field-group { flex: 1; }
.sb-totals { display: flex; flex-direction: column; gap: .5rem; align-items: flex-end; flex-shrink: 0; padding-top: 1.6rem; }
.sb-totals > div { display: flex; gap: 1.5rem; font-size: .9rem; min-width: 16rem; justify-content: space-between; }
.sb-totals-grand { padding-top: .5rem; border-top: 1px solid var(--zv-border, #e2e8f0); font-size: 1.05rem; }

@media (max-width: 900px) {
  .ci-totals-notes-row { flex-direction: column; }
  .sb-totals { align-items: stretch; padding-top: 0; }
  .sb-totals > div { min-width: 0; }
}
.ci-period-row td { padding-top: 0; border-top: none; }
.ci-period { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 12px; }
.ci-period-label { font-weight: 600; }
.ci-period input { width: 10rem; display: inline-block; margin-inline-start: 4px; }
</style>
