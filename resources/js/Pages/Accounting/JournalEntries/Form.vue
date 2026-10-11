<!--
══════════════════════════════════════════════════════════════════
  Accounting/JournalEntries/Form.vue

  Manual Journal Entry — Phase 4 exception tool (September 2026, per
  Mahmoud), for adjustments/accruals/corrections no business document
  auto-posts. Create only — posts immediately on submit, same
  discipline as Accruals/Form.vue: no draft state, no edit route. A
  wrong entry is corrected by reversing it (Rule: "A posted entry is
  immutable"), not by editing this page later.

  Two independent, identically-shaped repeaters — Debit lines /
  Credit lines — each row is Account + Amount, with an optional Cost
  Center and a per-line note. Kept as two plain v-for tables (same
  pattern as every other line-repeater in the app, e.g. Customer
  Invoice lines) rather than a new shared component — nothing else
  needs a generic "account + amount" repeater yet.

  The totals footer is a live, client-side reassurance only; the
  authoritative balance check is JournalEntryService::assertBalanced()
  on the backend (same as the "Balanced" badge on the View modal) —
  this just disables Submit early and shows the gap.

  @layout    AppLayout
  @route     accounting.journal-entries.create
  @props     accounts, costCenters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.journalEntries.form.journalEntries'), href: route('accounting.journal-entries.index') }, { label: t('accounting.journalEntries.form.newManualEntry') }]"
      :title="t('accounting.journalEntries.form.newManualJournalEntry')"
      :subtitle="t('accounting.journalEntries.form.forAdjustmentsAndCorrectionsNoDocument')"
    />

    <form @submit.prevent="submit">

      <!-- ── Entry details ─────────────────────────────────────── -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.journalEntries.form.entryDetails') }}</div>
            <div class="form-section-sub">{{ t('accounting.journalEntries.form.whenAndWhyForThisEntry') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-4-8">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.journalEntries.form.entryDate') }}</label>
              <input v-model="form.entry_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.entry_date }"/>
              <p v-if="form.errors.entry_date" class="zv-field-error">{{ form.errors.entry_date }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.journalEntries.form.narration') }}</label>
              <input v-model="form.narration" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.narration }" :placeholder="t('accounting.journalEntries.form.eGSeptemberDepreciationAdjustment')"/>
              <p v-if="form.errors.narration" class="zv-field-error">{{ form.errors.narration }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Debit lines ───────────────────────────────────────── -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#047857">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.journalEntries.form.debitLines') }}</div>
            <div class="form-section-sub">{{ t('accounting.journalEntries.form.accountsBeingDebited') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.journalEntries.form.account') }}</th>
                  <th>{{ t('accounting.journalEntries.form.costCenter') }}</th>
                  <th>{{ t('accounting.journalEntries.form.description') }}</th>
                  <th class="zv-table-num">{{ t('accounting.journalEntries.form.amount') }}</th>
                  <th class="ci-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.debit_lines" :key="idx">
                  <td><SelectBox v-model="line.chart_of_account_id" :options="accounts" value-key="id" :label-fn="a => `${a.code} — ${a.name_en}`" :placeholder="t('accounting.journalEntries.form.selectAccount')" :clearable="false" size="sm" /></td>
                  <td><SelectBox v-model="line.cost_center_id" :options="costCenters" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.journalEntries.form.none')" size="sm" /></td>
                  <td><input v-model="line.description" type="text" class="zv-input" :placeholder="t('accounting.journalEntries.form.optional')"/></td>
                  <td class="zv-table-num"><input v-model="line.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" style="width:8rem;"/></td>
                  <td class="ci-th-actions">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.journalEntries.form.removeLine')" @click="removeLine(form.debit_lines, idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine(form.debit_lines)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('accounting.journalEntries.form.addLine') }}
          </button>
          <p v-if="form.errors.debit_lines" class="zv-field-error mt-2">{{ form.errors.debit_lines }}</p>
        </div>
      </div>

      <!-- ── Credit lines ──────────────────────────────────────── -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#B45309">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.journalEntries.form.creditLines') }}</div>
            <div class="form-section-sub">{{ t('accounting.journalEntries.form.accountsBeingCredited') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.journalEntries.form.account') }}</th>
                  <th>{{ t('accounting.journalEntries.form.costCenter') }}</th>
                  <th>{{ t('accounting.journalEntries.form.description') }}</th>
                  <th class="zv-table-num">{{ t('accounting.journalEntries.form.amount') }}</th>
                  <th class="ci-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.credit_lines" :key="idx">
                  <td><SelectBox v-model="line.chart_of_account_id" :options="accounts" value-key="id" :label-fn="a => `${a.code} — ${a.name_en}`" :placeholder="t('accounting.journalEntries.form.selectAccount')" :clearable="false" size="sm" /></td>
                  <td><SelectBox v-model="line.cost_center_id" :options="costCenters" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.journalEntries.form.none')" size="sm" /></td>
                  <td><input v-model="line.description" type="text" class="zv-input" :placeholder="t('accounting.journalEntries.form.optional')"/></td>
                  <td class="zv-table-num"><input v-model="line.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" style="width:8rem;"/></td>
                  <td class="ci-th-actions">
                    <button type="button" class="zv-btn-remove-row" :title="t('accounting.journalEntries.form.removeLine')" @click="removeLine(form.credit_lines, idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine(form.credit_lines)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('accounting.journalEntries.form.addLine') }}
          </button>
          <p v-if="form.errors.credit_lines" class="zv-field-error mt-2">{{ form.errors.credit_lines }}</p>
        </div>
      </div>

      <!-- ── Totals / balance check ────────────────────────────── -->
      <div class="zv-card mb-5">
        <div class="je-totals-row">
          <div class="je-totals-item">
            <span class="je-totals-label">{{ t('accounting.journalEntries.form.totalDebit') }}</span>
            <span class="je-totals-value">{{ fmt(totalDebit) }}</span>
          </div>
          <div class="je-totals-item">
            <span class="je-totals-label">{{ t('accounting.journalEntries.form.totalCredit') }}</span>
            <span class="je-totals-value">{{ fmt(totalCredit) }}</span>
          </div>
          <div class="je-totals-item">
            <span v-if="isBalanced" class="je-balanced">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              {{ t('accounting.journalEntries.form.balanced') }}
            </span>
            <span v-else class="je-unbalanced">
              {{ t('accounting.journalEntries.form.outOfBalanceBy') }} {{ fmt(difference) }}
            </span>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.journal-entries.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing || !isBalanced" class="zv-btn zv-btn-primary" :title="!isBalanced ? t('accounting.journalEntries.form.debitAndCreditMustBalanceBeforePosting') : null">
            {{ form.processing ? t('accounting.journalEntries.form.posting') : t('accounting.journalEntries.form.postJournalEntry') }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { sum as sumMoney, sub as subMoney } from '@/Utils/money'
import { computed } from 'vue'
import { todayLocal } from '@/Utils/dates'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  accounts:    { type: Array, default: () => [] },
  costCenters: { type: Array, default: () => [] },
})

function blankLine() {
  return { chart_of_account_id: '', cost_center_id: '', amount: '', description: '' }
}

const form = useForm({
  entry_date:   todayLocal(),
  narration:    '',
  debit_lines:  [blankLine()],
  credit_lines: [blankLine()],
})

function addLine(lines) {
  lines.push(blankLine())
}

function removeLine(lines, idx) {
  if (lines.length > 1) lines.splice(idx, 1)
}

function sumLines(lines) {
  return Number(sumMoney(lines.map(l => l.amount))) // Audit L6: exact decimal sum
}

const totalDebit  = computed(() => sumLines(form.debit_lines))
const totalCredit = computed(() => sumLines(form.credit_lines))
const difference  = computed(() => Math.abs(Number(subMoney(totalDebit.value, totalCredit.value))))
// "Balanced" now also requires every line to be usable: an account chosen and a
// positive amount. Before, negative amounts or lines with no account could
// still show the green BALANCED badge and enable Post.
const linesValid = (lines) => lines.length > 0 && lines.every(l => l.chart_of_account_id && Number(l.amount) > 0)
const isBalanced  = computed(() =>
  totalDebit.value > 0 && difference.value < 0.005 &&
  linesValid(form.debit_lines) && linesValid(form.credit_lines)
)

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  form.post(route('accounting.journal-entries.store'))
}
</script>

<style>
/* Not global — CustomerInvoices/Form.vue defines its own copy of this
   same small utility rather than relying on it being loaded from
   elsewhere; same approach here. */
.ci-th-actions { width: 3rem; text-align: end; }

.je-totals-row {
  display: flex;
  align-items: center;
  gap: 2.5rem;
  padding: 1rem 1.25rem;
  flex-wrap: wrap;
}
.je-totals-item { display: flex; flex-direction: column; gap: 0.125rem; }
.je-totals-label { font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.55; font-weight: 600; }
.je-totals-value { font-size: 1.0625rem; font-weight: 700; font-variant-numeric: tabular-nums; }

.je-balanced {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  color: var(--zv-num-green, #047857);
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.je-unbalanced {
  color: var(--zv-num-red, #B91C1C);
  font-size: 0.8125rem;
  font-weight: 700;
}
</style>
