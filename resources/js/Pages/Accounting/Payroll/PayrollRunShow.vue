<!--
══════════════════════════════════════════════════════════════════
  Accounting/Payroll/PayrollRunShow.vue

  The core Payroll screen. One row per employee (PayrollRunLine) —
  clicking a row opens a modal to add/remove addition & deduction
  line items and enter the manual tax/Social Insurance/advance-
  recovery figures (PayrollRunService header explains why those
  three are manual input in this first slice, not calculated).

  Editing (line items, manual amounts) is only possible while the
  run is still draft — once posted, everything here is read-only
  except Set to Draft, which reverses everything (see
  PayrollRunService::setToDraft()).

  @layout    AppLayout
  @route     hr.payroll.show
  @props     run, deductionReasons
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="periodLabel"
      :subtitle="t('accounting.hr.payrollRunShow.runDate') + ': ' + String(run.run_date).slice(0, 10)"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/>'
    >
      <template #actions>
        <button v-if="run.status === 'draft'" type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="confirmPost">
          {{ t('accounting.hr.payrollRunShow.postRun') }}
        </button>
        <template v-else-if="run.status === 'posted'">
          <button type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft">{{ t('accounting.hr.payrollRunShow.setToDraft') }}</button>
          <StatusBadge :status="run.status" />
        </template>
        <StatusBadge v-else :status="run.status" />
      </template>
    </PageHeader>

    <!-- M-36: pay the net salaries -->
    <div v-if="run.status === 'posted'" class="form-section mb-4">
      <div class="form-section-body">
        <template v-if="livePayment">
          <p>Net pay paid on <strong>{{ String(livePayment.payment_date).slice(0, 10) }}</strong> — {{ fmt(livePayment.amount) }}</p>
          <button type="button" class="zv-btn zv-btn-secondary mt-2" @click="reversePayment">Reverse payment</button>
        </template>
        <template v-else>
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label">Pay from bank account</label>
              <select v-model="payForm.bank_account_id" class="zv-input">
                <option value="">—</option>
                <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option>
              </select>
            </div>
            <div class="field-group">
              <label class="zv-label">Payment date</label>
              <input v-model="payForm.payment_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group" style="align-self:end">
              <button type="button" class="zv-btn zv-btn-primary" :disabled="! payForm.bank_account_id || payForm.processing" @click="payNet">Pay net salaries</button>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Summary strip -->
    <div class="pr-summary-grid mb-4">
      <div class="pr-summary-card"><div class="pr-summary-label">{{ t('accounting.hr.payrollRunShow.employees') }}</div><div class="pr-summary-value">{{ run.lines.length }}</div></div>
      <div class="pr-summary-card"><div class="pr-summary-label">{{ t('accounting.hr.payrollRunShow.grossPay') }}</div><div class="pr-summary-value">{{ fmt(totals.gross) }}</div></div>
      <div class="pr-summary-card"><div class="pr-summary-label">{{ t('accounting.hr.payrollRunShow.tax') }}</div><div class="pr-summary-value">{{ fmt(totals.tax) }}</div></div>
      <div class="pr-summary-card"><div class="pr-summary-label">{{ t('accounting.hr.payrollRunShow.socialInsurance') }}</div><div class="pr-summary-value">{{ fmt(totals.si) }}</div></div>
      <div class="pr-summary-card"><div class="pr-summary-label">{{ t('accounting.hr.payrollRunShow.netPay') }}</div><div class="pr-summary-value pr-summary-highlight">{{ fmt(totals.net) }}</div></div>
    </div>

    <div class="zv-card">
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.payrollRunShow.employee') }}</th>
              <th>{{ t('accounting.hr.payrollRunShow.category') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.payrollRunShow.baseSalary') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.payrollRunShow.grossPay') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.payrollRunShow.tax') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.payrollRunShow.socialInsurance') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.payrollRunShow.advance') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.payrollRunShow.netPay') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in run.lines" :key="l.id">
              <td class="font-semibold">{{ l.partner?.name_en }}</td>
              <td><span class="zv-tag" :class="categoryTagClass(l.expense_category)">{{ categoryLabel(l.expense_category) }}</span></td>
              <td class="zv-table-num">{{ fmt(l.base_salary) }}</td>
              <td class="zv-table-num">{{ fmt(l.gross_pay) }}</td>
              <td class="zv-table-num">{{ fmt(l.tax_amount) }}</td>
              <td class="zv-table-num">{{ fmt(l.social_insurance_employee_amount) }}</td>
              <td class="zv-table-num">{{ fmt(l.advance_deduction_amount) }}</td>
              <td class="zv-table-num font-semibold">{{ fmt(l.net_pay) }}</td>
              <td class="zv-col-actions">
                <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="openLine(l)">
                  {{ run.status === 'draft' ? t('common.edit') : t('common.view') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Postings — visible once posted -->
    <div v-if="run.postings?.length" class="zv-card mt-4">
      <div class="pr-postings-title">{{ t('accounting.hr.payrollRunShow.journalEntriesPosted') }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead><tr><th>{{ t('accounting.hr.payrollRunShow.category') }}</th><th>{{ t('accounting.hr.payrollRunShow.journalEntry') }}</th></tr></thead>
          <tbody>
            <tr v-for="p in run.postings" :key="p.id">
              <td>{{ categoryLabel(p.category) }}</td>
              <td>{{ p.journal_entry?.entry_number ?? ('#' + p.journal_entry_id) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Line modal -->
    <div v-if="activeLine" class="pr-modal-backdrop" @click.self="closeLine">
      <div class="pr-modal">
        <div class="pr-modal-header">
          <h3 class="pr-modal-title">{{ activeLine.partner?.name_en }}</h3>
          <a :href="route('hr.payroll.lines.payslip', activeLine.id)" target="_blank" class="zv-btn zv-btn-ghost">Payslip</a>
          <button type="button" class="pr-modal-close" @click="closeLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <div class="pr-modal-body">
          <!-- Additions & deductions -->
          <div class="pr-section-title">{{ t('accounting.hr.payrollRunShow.additionsDeductions') }}</div>

          <div v-if="activeLine.line_items?.length" class="pr-item-list mb-3">
            <div v-for="item in activeLine.line_items" :key="item.id" class="pr-item-row">
              <span class="zv-tag" :class="item.type === 'addition' ? 'zv-tag-green' : 'zv-tag-amber'">
                {{ item.type === 'addition' ? t('accounting.hr.payrollRunShow.addition') : item.deduction_reason?.name_en }}
              </span>
              <span class="pr-item-desc">{{ item.description }}</span>
              <span class="pr-item-amount">{{ fmt(item.amount) }}</span>
              <button v-if="run.status === 'draft'" type="button" class="zv-action-btn" @click="removeItem(item)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
              </button>
            </div>
          </div>
          <p v-else class="text-sm opacity-70 mb-3">{{ t('accounting.hr.payrollRunShow.noAdditionsOrDeductionsYet') }}</p>

          <div v-if="run.status === 'draft'" class="pr-add-item-row">
            <div class="zv-tile-group pr-type-tiles">
              <button type="button" class="zv-tile" :class="{ 'zv-tile-active': itemForm.type === 'addition' }" @click="itemForm.type = 'addition'; itemForm.deduction_reason_id = ''">{{ t('accounting.hr.payrollRunShow.addition') }}</button>
              <button type="button" class="zv-tile" :class="{ 'zv-tile-active': itemForm.type === 'deduction' }" @click="itemForm.type = 'deduction'">{{ t('accounting.hr.payrollRunShow.deduction') }}</button>
            </div>
            <SelectBox v-if="itemForm.type === 'deduction'" v-model="itemForm.deduction_reason_id" :options="deductionReasons" value-key="id" label-key="name_en" :placeholder="t('accounting.hr.payrollRunShow.selectReason')" :clearable="false" class="mb-2" />
            <div class="flex gap-2">
              <input v-model="itemForm.amount" type="number" step="0.01" min="0.01" class="zv-input" :placeholder="t('accounting.hr.payrollRunShow.amount')"/>
              <input v-model="itemForm.description" type="text" class="zv-input" :placeholder="t('common.notes')"/>
              <button type="button" class="zv-btn zv-btn-secondary" @click="addItem">{{ t('common.add') }}</button>
            </div>
          </div>

          <!-- Manual amounts -->
          <div class="pr-section-title mt-4">{{ t('accounting.hr.payrollRunShow.taxSocialInsuranceAdvance') }}</div>
          <p class="text-sm opacity-70 mb-2">{{ t('accounting.hr.payrollRunShow.enteredManuallyThisSlice') }}</p>

          <div class="grid grid-cols-2 gap-3 mb-3">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.hr.payrollRunShow.taxAmount') }}</label>
              <input v-model="amountsForm.tax_amount" type="number" step="0.01" min="0" class="zv-input" :disabled="run.status !== 'draft'"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.hr.payrollRunShow.siEmployee') }}</label>
              <input v-model="amountsForm.social_insurance_employee_amount" type="number" step="0.01" min="0" class="zv-input" :disabled="run.status !== 'draft'"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.hr.payrollRunShow.siEmployer') }}</label>
              <input v-model="amountsForm.social_insurance_employer_amount" type="number" step="0.01" min="0" class="zv-input" :disabled="run.status !== 'draft'"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.hr.payrollRunShow.advanceRecovery') }}</label>
              <input v-model="amountsForm.advance_deduction_amount" type="number" step="0.01" min="0" class="zv-input" :disabled="run.status !== 'draft'"/>
              <button v-if="run.status === 'draft'" type="button" class="pr-link-btn" @click="checkOutstanding">{{ t('accounting.hr.payrollRunShow.checkOutstandingAdvance') }}</button>
              <p v-if="outstandingAdvance !== null" class="text-sm opacity-70">{{ t('accounting.hr.payrollRunShow.outstanding') }}: {{ fmt(outstandingAdvance) }}</p>
            </div>
          </div>
          <p v-if="amountsForm.errors.advance_deduction_amount" class="zv-field-error">{{ amountsForm.errors.advance_deduction_amount }}</p>
        </div>

        <div v-if="run.status === 'draft'" class="pr-modal-footer">
          <button type="button" class="zv-btn zv-btn-ghost" @click="closeLine">{{ t('common.close') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="amountsForm.processing" @click="saveAmounts">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sum as sumMoney } from '@/Utils/money'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { router, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  run:              { type: Object, required: true },
  deductionReasons: { type: Array,  default: () => [] },
  bankAccounts:     { type: Array,  default: () => [] },
})

// M-36 — net-pay payment
const livePayment = computed(() => (props.run.payments ?? []).find(p => ! p.reversed_at))
const payForm = useForm({ bank_account_id: '', payment_date: todayLocal() })
function payNet() { payForm.post(route('hr.payroll.pay', props.run.id)) }
function reversePayment() {
  if (! confirm(t('accounting.hr.payrollRunShow.reverseNetPayConfirm'))) return
  router.patch(route('hr.payroll.reverse-payment', props.run.id))
}

const MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const periodLabel = computed(() => `${MONTHS[props.run.period_month]} ${props.run.period_year} ${t('accounting.hr.payrollRunShow.payroll')}`)

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

const CATEGORY_LABELS = { manufacturing: 'Manufacturing', sales: 'Sales & Distribution', marketing: 'Marketing', general_admin: 'G&A', cost_of_service: 'Cost of Service' }
const CATEGORY_TAGS   = { manufacturing: 'zv-tag-amber', sales: '', marketing: 'zv-tag-green', general_admin: 'zv-tag-gray', cost_of_service: 'zv-tag-gold' }
function categoryLabel(c) { return CATEGORY_LABELS[c] ?? c }
function categoryTagClass(c) { return CATEGORY_TAGS[c] ?? 'zv-tag-gray' }

// Exact decimal sums (Utils/money.js) so the cards never drift by a cent.
const totals = computed(() => {
  const lines = props.run.lines
  return {
    gross: sumMoney(lines.map(l => l.gross_pay ?? 0)),
    tax:   sumMoney(lines.map(l => l.tax_amount ?? 0)),
    si:    sumMoney(lines.flatMap(l => [l.social_insurance_employee_amount ?? 0, l.social_insurance_employer_amount ?? 0])),
    net:   sumMoney(lines.map(l => l.net_pay ?? 0)),
  }
})

// ── Line modal ───────────────────────────────────────────────────
// Only the id is remembered; the line itself is looked up in the CURRENT page data,
// so items added/removed (page reload) show up in the open window at once.
const activeLineId = ref(null)
const activeLine = computed(() => activeLineId.value == null ? null : (props.run.lines.find(l => l.id === activeLineId.value) ?? null))
let advanceReq = 0
const outstandingAdvance = ref(null)

const itemForm = useForm({ type: 'addition', deduction_reason_id: '', amount: '', description: '' })
const amountsForm = useForm({ tax_amount: '0', social_insurance_employee_amount: '0', social_insurance_employer_amount: '0', advance_deduction_amount: '0' })

function openLine(line) {
  activeLineId.value = line.id
  advanceReq++
  outstandingAdvance.value = null
  itemForm.reset()
  itemForm.type = 'addition'
  amountsForm.tax_amount = line.tax_amount ?? '0'
  amountsForm.social_insurance_employee_amount = line.social_insurance_employee_amount ?? '0'
  amountsForm.social_insurance_employer_amount = line.social_insurance_employer_amount ?? '0'
  amountsForm.advance_deduction_amount = line.advance_deduction_amount ?? '0'
}

function closeLine() {
  activeLineId.value = null
  advanceReq++
}

function addItem() {
  if (!itemForm.amount) return
  itemForm.post(route('hr.payroll.lines.add-item', activeLine.value.id), {
    preserveScroll: true,
    onSuccess: () => { itemForm.reset(); itemForm.type = 'addition'; router.reload({ preserveScroll: true }) },
  })
}

function removeItem(item) {
  router.delete(route('hr.payroll.line-items.remove', item.id), { preserveScroll: true })
}

function checkOutstanding() {
  const seq = ++advanceReq
  fetch(route('hr.payroll.lines.outstanding-advance', activeLine.value.id), { headers: { Accept: 'application/json' } })
    .then(r => r.json())
    .then(data => { if (seq === advanceReq) outstandingAdvance.value = data.outstanding })
}

function saveAmounts() {
  amountsForm.patch(route('hr.payroll.lines.manual-amounts', activeLine.value.id), {
    preserveScroll: true,
    onSuccess: () => closeLine(),
  })
}

// ── Run-level actions ────────────────────────────────────────────
function confirmPost() {
  if (! confirm(t('accounting.hr.payrollRunShow.postThisRunThisWillPostAJournal'))) return
  router.patch(route('hr.payroll.post', props.run.id))
}

function confirmSetToDraft() {
  if (! confirm(t('accounting.hr.payrollRunShow.reopenThisRunThisWillReverseEvery'))) return
  router.patch(route('hr.payroll.set-to-draft', props.run.id))
}
</script>

<style>
.pr-summary-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
@media (max-width: 900px) { .pr-summary-grid { grid-template-columns: repeat(2, 1fr); } }
.pr-summary-card { background: var(--zv-bg-card, #fff); border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius-md, 10px); padding: 14px 16px; }
.pr-summary-label { font-size: 12px; color: var(--zv-text-muted, #94a3b8); margin-bottom: 4px; }
.pr-summary-value { font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--zv-text-primary, #0f172a); }
.pr-summary-highlight { color: #06aff1; }

.pr-postings-title { font-size: 13px; font-weight: 700; padding: 14px 16px 0; color: var(--zv-text-primary, #0f172a); }

.pr-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 400; display: flex; align-items: center; justify-content: center; }
.pr-modal { width: 560px; max-width: 92vw; max-height: 88vh; overflow-y: auto; background: var(--zv-bg-card, #fff); border-radius: var(--zv-radius-lg, 14px); border: 1px solid var(--zv-border, #e2e8f0); box-shadow: var(--zv-shadow-md); }
.pr-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--zv-border, #e2e8f0); position: sticky; top: 0; background: var(--zv-bg-card, #fff); }
.pr-modal-title { font-size: 15px; font-weight: 700; color: var(--zv-text-primary, #0f172a); }
.pr-modal-close { width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--zv-text-muted, #94a3b8); }
.pr-modal-close:hover { background: var(--zv-bg-surface, #f1f5f9); }
.pr-modal-close svg { width: 16px; height: 16px; }
.pr-modal-body { padding: 20px; }
.pr-modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid var(--zv-border, #e2e8f0); }

.pr-section-title { font-size: 13px; font-weight: 700; color: var(--zv-text-primary, #0f172a); margin-bottom: 8px; }
.pr-item-list { display: flex; flex-direction: column; gap: 6px; }
.pr-item-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--zv-border, #f1f5f9); }
.pr-item-desc { flex: 1; font-size: 13px; color: var(--zv-text-muted, #94a3b8); }
.pr-item-amount { font-weight: 700; font-variant-numeric: tabular-nums; }
.pr-add-item-row { background: var(--zv-bg-surface, #f8fafc); border-radius: var(--zv-radius-md, 10px); padding: 12px; }
.pr-type-tiles { grid-template-columns: 1fr 1fr; margin-bottom: 8px; }
.pr-link-btn { font-size: 12px; color: #06aff1; text-decoration: underline; background: none; border: none; padding: 2px 0; cursor: pointer; }
</style>
