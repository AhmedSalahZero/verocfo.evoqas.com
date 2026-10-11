<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/MtlScheduleIndex.vue

  The Loan Schedule Table — confirmed NOT auto-calculated. Same
  manual-entry + Excel-upload repeater mechanism as Leasing's
  Cheque Schedule (ContractForm.vue), reused here as a standalone
  page since MTL's schedule is reached from the facility list
  rather than embedded in the facility form itself.

  Status per row is DERIVED (Past Due / Partially Paid / Paid /
  Upcoming) from comparing settled_amount against schedule_payment
  — never a stored flag, since a line can be paid across multiple
  partial settlements (see MtlScheduleLine::computedStatus()).

  The $ action opens MtlSettlementForm.vue for that specific line.

  v1.1 — ONE merged table now, not two. Previously this page showed
  a separate editable "entry" table plus a separate read-only
  "Saved Schedule" table underneath, both showing the same numbers
  the moment anything was saved — confusing, looked like duplicated
  data. Now every row lives in one table: editable by default,
  and automatically becomes read-only (no input boxes, no Remove
  button) the moment ANY settlement — even one partial payment —
  is recorded against it (row.is_locked, from MtlScheduleLine::
  isLocked()). The Settlement ($) button still works on a locked
  row — locking only protects the schedule's own numbers and its
  removal, never the ability to record further settlements. A
  locked row becomes editable again only once every settlement
  against it is reversed. This is enforced for real on the server
  (MtlService::saveSchedule()), not just hidden on screen.

  @layout    AppLayout
  @route     fi.facilities.mtl.schedule
  @props     facility, currency, scheduleLines
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.facilities.mtl.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.mediumTermLoan') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.loanSchedule') }}</span>
          </div>
          <h1 class="zv-page-title">{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.loanScheduleTable') }} — {{ facility.name }}</h1>
          <p class="zv-page-subtitle">{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.enteredManuallyOrUploadedFromExcel') }}</p>
        </div>
        <label class="mtl-upload-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
          </svg>
          {{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.uploadExcel') }}
          <input type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="onExcelUpload"/>
        </label>
      </div>

      <div v-if="uploadError" class="zv-alert zv-alert-danger mb-4">{{ uploadError }}</div>
      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-4" style="flex-direction:column; align-items:flex-start; gap:4px;">
        <div>{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.pleaseFixTheErrorsBelowBefore') }}</div>
        <ul v-if="rowProblems.length" class="mtl-row-problems">
          <li v-for="p in rowProblems" :key="p.key">{{ p.text }}</li>
        </ul>
      </div>

      <div class="zv-card mb-5">
        <div class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th style="width:11%">{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.dueDate') }}</th>
                <th style="width:11%" class="text-end">{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.beginningBalance') }}</th>
                <th style="width:11%" class="text-end">{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.schedulePayment') }}</th>
                <th style="width:10%" class="text-end">{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.interestAmount') }}</th>
                <th style="width:10%" class="text-end">{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.principalAmount') }}</th>
                <th style="width:11%" class="text-end">{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.endBalance') }}</th>
                <th style="width:9%">{{ t('common.status') }}</th>
                <th style="width:9%" class="text-end">{{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.remaining') }}</th>
                <th style="width:9%" class="mtl-th-actions"></th>
                <th style="width:9%" class="mtl-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, idx) in form.lines" :key="row.id ?? `new-${idx}`">
                <!-- Editable when unlocked, plain text when locked -->
                <td>
                  <input v-if="!row.is_locked" v-model="row.due_date" type="date" class="zv-input mtl-cell-input"
                         :class="{ 'zv-input-error': cellError(row, 'due_date') }" :title="cellError(row, 'due_date') || undefined"/>
                  <span v-else class="mtl-locked-value">{{ fmtDate(row.due_date) }}</span>
                </td>
                <td>
                  <input v-if="!row.is_locked" v-model="row.beginning_balance" type="number" step="0.01" class="zv-input mtl-cell-input text-end"
                         :class="{ 'zv-input-error': cellError(row, 'beginning_balance') }" :title="cellError(row, 'beginning_balance') || undefined"/>
                  <span v-else class="mtl-locked-value text-end">{{ fmt(row.beginning_balance) }}</span>
                </td>
                <td>
                  <input v-if="!row.is_locked" v-model="row.schedule_payment" type="number" step="0.01" class="zv-input mtl-cell-input text-end"
                         :class="{ 'zv-input-error': cellError(row, 'schedule_payment') }" :title="cellError(row, 'schedule_payment') || undefined"/>
                  <span v-else class="mtl-locked-value text-end">{{ fmt(row.schedule_payment) }}</span>
                </td>
                <td>
                  <input v-if="!row.is_locked" v-model="row.interest_amount" type="number" step="0.01" class="zv-input mtl-cell-input text-end"
                         :class="{ 'zv-input-error': cellError(row, 'interest_amount') }" :title="cellError(row, 'interest_amount') || undefined"/>
                  <span v-else class="mtl-locked-value text-end">{{ fmt(row.interest_amount) }}</span>
                </td>
                <td>
                  <input v-if="!row.is_locked" v-model="row.principal_amount" type="number" step="0.01" class="zv-input mtl-cell-input text-end"
                         :class="{ 'zv-input-error': cellError(row, 'principal_amount') }" :title="cellError(row, 'principal_amount') || undefined"/>
                  <span v-else class="mtl-locked-value text-end">{{ fmt(row.principal_amount) }}</span>
                </td>
                <td>
                  <input v-if="!row.is_locked" v-model="row.end_balance" type="number" step="0.01" class="zv-input mtl-cell-input text-end"
                         :class="{ 'zv-input-error': cellError(row, 'end_balance') }" :title="cellError(row, 'end_balance') || undefined"/>
                  <span v-else class="mtl-locked-value text-end">{{ fmt(row.end_balance) }}</span>
                </td>

                <!-- Status / Remaining — only meaningful once the row is actually saved (has an id) -->
                <td>
                  <span v-if="row.id" class="mtl-status-badge" :class="statusClass(row.computed_status)">{{ t(statusLabel(row.computed_status)) }}</span>
                  <span v-else class="mtl-locked-value">—</span>
                </td>
                <td class="text-end">
                  <span v-if="row.id">{{ fmt(row.remaining_amount) }}</span>
                  <span v-else class="mtl-locked-value">—</span>
                </td>

                <!-- Settlement — available on any saved row, locked or not -->
                <td class="mtl-th-actions">
                  <Link v-if="row.id" :href="route('fi.mtl.settlement-form', row.id)" class="zv-btn zv-btn-secondary zv-btn-sm" :title="t('financialInstitutions.banks.facilities.mtlScheduleIndex.settlement')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                  </Link>
                </td>

                <!-- Remove — only when unlocked -->
                <td class="mtl-th-actions">
                  <button v-if="!row.is_locked" type="button" class="mtl-remove-btn" @click="removeRow(idx)" :title="t('financialInstitutions.banks.facilities.mtlScheduleIndex.removeRow')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                  <span v-else class="mtl-locked-note" :title="t('financialInstitutions.banks.facilities.mtlScheduleIndex.reverseTheSettlementToUnlockThis')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mtl-lines-footer">
          <button type="button" class="mtl-add-row-btn" @click="addRow">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('financialInstitutions.banks.facilities.mtlScheduleIndex.addInstallment') }}
          </button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="form.processing" @click="submit">
            {{ form.processing ? t('common.saving') : t('financialInstitutions.banks.facilities.mtlScheduleIndex.saveSchedule') }}
          </button>
        </div>
      </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  facility:      { type: Object, required: true },
  currency:      Object,
  scheduleLines: { type: Array, default: () => [] },
})

function defaultRow() {
  return {
    id: null,
    due_date: '', beginning_balance: '', schedule_payment: '',
    interest_amount: '', principal_amount: '', end_balance: '',
    is_locked: false, computed_status: null, remaining_amount: null,
  }
}

// Turns the saved lines the server sends into the editable rows on screen.
function rowsFromLines(lines) {
  return lines.length > 0
    ? lines.map(l => ({
        id: l.id,
        due_date: l.due_date?.toString().slice(0, 10) ?? '',
        beginning_balance: l.beginning_balance ?? '',
        schedule_payment: l.schedule_payment ?? '',
        interest_amount: l.interest_amount ?? '',
        principal_amount: l.principal_amount ?? '',
        end_balance: l.end_balance ?? '',
        is_locked: l.is_locked ?? false,
        computed_status: l.computed_status ?? null,
        remaining_amount: l.remaining_amount ?? null,
      }))
    : [defaultRow()]
}

const form = useForm({
  lines: rowsFromLines(props.scheduleLines),
})

const NUMBER_FIELDS = ['beginning_balance', 'schedule_payment', 'interest_amount', 'principal_amount', 'end_balance']
// A spare row nobody typed into. The server refuses it ("due date is
// required"), so it is left out of what is SENT — the row on screen stays.
function isBlankRow(r) {
  return !r.id && !r.due_date && NUMBER_FIELDS.every(k => r[k] === '' || r[k] === null || r[k] === undefined)
}
form.transform(data => ({ ...data, lines: data.lines.filter(r => !isBlankRow(r)) }))

// The server numbers rows by what it was sent (blank rows left out), so
// map its error keys back to the row on screen.
function cellError(row, field) {
  const sent = form.lines.filter(r => !isBlankRow(r))
  const i = sent.indexOf(row)
  return i < 0 ? '' : (form.errors[`lines.${i}.${field}`] || '')
}

const FIELD_NAMES = {
  due_date: 'due date', beginning_balance: 'beginning balance', schedule_payment: 'payment',
  interest_amount: 'interest', principal_amount: 'principal', end_balance: 'end balance',
}
const rowProblems = computed(() => {
  const sent = form.lines.filter(r => !isBlankRow(r))
  return Object.entries(form.errors)
    .filter(([key]) => key.startsWith('lines.'))
    .map(([key]) => {
      const [, i, field] = key.split('.')
      const shown = form.lines.indexOf(sent[Number(i)]) + 1
      const text = String(form.errors[key]).replace(/lines\.\d+\./, '').replace(/_/g, ' ')
      return { key, text: `Installment ${shown || Number(i) + 1} — ${FIELD_NAMES[field] ?? field}: ${text}` }
    })
})

function addRow() { form.lines.push(defaultRow()) }

// Locked rows can never be removed from the screen — this button
// isn't even shown for them, but this guard stays as a second,
// harmless line of defense in case anything calls this directly.
function removeRow(idx) {
  if (form.lines[idx]?.is_locked) return
  form.lines.splice(idx, 1)
}

function submit() {
  form.post(route('fi.facilities.mtl.schedule.save', props.facility.id), {
    preserveScroll: true,
    // After a good save, show what the server now holds: the new rows get
    // their ids (so the settlement button and status appear, and a second
    // save updates them instead of creating them again). A refusal also
    // arrives as a normal page, so only do this when there is no error —
    // otherwise the user's typing would be thrown away.
    onSuccess: (page) => {
      if (!page.props.flash?.error) form.lines = rowsFromLines(page.props.scheduleLines ?? [])
    },
  })
}

const uploadError = ref('')
async function onExcelUpload(e) {
  const file = e.target.files[0]
  if (!file) return
  uploadError.value = ''
  const formData = new FormData()
  formData.append('file', file)
  try {
    // BUG FIX: this request was missing the CSRF token — every other
    // POST/DELETE fetch() in the app attaches it (see
    // ContractAssignmentOdIndex.vue), but this one and the Leasing
    // cheque import (ContractForm.vue) didn't. Without it, Laravel
    // correctly rejects the request (419) as untrusted — nothing to
    // do with the uploaded file itself, but the old catch-all below
    // showed the same "could not read this file" message for that
    // and every other possible failure, which was actively
    // misleading here.
    const res = await fetch(route('fi.facilities.mtl.schedule.import'), {
      method: 'POST',
      body: formData,
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
      },
    })
    if (!res.ok) {
      if (res.status === 419) {
        throw new Error('Your session has expired — please refresh the page and try again.')
      }
      throw new Error('Upload failed')
    }
    const data = await res.json()
    // Uploaded rows are always brand new, never-saved rows.
    form.lines = form.lines.filter(r => r.due_date && !r.is_locked || r.is_locked)
    form.lines.push(...data.rows.map(r => ({ ...r, id: null, is_locked: false, computed_status: null, remaining_amount: null })))
    if (form.lines.length === 0) form.lines.push(defaultRow())
  } catch (err) {
    uploadError.value = err.message === 'Upload failed' || !err.message
      ? t('financialInstitutions.banks.facilities.mtlScheduleIndex.couldNotReadThisFilePlease')
      : t(err.message)
  } finally {
    e.target.value = ''
  }
}

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' }
function statusLabel(s) { return { past_due: 'Past Due', partially_paid: 'Partially Paid', paid: 'Paid', upcoming: 'Upcoming' }[s] ?? s }
function statusClass(s) { return { past_due: 'mtl-status-danger', partially_paid: 'mtl-status-warn', paid: 'mtl-status-ok', upcoming: 'mtl-status-muted' }[s] ?? '' }
</script>

<style>
.mtl-upload-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius); background: var(--zv-bg-surface); color: #1490A8; font-size: 13px; font-weight: 600; cursor: pointer; }
.mtl-upload-btn:hover { border-color: #1490A8; background: rgba(20,144,168,0.06); }
.hidden { display: none; }

.mtl-cell-input { padding: 6px 8px; font-size: 12.5px; min-height: unset; }
.mtl-remove-btn { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: var(--zv-radius); border: none; background: transparent; cursor: pointer; color: var(--zv-text-muted); }
.mtl-remove-btn:hover { background: rgba(239,68,68,0.1); color: #ef4444; }
.mtl-lines-footer { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-top: 1px dashed var(--zv-border); }
.mtl-add-row-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border: 1px dashed var(--zv-border); border-radius: var(--zv-radius); background: transparent; color: #1490A8; font-size: 13px; cursor: pointer; }
.mtl-add-row-btn:hover { border-color: #1490A8; background: rgba(20,144,168,0.05); }

.mtl-existing-header { padding: 14px 18px; font-size: 14px; font-weight: 700; color: var(--zv-text-primary); border-bottom: 1px solid var(--zv-border); }
.mtl-th-actions { width: 60px; text-align: end; }
.text-end { text-align: end; }
.mtl-row-problems { margin: 0; padding-inline-start: 18px; font-size: 12.5px; }

.mtl-status-badge { display: inline-flex; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.mtl-status-danger { background: rgba(239,68,68,0.1); color: #ef4444; }
.mtl-status-warn { background: rgba(245,158,11,0.1); color: #f59e0b; }
.mtl-status-ok { background: rgba(16,185,129,0.1); color: #10b981; }
.mtl-status-muted { background: rgba(100,116,139,0.1); color: #64748b; }

.zv-alert { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: var(--zv-radius-md); font-size: 13px; }
.zv-alert-danger { background: rgba(239,68,68,0.07); border: 1px solid rgba(239,68,68,0.2); color: #dc2626; }

@media (max-width: 900px) { .zv-page-header { flex-direction: column; } }
</style>
