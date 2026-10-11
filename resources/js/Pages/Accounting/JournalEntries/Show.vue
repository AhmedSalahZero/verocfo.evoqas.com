<!--
══════════════════════════════════════════════════════════════════
  Accounting/JournalEntries/Show.vue

  Manual Journal Entry only — "View" page (September 2026, per
  Mahmoud). Opens locked (read-only). "Set to Draft" reverses the
  entry (JournalEntryService::setToDraft()) and unlocks the SAME
  fields on this page for editing — no separate draft row exists to
  reopen (the journal entry IS the record), so this is deliberately
  simpler than Customer Collection's version: editing here does NOT
  update the old entry, it posts a brand-new one via the same Create
  endpoint (accounting.journal-entries.store). The old, reversed
  entry is left exactly as-is, permanently.

  Once unlocked, two ways out:
    - Delete   — discard, go back to the list. The reversal already
      happened; this does nothing further.
    - Post New Entry — submits the (possibly edited) lines as a new
      Manual Journal Entry.

  @layout    AppLayout
  @route     accounting.journal-entries.show
  @props     journalEntry, accounts, costCenters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.journalEntries.show.journalEntries'), href: route('accounting.journal-entries.index') }, { label: journalEntry.entry_number }]"
      :title="t('accounting.journalEntries.show.manualJournalEntry') + ' ' + journalEntry.entry_number"
      :subtitle="locked ? t('accounting.journalEntries.show.postedReadOnlySetToDraftToEdit') : t('accounting.journalEntries.show.reversedEditTheLinesBelowAndPost')"
    />

    <div v-if="!locked" class="je-show-banner">
      {{ t('accounting.journalEntries.show.thisEntryWasReversedIfYouHaven') }}
    </div>

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
            <div class="form-section-title">{{ t('accounting.journalEntries.show.entryDetails') }}</div>
            <div class="form-section-sub">
              <StatusBadge :status="journalEntry.status" :overrides="JE_STATUS_BADGES" />
            </div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-4-8">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.journalEntries.show.entryDate') }}</label>
              <input v-model="form.entry_date" type="date" class="zv-input" :disabled="locked" :class="{ 'zv-input-error': form.errors.entry_date }"/>
              <p v-if="form.errors.entry_date" class="zv-field-error">{{ form.errors.entry_date }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.journalEntries.show.narration') }}</label>
              <input v-model="form.narration" type="text" class="zv-input" :disabled="locked" :class="{ 'zv-input-error': form.errors.narration }"/>
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
            <div class="form-section-title">{{ t('accounting.journalEntries.show.debitLines') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.journalEntries.show.account') }}</th>
                  <th>{{ t('accounting.journalEntries.show.costCenter') }}</th>
                  <th>{{ t('accounting.journalEntries.show.description') }}</th>
                  <th class="zv-table-num">{{ t('accounting.journalEntries.show.amount') }}</th>
                  <th class="ci-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.debit_lines" :key="idx">
                  <td>
                    <SelectBox v-if="!locked" v-model="line.chart_of_account_id" :options="accounts" value-key="id" :label-fn="a => `${a.code} — ${a.name_en}`" :clearable="false" size="sm" />
                    <span v-else class="zv-code">{{ accountLabel(line.chart_of_account_id) }}</span>
                  </td>
                  <td>
                    <SelectBox v-if="!locked" v-model="line.cost_center_id" :options="costCenters" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.journalEntries.show.none')" size="sm" />
                    <span v-else>{{ costCenterLabel(line.cost_center_id) || '—' }}</span>
                  </td>
                  <td><input v-if="!locked" v-model="line.description" type="text" class="zv-input"/><span v-else>{{ line.description || '—' }}</span></td>
                  <td class="zv-table-num"><input v-if="!locked" v-model="line.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" style="width:8rem;"/><span v-else>{{ fmt(line.amount) }}</span></td>
                  <td class="ci-th-actions">
                    <button v-if="!locked" type="button" class="zv-btn-remove-row" :title="t('accounting.journalEntries.show.removeLine')" @click="removeLine(form.debit_lines, idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button v-if="!locked" type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine(form.debit_lines)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('accounting.journalEntries.show.addLine') }}
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
            <div class="form-section-title">{{ t('accounting.journalEntries.show.creditLines') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('accounting.journalEntries.show.account') }}</th>
                  <th>{{ t('accounting.journalEntries.show.costCenter') }}</th>
                  <th>{{ t('accounting.journalEntries.show.description') }}</th>
                  <th class="zv-table-num">{{ t('accounting.journalEntries.show.amount') }}</th>
                  <th class="ci-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.credit_lines" :key="idx">
                  <td>
                    <SelectBox v-if="!locked" v-model="line.chart_of_account_id" :options="accounts" value-key="id" :label-fn="a => `${a.code} — ${a.name_en}`" :clearable="false" size="sm" />
                    <span v-else class="zv-code">{{ accountLabel(line.chart_of_account_id) }}</span>
                  </td>
                  <td>
                    <SelectBox v-if="!locked" v-model="line.cost_center_id" :options="costCenters" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.journalEntries.show.none')" size="sm" />
                    <span v-else>{{ costCenterLabel(line.cost_center_id) || '—' }}</span>
                  </td>
                  <td><input v-if="!locked" v-model="line.description" type="text" class="zv-input"/><span v-else>{{ line.description || '—' }}</span></td>
                  <td class="zv-table-num"><input v-if="!locked" v-model="line.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" style="width:8rem;"/><span v-else>{{ fmt(line.amount) }}</span></td>
                  <td class="ci-th-actions">
                    <button v-if="!locked" type="button" class="zv-btn-remove-row" :title="t('accounting.journalEntries.show.removeLine')" @click="removeLine(form.credit_lines, idx)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button v-if="!locked" type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="addLine(form.credit_lines)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ t('accounting.journalEntries.show.addLine') }}
          </button>
          <p v-if="form.errors.credit_lines" class="zv-field-error mt-2">{{ form.errors.credit_lines }}</p>
        </div>
      </div>

      <!-- ── Totals / balance check (only meaningful once editable) ── -->
      <div v-if="!locked" class="zv-card mb-5">
        <div class="je-totals-row">
          <div class="je-totals-item">
            <span class="je-totals-label">{{ t('accounting.journalEntries.show.totalDebit') }}</span>
            <span class="je-totals-value">{{ fmt(totalDebit) }}</span>
          </div>
          <div class="je-totals-item">
            <span class="je-totals-label">{{ t('accounting.journalEntries.show.totalCredit') }}</span>
            <span class="je-totals-value">{{ fmt(totalCredit) }}</span>
          </div>
          <div class="je-totals-item">
            <span v-if="isBalanced" class="je-balanced">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              {{ t('accounting.journalEntries.show.balanced') }}
            </span>
            <span v-else class="je-unbalanced">{{ t('accounting.journalEntries.show.outOfBalanceBy') }} {{ fmt(difference) }}</span>
          </div>
        </div>
      </div>

      <!-- ── Actions ───────────────────────────────────────────── -->
      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.journal-entries.index')" class="zv-btn zv-btn-ghost">{{ t('common.close') }}</Link>

          <!-- Locked (posted) → offer Set to Draft -->
          <button v-if="locked" type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft">
            {{ t('accounting.journalEntries.show.setToDraft') }}
          </button>

          <!-- Unlocked (reversed) → Delete or post the edited replacement -->
          <template v-else>
            <button type="button" class="zv-btn zv-btn-danger-ghost" @click="confirmDelete">
              {{ t('accounting.journalEntries.show.delete') }}
            </button>
            <button type="submit" :disabled="form.processing || !isBalanced" class="zv-btn zv-btn-primary">
              {{ form.processing ? t('accounting.journalEntries.show.posting') : t('accounting.journalEntries.show.postNewEntry') }}
            </button>
          </template>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { sum as sumMoney, sub as subMoney } from '@/Utils/money'
import { computed } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'
import StatusBadge from '@/Components/StatusBadge.vue'

const { t } = useI18n()

const props = defineProps({
  journalEntry: { type: Object, required: true },
  accounts:     { type: Array, default: () => [] },
  costCenters:  { type: Array, default: () => [] },
})

const JE_STATUS_BADGES = {
  posted:   { label: 'Posted',   badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
  reversed: { label: 'Reversed', badge: 'zv-badge-gray',  dot: 'zv-status-dot-gray'  },
}

// Locked whenever the entry is still 'posted' — the moment Set to
// Draft succeeds, the server-driven reload (see confirmSetToDraft)
// brings journalEntry.status back as 'reversed' and this flips on
// its own; nothing here tracks lock state independently.
const locked = computed(() => props.journalEntry.status === 'posted')

function accountLabel(id) {
  const a = props.accounts.find(a => a.id === id)
  return a ? `${a.code} — ${a.name_en}` : '—'
}
function costCenterLabel(id) {
  const c = props.costCenters.find(c => c.id === id)
  return c ? `${c.code} — ${c.name_en}` : null
}

function linesFor(sign) {
  return props.journalEntry.lines
    .filter(l => Number(l[sign]) > 0)
    .map(l => ({
      chart_of_account_id: l.chart_of_account_id,
      cost_center_id:      l.cost_center_id,
      amount:              l[sign],
      description:         l.description,
    }))
}

const form = useForm({
  entry_date:   props.journalEntry.entry_date,
  narration:    props.journalEntry.narration,
  debit_lines:  linesFor('debit'),
  credit_lines: linesFor('credit'),
})

function blankLine() {
  return { chart_of_account_id: '', cost_center_id: '', amount: '', description: '' }
}
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
const isBalanced  = computed(() => totalDebit.value > 0 && difference.value < 0.005)

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// ── Set to Draft ─────────────────────────────────────────────────
// Same proven pattern as Customer Invoice's confirmSetToDraft(): a
// PATCH landing back on the page it's already displaying updates
// props in place rather than re-running setup(), so a full reload is
// forced on success — guarded to skip when the server rejected the
// request (e.g. closed period), so that flash error isn't wiped
// before it's ever seen.
function confirmSetToDraft() {
  if (! confirm(t('accounting.journalEntries.show.reverseThisEntryAndOpenItForEditing'))) return

  router.patch(route('accounting.journal-entries.set-to-draft', props.journalEntry.id), {}, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

// ── Delete (discard) ─────────────────────────────────────────────
// The reversal already happened when Set to Draft was clicked —
// Delete does nothing further on the server, it just leaves.
function confirmDelete() {
  if (! confirm(t('accounting.journalEntries.show.discardTheseEditsThisEntryStaysReversed'))) return
  router.visit(route('accounting.journal-entries.index'))
}

// ── Post New Entry ────────────────────────────────────────────────
function submit() {
  form.post(route('accounting.journal-entries.store'))
}
</script>

<style>
.ci-th-actions { width: 3rem; text-align: end; }

/* Not global — several other pages (e.g. LetterOfCreditIndex.vue)
   each define their own copy of this small utility rather than
   relying on it being loaded from elsewhere; same approach here. */
.zv-btn-danger-ghost { background-color: transparent; color: var(--zv-danger); border-color: transparent; }
.zv-btn-danger-ghost:hover { background-color: var(--zv-danger-light); color: var(--zv-danger-text); }
.zv-btn-danger-ghost:disabled { color: var(--zv-text-muted); cursor: not-allowed; opacity: 0.5; }
.zv-btn-danger-ghost:disabled:hover { background-color: transparent; }

.je-show-banner {
  background: rgba(255, 191, 0, 0.12);
  border: 1px solid rgba(255, 191, 0, 0.35);
  color: #8a6300;
  font-size: 0.8125rem;
  font-weight: 600;
  padding: 0.75rem 1rem;
  border-radius: 0.5rem;
  margin-bottom: 1.25rem;
}

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
