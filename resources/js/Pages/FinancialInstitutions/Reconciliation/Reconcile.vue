<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Reconciliation/Reconcile.vue

  Audit H-25 — the reconcile workspace for ONE bank account up to one
  statement date. Three parts:

    1. The reconciling-items report (bank side, book side, difference)
       — updates after every action. Completion is only possible when
       the difference is exactly 0.00.
    2. The bank's own statement lines (left): type in, import from
       Excel/CSV, auto-match, match by hand.
    3. The ledger entries of the account (right): tick the ones that
       appear on the bank statement.

  How to match by hand: click a statement line (left), tick the ledger
  entries it represents (right) — their total must equal the statement
  line — then press "Match selected". Ledger entries can also be ticked
  on their own ("Tick as cleared") when no statement lines were loaded.

  All amounts are in the bank account's OWN currency.

  A completed reconciliation is read-only and shows the frozen report;
  "Reopen" is available for the latest one only.

  @layout    AppLayout
  @route     fi.bank-reconciliation.show
  @props     reconciliation, currency_code, is_native, report,
             book_lines, statement_lines, book_lines_capped
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <div class="mb-3">
      <Link :href="route('fi.bank-reconciliation.index')" class="zv-btn zv-btn-ghost zv-btn-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ t('financialInstitutions.reconciliation.reconcile.backToList') }}
      </Link>
    </div>

    <div class="zv-page-header">
      <div>
        <h1 class="zv-page-title">
          {{ t('financialInstitutions.reconciliation.reconcile.title') }} — {{ reconciliation.bank_account?.bank?.name_en }} · {{ reconciliation.bank_account?.account_name }} · {{ currency_code }}
        </h1>
        <p class="zv-page-subtitle">
          {{ t('financialInstitutions.reconciliation.reconcile.statementDate') }}: {{ reconciliation.statement_date }}
          <span class="zv-badge ml-2" :class="completed ? 'zv-badge-success' : 'zv-badge-warning'">
            {{ completed ? t('financialInstitutions.reconciliation.reconcile.completed') : t('financialInstitutions.reconciliation.reconcile.inProgress') }}
          </span>
        </p>
      </div>
      <div class="brc-header-actions">
        <a :href="route('fi.bank-reconciliation.export', reconciliation.id)" class="zv-btn zv-btn-secondary">
          {{ t('financialInstitutions.reconciliation.reconcile.exportReport') }}
        </a>
        <button v-if="completed" type="button" class="zv-btn zv-btn-secondary" :disabled="busy" @click="reopen">
          {{ t('financialInstitutions.reconciliation.reconcile.reopen') }}
        </button>
        <template v-else>
          <button type="button" class="zv-btn zv-btn-ghost" :disabled="busy" @click="destroy">
            {{ t('financialInstitutions.reconciliation.reconcile.delete') }}
          </button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="busy || !canComplete" @click="complete">
            {{ t('financialInstitutions.reconciliation.reconcile.complete') }}
          </button>
        </template>
      </div>
    </div>

    <div v-if="is_native" class="zv-alert zv-alert-info mb-3">
      {{ t('financialInstitutions.reconciliation.reconcile.ownCurrencyNote', { currency: currency_code }) }}
    </div>
    <div v-if="report.unknown_foreign_count > 0" class="zv-alert zv-alert-warning mb-3">
      {{ t('financialInstitutions.reconciliation.reconcile.unknownForeign', { count: report.unknown_foreign_count }) }}
    </div>
    <div v-if="book_lines_capped" class="zv-alert zv-alert-warning mb-3">
      {{ t('financialInstitutions.reconciliation.reconcile.cappedNote') }}
    </div>

    <!-- ═══════════════ The report ═══════════════ -->
    <div class="brc-report-grid mb-5">
      <div class="zv-card brc-report-card">
        <div class="brc-report-title">{{ t('financialInstitutions.reconciliation.reconcile.bankSide') }}</div>
        <div class="brc-line"><span>{{ t('financialInstitutions.reconciliation.reconcile.statementBalance') }}</span><b>{{ fmt(report.statement_balance) }}</b></div>
        <div class="brc-line"><span>+ {{ t('financialInstitutions.reconciliation.reconcile.outstandingDeposits') }}</span><b>{{ fmt(report.outstanding_deposits) }}</b></div>
        <div class="brc-line"><span>− {{ t('financialInstitutions.reconciliation.reconcile.outstandingPayments') }}</span><b>{{ fmt(report.outstanding_payments) }}</b></div>
        <div class="brc-line brc-total"><span>= {{ t('financialInstitutions.reconciliation.reconcile.adjustedBank') }}</span><b>{{ fmt(report.adjusted_bank_balance) }}</b></div>
      </div>

      <div class="zv-card brc-report-card">
        <div class="brc-report-title">{{ t('financialInstitutions.reconciliation.reconcile.bookSide') }}</div>
        <div class="brc-line"><span>{{ t('financialInstitutions.reconciliation.reconcile.bookBalance') }}</span><b>{{ fmt(report.book_balance) }}</b></div>
        <div class="brc-line"><span>+ {{ t('financialInstitutions.reconciliation.reconcile.unbookedItems') }}</span><b>{{ fmt(report.unbooked_bank_items) }}</b></div>
        <div class="brc-line"><span>+ {{ t('financialInstitutions.reconciliation.reconcile.bookedAfter') }}</span><b>{{ fmt(report.booked_after_date) }}</b></div>
        <div class="brc-line brc-total"><span>= {{ t('financialInstitutions.reconciliation.reconcile.adjustedBook') }}</span><b>{{ fmt(report.adjusted_book_balance) }}</b></div>
      </div>

      <div class="zv-card brc-report-card brc-diff-card" :class="balanced ? 'brc-diff-ok' : 'brc-diff-bad'">
        <div class="brc-report-title">{{ t('financialInstitutions.reconciliation.reconcile.difference') }}</div>
        <div class="brc-diff-value">{{ currency_code }} {{ fmt(report.difference) }}</div>
        <div class="brc-diff-note">
          {{ balanced ? t('financialInstitutions.reconciliation.reconcile.balancedNote') : t('financialInstitutions.reconciliation.reconcile.notBalancedNote') }}
        </div>
        <div v-if="report.unmatched_statement_count > 0" class="brc-diff-sub">
          {{ t('financialInstitutions.reconciliation.reconcile.unbookedCount', { count: report.unmatched_statement_count }) }}
        </div>
      </div>
    </div>

    <!-- Fix a typo in the date / closing balance -->
    <div v-if="!completed" class="zv-card brc-header-edit mb-5">
      <div class="field-grid field-grid-4">
        <div class="field-group">
          <label class="zv-label">{{ t('financialInstitutions.reconciliation.reconcile.statementDate') }}</label>
          <input v-model="headerForm.statement_date" type="date" class="zv-input"/>
        </div>
        <div class="field-group">
          <label class="zv-label">{{ t('financialInstitutions.reconciliation.reconcile.closingBalance') }} ({{ currency_code }})</label>
          <input v-model="headerForm.statement_ending_balance" type="number" step="0.01" class="zv-input text-end"/>
        </div>
        <div class="field-group brc-align-end">
          <button type="button" class="zv-btn zv-btn-secondary" :disabled="busy" @click="saveHeader">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <div class="brc-columns">

      <!-- ═══════════════ Bank statement lines ═══════════════ -->
      <div class="zv-card brc-panel">
        <div class="brc-panel-head">
          <div>
            <div class="brc-panel-title">{{ t('financialInstitutions.reconciliation.reconcile.statementLines') }}</div>
            <div class="brc-panel-sub">{{ t('financialInstitutions.reconciliation.reconcile.statementLinesHint') }}</div>
          </div>
          <div v-if="!completed" class="brc-panel-actions">
            <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" :disabled="busy" @click="autoMatch">{{ t('financialInstitutions.reconciliation.reconcile.autoMatch') }}</button>
            <label class="zv-btn zv-btn-secondary zv-btn-sm brc-file-btn">
              {{ t('financialInstitutions.reconciliation.reconcile.importFile') }}
              <input type="file" accept=".xlsx,.xls,.csv,.txt" class="brc-file-input" @change="importFile"/>
            </label>
            <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="showAdd = !showAdd">{{ t('financialInstitutions.reconciliation.reconcile.addLine') }}</button>
          </div>
        </div>

        <div v-if="showAdd && !completed" class="brc-add-line">
          <div class="field-grid field-grid-4">
            <div class="field-group"><label class="zv-label">{{ t('common.date') }}</label><input v-model="lineForm.line_date" type="date" class="zv-input"/></div>
            <div class="field-group"><label class="zv-label">{{ t('financialInstitutions.reconciliation.reconcile.reference') }}</label><input v-model="lineForm.reference" type="text" class="zv-input"/></div>
            <div class="field-group"><label class="zv-label">{{ t('financialInstitutions.reconciliation.reconcile.amountSigned') }}</label><input v-model="lineForm.amount" type="number" step="0.01" class="zv-input text-end"/></div>
            <div class="field-group"><label class="zv-label">{{ t('financialInstitutions.reconciliation.reconcile.description') }}</label><input v-model="lineForm.description" type="text" class="zv-input"/></div>
          </div>
          <p v-if="lineForm.errors.amount || lineForm.errors.line_date" class="zv-field-error">{{ lineForm.errors.amount || lineForm.errors.line_date }}</p>
          <button type="button" class="zv-btn zv-btn-primary zv-btn-sm mt-2" :disabled="lineForm.processing" @click="addLine">{{ t('financialInstitutions.reconciliation.reconcile.add') }}</button>
        </div>

        <div v-if="statement_lines.length === 0" class="zv-empty"><div class="zv-empty-title">{{ t('financialInstitutions.reconciliation.reconcile.noStatementLines') }}</div></div>
        <div v-else class="zv-table-wrap brc-scroll">
          <table class="zv-table">
            <thead>
              <tr>
                <th>{{ t('common.date') }}</th>
                <th>{{ t('financialInstitutions.reconciliation.reconcile.description') }}</th>
                <th class="brc-th-num">{{ t('financialInstitutions.reconciliation.reconcile.amount') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in statement_lines" :key="s.id" class="brc-row"
                  :class="{ 'brc-selected': selectedStatementId === s.id, 'brc-matched': s.matched_here }"
                  @click="!completed && !s.matched_here && (selectedStatementId = selectedStatementId === s.id ? null : s.id)">
                <td class="brc-muted">{{ s.line_date }}</td>
                <td>
                  <div>{{ s.description || '—' }}</div>
                  <div v-if="s.reference" class="brc-muted brc-small">{{ s.reference }}</div>
                </td>
                <td class="brc-num" :class="Number(s.amount) < 0 ? 'brc-neg' : 'brc-pos'">{{ fmt(s.amount) }}</td>
                <td class="text-end">
                  <span v-if="s.matched_here" class="zv-badge zv-badge-success">{{ t('financialInstitutions.reconciliation.reconcile.matched') }}</span>
                  <span v-else class="zv-badge zv-badge-warning">{{ t('financialInstitutions.reconciliation.reconcile.open') }}</span>
                  <button v-if="!completed && s.matched_here" type="button" class="brc-link" @click.stop="unmatch(s)">{{ t('financialInstitutions.reconciliation.reconcile.unmatch') }}</button>
                  <button v-if="!completed && !s.matched_here" type="button" class="brc-link brc-link-danger" @click.stop="removeLine(s)">{{ t('financialInstitutions.reconciliation.reconcile.remove') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ═══════════════ Ledger entries ═══════════════ -->
      <div class="zv-card brc-panel">
        <div class="brc-panel-head">
          <div>
            <div class="brc-panel-title">{{ t('financialInstitutions.reconciliation.reconcile.ledgerEntries') }}</div>
            <div class="brc-panel-sub">{{ t('financialInstitutions.reconciliation.reconcile.ledgerEntriesHint') }}</div>
          </div>
        </div>

        <div v-if="!completed" class="brc-match-bar">
          <div class="brc-match-sums">
            <span>{{ t('financialInstitutions.reconciliation.reconcile.ticked') }}: <b>{{ fmt(tickedTotal) }}</b></span>
            <span v-if="selectedStatement">
              {{ t('financialInstitutions.reconciliation.reconcile.statementLine') }}: <b>{{ fmt(selectedStatement.amount) }}</b>
              <span :class="sumsEqual ? 'brc-ok' : 'brc-bad'">{{ sumsEqual ? '✓' : '≠' }}</span>
            </span>
          </div>
          <div class="brc-match-buttons">
            <button type="button" class="zv-btn zv-btn-primary zv-btn-sm" :disabled="busy || !canMatch" @click="matchSelected">{{ t('financialInstitutions.reconciliation.reconcile.matchSelected') }}</button>
            <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" :disabled="busy || tickedIds.length === 0" @click="tickAsCleared">{{ t('financialInstitutions.reconciliation.reconcile.tickAsCleared') }}</button>
          </div>
        </div>

        <div v-if="book_lines.length === 0" class="zv-empty"><div class="zv-empty-title">{{ t('financialInstitutions.reconciliation.reconcile.noLedgerEntries') }}</div></div>
        <div v-else class="zv-table-wrap brc-scroll">
          <table class="zv-table">
            <thead>
              <tr>
                <th v-if="!completed"></th>
                <th>{{ t('common.date') }}</th>
                <th>{{ t('financialInstitutions.reconciliation.reconcile.entry') }}</th>
                <th class="brc-th-num">{{ t('financialInstitutions.reconciliation.reconcile.amount') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in book_lines" :key="b.id" class="brc-row" :class="{ 'brc-matched': b.cleared_here }">
                <td v-if="!completed">
                  <input v-if="!b.cleared_here" v-model="tickedIds" type="checkbox" :value="b.id"/>
                </td>
                <td class="brc-muted">{{ String(b.entry_date).slice(0, 10) }}</td>
                <td>
                  <div>{{ b.description || b.narration || '—' }}</div>
                  <div class="brc-muted brc-small">{{ b.entry_number }}</div>
                </td>
                <td class="brc-num" :class="Number(b.amount) < 0 ? 'brc-neg' : 'brc-pos'">{{ fmt(b.amount) }}</td>
                <td class="text-end">
                  <span v-if="b.cleared_here" class="zv-badge zv-badge-success">{{ t('financialInstitutions.reconciliation.reconcile.cleared') }}</span>
                  <button v-if="!completed && b.cleared_here" type="button" class="brc-link" @click="unclear(b)">{{ t('financialInstitutions.reconciliation.reconcile.undo') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  reconciliation:    { type: Object, required: true },
  currency_code:     { type: String, default: '' },
  is_native:         { type: Boolean, default: false },
  report:            { type: Object, required: true },
  book_lines:        { type: Array, default: () => [] },
  statement_lines:   { type: Array, default: () => [] },
  book_lines_capped: { type: Boolean, default: false },
})

const completed = computed(() => props.reconciliation.status === 'completed')
const balanced  = computed(() => Number(props.report.difference) === 0)
const canComplete = computed(() => balanced.value && props.report.unknown_foreign_count === 0)

const busy = ref(false)
const base = (suffix = '') => route('fi.bank-reconciliation.show', props.reconciliation.id) + suffix

// ── Selection ──────────────────────────────────────────────────
const selectedStatementId = ref(null)
const tickedIds = ref([])

const selectedStatement = computed(() => props.statement_lines.find(s => s.id === selectedStatementId.value) || null)
const tickedTotal = computed(() => props.book_lines
  .filter(b => tickedIds.value.includes(b.id))
  .reduce((sum, b) => sum + Math.round(Number(b.amount) * 100), 0) / 100)
const sumsEqual = computed(() => selectedStatement.value
  && Math.round(tickedTotal.value * 100) === Math.round(Number(selectedStatement.value.amount) * 100))
const canMatch = computed(() => !!selectedStatement.value && tickedIds.value.length > 0 && sumsEqual.value)

function send(method, url, data = {}, extra = {}) {
  busy.value = true
  const options = {
    preserveScroll: true,
    onSuccess: () => { selectedStatementId.value = null; tickedIds.value = [] },
    onFinish: () => { busy.value = false },
    ...extra,
  }
  // router.delete(url, options) takes no body; the others are (url, data, options)
  if (method === 'delete') router.delete(url, options)
  else router[method](url, data, options)
}

// ── Actions ────────────────────────────────────────────────────
function autoMatch()    { send('post', base('/auto-match')) }
function matchSelected() { send('post', base('/match'), { statement_line_id: selectedStatementId.value, book_line_ids: tickedIds.value }) }
function tickAsCleared() { send('post', base('/clear'), { book_line_ids: tickedIds.value }) }
function unclear(b)      { send('post', base('/unclear'), { book_line_ids: [b.id] }) }
function unmatch(s)      { send('post', base('/unmatch'), { statement_line_id: s.id }) }
function removeLine(s) {
  if (!confirm(t('financialInstitutions.reconciliation.reconcile.confirmRemove'))) return
  send('delete', base('/statement-lines/' + s.id))
}
function complete() {
  if (!confirm(t('financialInstitutions.reconciliation.reconcile.confirmComplete'))) return
  send('post', base('/complete'))
}
function reopen() {
  if (!confirm(t('financialInstitutions.reconciliation.reconcile.confirmReopen'))) return
  send('patch', base('/reopen'))
}
function destroy() {
  if (!confirm(t('financialInstitutions.reconciliation.reconcile.confirmDelete'))) return
  send('delete', base())
}

// ── Header (date / closing balance) ────────────────────────────
const headerForm = ref({
  statement_date: String(props.reconciliation.statement_date).slice(0, 10),
  statement_ending_balance: props.reconciliation.statement_ending_balance,
})
function saveHeader() {
  send('put', base(), {
    bank_account_id: props.reconciliation.bank_account_id,
    statement_date: headerForm.value.statement_date,
    statement_ending_balance: headerForm.value.statement_ending_balance,
  })
}

// ── Statement lines: type in / import ──────────────────────────
const showAdd = ref(false)
const lineForm = useForm({
  line_date: String(props.reconciliation.statement_date).slice(0, 10),
  reference: '', amount: '', description: '',
})
function addLine() {
  lineForm.post(base('/statement-lines'), {
    preserveScroll: true,
    onSuccess: () => lineForm.reset('reference', 'amount', 'description'),
  })
}
function importFile(e) {
  const file = e.target.files?.[0]
  if (!file) return
  send('post', base('/import'), { file }, { forceFormData: true, onFinish: () => { busy.value = false; e.target.value = '' } })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.brc-header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.brc-report-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
@media (max-width: 1000px) { .brc-report-grid { grid-template-columns: 1fr; } }
.brc-report-card { padding: 16px 18px; }
.brc-report-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 10px; }
.brc-line { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; font-size: 13px; font-variant-numeric: tabular-nums; }
.brc-line span { color: var(--zv-text-secondary); }
.brc-total { border-top: 1px solid var(--zv-border); margin-top: 6px; padding-top: 8px; font-weight: 700; }
.brc-diff-card { text-align: center; display: flex; flex-direction: column; justify-content: center; }
.brc-diff-value { font-size: 28px; font-weight: 800; font-variant-numeric: tabular-nums; }
.brc-diff-note { font-size: 12.5px; margin-top: 6px; }
.brc-diff-sub { font-size: 12px; margin-top: 8px; color: var(--zv-text-muted); }
.brc-diff-ok { border-color: rgba(16,185,129,0.45); }
.brc-diff-ok .brc-diff-value, .brc-diff-ok .brc-diff-note { color: #10b981; }
.brc-diff-bad { border-color: rgba(239,68,68,0.40); }
.brc-diff-bad .brc-diff-value, .brc-diff-bad .brc-diff-note { color: #ef4444; }
.brc-header-edit { padding: 14px 18px; }
.brc-align-end { display: flex; align-items: flex-end; }

.brc-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; align-items: start; }
@media (max-width: 1100px) { .brc-columns { grid-template-columns: 1fr; } }
.brc-panel { padding: 0; }
.brc-panel-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--zv-border); flex-wrap: wrap; }
.brc-panel-title { font-size: 14px; font-weight: 800; color: var(--zv-text-primary); }
.brc-panel-sub { font-size: 12px; color: var(--zv-text-muted); margin-top: 2px; max-width: 360px; }
.brc-panel-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.brc-file-btn { position: relative; cursor: pointer; }
.brc-file-input { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; }
.brc-add-line { padding: 14px 18px; border-bottom: 1px solid var(--zv-border); background: var(--zv-bg-surface-alt); }
.brc-scroll { max-height: 520px; overflow-y: auto; }
.brc-th-num { text-align: end; }
.brc-num { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
.brc-neg { color: #ef4444; }
.brc-pos { color: #10b981; }
.brc-muted { color: var(--zv-text-muted); }
.brc-small { font-size: 11.5px; }
.brc-row { cursor: default; }
.brc-row:hover { background: var(--zv-bg-surface-alt); }
.brc-selected { outline: 2px solid #FFBF00; outline-offset: -2px; background: rgba(255,191,0,0.08); cursor: pointer; }
.brc-matched { background: rgba(16,185,129,0.06); }
.brc-link { background: none; border: none; color: var(--zv-teal, #1490A8); font-size: 12px; font-weight: 600; margin-inline-start: 8px; cursor: pointer; }
.brc-link-danger { color: #ef4444; }
.brc-match-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 18px; border-bottom: 1px solid var(--zv-border); flex-wrap: wrap; }
.brc-match-sums { display: flex; gap: 16px; font-size: 12.5px; color: var(--zv-text-secondary); flex-wrap: wrap; }
.brc-match-buttons { display: flex; gap: 6px; }
.brc-ok { color: #10b981; font-weight: 800; }
.brc-bad { color: #ef4444; font-weight: 800; }
</style>
