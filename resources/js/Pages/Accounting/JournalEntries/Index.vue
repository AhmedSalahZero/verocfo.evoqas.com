<!--
══════════════════════════════════════════════════════════════════
  Accounting/JournalEntries/Index.vue

  Journal Entry viewer (July 2026 — Point 6 from the cross-audit
  discussion), extended with Manual Journal Entry (September 2026,
  per Mahmoud). List entries (date, entry number, narration, type,
  source document, total), a "View" button beside the status badge
  opens a modal with that entry's debit/credit lines — replaced the
  original click-row-to-expand-inline design (August 2026, per
  Mahmoud) since a dedicated button is a clearer, more discoverable
  affordance than an implicit whole-row click.

  "Create Manual Journal" (new) links to the Form page — the one
  place a person builds a journal entry by hand instead of a
  document doing it automatically. Every entry now carries a Type
  (Automated / Manual), shown as a column and filterable — set once,
  automatically, by JournalEntryService::post() itself; nothing on
  this page decides it.

  @layout    AppLayout
  @route     accounting.journal-entries.index
  @props     journalEntries (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.journalEntries.index.journalEntries')"
      :subtitle="t('accounting.journalEntries.index.everyEntryTheSystemHasPosted')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.journal-entries.create')" class="zv-btn zv-btn-primary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
          {{ t('accounting.journalEntries.index.createManualJournal') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="journalEntries.total"
        results-label="entry"
        results-label-plural="entries"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.journalEntries.index.searchByEntryNumberOrNarration')" @input="debouncedSearch" />
        <SelectBox v-model="typeFilter" :options="['automated', 'manual']" :value-fn="s => s" :label-fn="s => typeLabel(s)" :placeholder="t('accounting.journalEntries.index.allTypes')" @change="applyFilters" />
        <SelectBox v-model="sourceFilter" :options="sourceOptions" :value-fn="s => s" :label-fn="s => sourceLabel(s)" :placeholder="t('accounting.journalEntries.index.allSources')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="journalEntries.data.length === 0"
        :title="hasActiveFilters ? t('accounting.journalEntries.index.noJournalEntriesMatchThisFilter') : t('accounting.journalEntries.index.noJournalEntriesPostedYet')"
        :message="hasActiveFilters ? t('accounting.journalEntries.index.tryADifferentSourceOrClear') : t('accounting.journalEntries.index.entriesAppearHereAutomaticallyAsDocuments')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.journalEntries.index.entry') }}</th>
              <th>{{ t('accounting.journalEntries.index.date') }}</th>
              <th>{{ t('accounting.journalEntries.index.narration') }}</th>
              <th>{{ t('accounting.journalEntries.index.type') }}</th>
              <th>{{ t('accounting.journalEntries.index.source') }}</th>
              <th>{{ t('accounting.journalEntries.index.partner') }}</th>
              <th class="zv-table-num">{{ t('accounting.journalEntries.index.total') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="je in journalEntries.data" :key="je.id">
              <td><span class="zv-code">{{ je.entry_number }}</span></td>
              <td>{{ je.entry_date }}</td>
              <td><div class="zv-cell-clamp">{{ je.narration }}</div></td>
              <td>
                <span class="zv-tag" :class="je.journal_type === 'manual' ? 'je-type-manual' : 'je-type-automated'">{{ typeLabel(je.journal_type) }}</span>
              </td>
              <td>
                <span class="zv-tag">{{ sourceLabel(je.source_type) }}</span>
              </td>
              <td><PartnerCell :name="je.partner_name" /></td>
              <td class="zv-table-num">{{ fmt(je.total_debit) }}</td>
              <td class="text-center">
                <StatusBadge :status="je.status" :overrides="JE_STATUS_BADGES" />
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <!-- Editable "View" page only for a manual entry still POSTED, and never
                       for a reversal record itself — everything else (already reversed, or a
                       reversal entry) is read-only-peek only, so a reversed entry can't be
                       re-entered from the list and re-edited into a duplicate replacement. -->
                  <Link v-if="canOpenForEditing(je)" :href="route('accounting.journal-entries.show', je.id)" class="zv-btn zv-btn-secondary zv-btn-sm">
                    {{ t('accounting.journalEntries.index.view') }}
                  </Link>
                  <button v-else type="button" class="zv-action-btn" :title="t('accounting.journalEntries.index.view')" @click="openModal(je)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="journalEntries" />
    </div>

    <!-- ══════════════════ View modal ══════════════════ -->
    <Teleport to="body">
      <div v-if="modalEntry" class="zv-modal-backdrop zv-acc" @click.self="closeModal">
        <div class="zv-modal zv-modal-xl je-entry-modal">
          <div class="zv-modal-header">
            <div>
              <div class="zv-modal-title">
                {{ t('accounting.journalEntries.index.journalEntry') }}
                <span class="zv-code">{{ modalEntry.entry_number }}</span>
              </div>
              <p class="zv-page-subtitle">
                {{ modalEntry.entry_date }} · {{ modalEntry.narration }}
                <span class="zv-tag" :class="modalEntry.journal_type === 'manual' ? 'je-type-manual' : 'je-type-automated'">{{ typeLabel(modalEntry.journal_type) }}</span>
                <span class="zv-tag">{{ sourceLabel(modalEntry.source_type) }}</span>
              </p>
            </div>
            <button type="button" class="zv-modal-close" @click="closeModal" :title="t('common.close')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <div class="zv-modal-body je-entry-modal-body">
            <div v-if="modalLoading" class="zv-empty">
              <div class="zv-empty-sub">{{ t('common.loading') }}</div>
            </div>

            <div v-else-if="modalLines" class="zv-table-wrap">
              <table class="zv-table">
                <thead>
                  <tr>
                    <th>{{ t('accounting.journalEntries.index.account') }}</th>
                    <th>{{ t('accounting.journalEntries.index.description') }}</th>
                    <th class="zv-table-num">{{ t('accounting.journalEntries.index.debit') }}</th>
                    <th class="zv-table-num">{{ t('accounting.journalEntries.index.credit') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(line, idx) in modalLines" :key="idx">
                    <td>
                      <span class="zv-code">{{ line.account_code }}</span>
                      <span class="zv-account-name">{{ line.account_name }}</span>
                    </td>
                    <td>{{ line.description }}</td>
                    <td class="zv-table-num">{{ line.debit !== '0.00' ? fmt(line.debit) : '—' }}</td>
                    <td class="zv-table-num">{{ line.credit !== '0.00' ? fmt(line.credit) : '—' }}</td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr class="zv-summary-row">
                    <td colspan="2">
                      <span class="je-balanced">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ t('accounting.journalEntries.index.balanced') }}
                      </span>
                    </td>
                    <td class="zv-table-num">{{ fmt(lineTotals(modalLines).debit) }}</td>
                    <td class="zv-table-num">{{ fmt(lineTotals(modalLines).credit) }}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { router, Link } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'
import PartnerCell from '@/Components/PartnerCell.vue'

const { t } = useI18n()

const props = defineProps({
  journalEntries: { type: Object, required: true },
  filters:        { type: Object, default: () => ({}) },
})

const JE_STATUS_BADGES = {
  posted:   { label: 'Posted',   badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
  reversed: { label: 'Reversed', badge: 'zv-badge-gray',  dot: 'zv-status-dot-gray'  },
}

// Every source_type string actually used across the codebase's
// auto-posting services (grepped from every `sourceType:` call site).
// Kept as a real list rather than derived from the DB so the filter
// dropdown works even for a company with zero entries of a given
// type yet.
const sourceOptions = [
  'accrual', 'cash_expense_payment', 'other_collection', 'other_payment',
  'customer_invoice', 'credit_note', 'delivery_note', 'customer_payment',
  'customer_payment_allocation', 'customer_payment_unapplied',
  'supplier_bill', 'supplier_bill_line', 'supplier_payment',
  'supplier_payment_allocation', 'supplier_payment_unapplied',
  'goods_receipt_note', 'fixed_asset',
  'lg_issuance', 'lc_issuance', 'lc_issuance_expense',
  'leasing_contract', 'lease_amortization_line',
  'factoring_transaction', 'factoring_collection', 'factoring_rejection',
  'reverse_factoring_txn', 'reverse_factoring_settle',
  'cheque_payable', 'cheque_receivable',
  'bank_account_td_event', 'buy_sell_currency', 'internal_money_transfer',
  'fx_revaluation', 'fx_revaluation_reversal', // Audit H12
  'income_tax', 'fixed_asset_disposal', 'fixed_asset_impairment', // Audit H19 / H17
  'revenue_schedule_release', 'sales_return', 'purchase_return', // Audit H18 / H16
  'reversal', 'manual',
]

// Bug fix: this used to be a 3-entry map with anything unmapped
// falling straight through to t(type) — passing a raw string like
// 'customer_invoice' to the translator as if it were a key, which
// silently renders as the untranslated key itself (the underscored
// text seen in the screenshot). Replaced with a generic, future-proof
// formatter: Title Case each underscore-separated word, except a
// short list of real acronyms (LG, LC, TD, etc.) which get fully
// upper-cased instead of "Lg"/"Td". Covers every source_type above
// correctly, including ones added after this file was last touched —
// no map to maintain going forward.
const ACRONYMS = new Set(['lg', 'lc', 'grn', 'grni', 'po', 'so', 'ap', 'ar', 'wht', 'vat', 'td', 'cp', 'mtl', 'coa', 'fx'])
function sourceLabel(type) {
  if (! type) return '—'
  return type
    .split('_')
    .map(word => ACRONYMS.has(word) ? word.toUpperCase() : word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

// journal_type is only ever 'automated' or 'manual' (set by
// JournalEntryService::post() itself) — a fixed two-value label, not
// the generic source_type formatter above.
function typeLabel(type) {
  if (type === 'manual') return t('accounting.journalEntries.index.manual')
  if (type === 'automated') return t('accounting.journalEntries.index.automated')
  return '—'
}

// The editable "View" page (Set to Draft, edit-and-repost) is only
// offered for a manual entry that is STILL POSTED, and never for a
// reversal record itself. Anything already reversed, or a reversal
// entry, drops to the read-only-peek modal instead — otherwise a
// reversed entry stays re-enterable from the list forever, and
// re-editing it again posts a second, duplicate replacement with no
// warning (the exact mistake this restricts).
function canOpenForEditing(je) {
  return je.journal_type === 'manual' && je.status === 'posted' && je.source_type !== 'reversal'
}

const searchTerm   = ref(props.filters?.search ?? '')
const sourceFilter = ref(props.filters?.source_type ?? '')
const typeFilter   = ref(props.filters?.journal_type ?? '')
const hasActiveFilters = computed(() => !!searchTerm.value || !!sourceFilter.value || !!typeFilter.value)

let searchTimer = null
function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.journal-entries.index'), {
    search: searchTerm.value || undefined,
    source_type: sourceFilter.value || undefined,
    journal_type: typeFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  searchTerm.value = ''
  sourceFilter.value = ''
  typeFilter.value = ''
  applyFilters()
}

// ── View modal — replaces the old click-row-to-expand design
//    (August 2026, per Mahmoud). Lines are fetched on first open per
//    entry and cached in a plain object keyed by entry id, so
//    reopening the same entry later doesn't re-fetch. ───────────────

const modalEntry   = ref(null)
const modalLoading = ref(false)
const linesCache    = {}
const modalLines    = computed(() => modalEntry.value ? linesCache[modalEntry.value.id] ?? null : null)

async function openModal(je) {
  modalEntry.value = je
  if (! linesCache[je.id]) {
    modalLoading.value = true
    try {
      const res = await window.axios.get(route('accounting.journal-entries.lines', je.id))
      linesCache[je.id] = res.data.lines
    } finally {
      modalLoading.value = false
    }
  }
}

function closeModal() {
  modalEntry.value = null
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// Debit/credit totals for the modal's footer — purely a display
// reassurance (the backend already guarantees every posted entry
// balances via UnbalancedJournalEntryException), not a validation step.
function lineTotals(entryLines) {
  return entryLines.reduce((acc, line) => {
    acc.debit += Number(line.debit || 0)
    acc.credit += Number(line.credit || 0)
    return acc
  }, { debit: 0, credit: 0 })
}
</script>

<style>
.je-entry-modal { overflow: hidden; }
.je-entry-modal .zv-modal-header { align-items: flex-start; }
.je-entry-modal-body { padding: 0; }
.je-entry-modal-body .zv-table-wrap {
  border-inline: none;
  border-bottom: none;
  border-radius: 0;
}

.je-balanced {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  color: var(--zv-num-green, #047857);
  font-size: 0.6875rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.zv-modal-title .zv-code { margin-inline-start: 0.5rem; }
.zv-page-subtitle .zv-tag { margin-inline-start: 0.5rem; vertical-align: middle; }

/* Type badge — Manual gets the gold accent (ZAVERO's own "needs a
   human" signal, same gold used for active nav state elsewhere);
   Automated stays a plain neutral tag, same as Source. */
.je-type-manual {
  background: rgba(255, 191, 0, 0.14);
  color: #8a6300;
  font-weight: 700;
}
.je-type-automated {
  opacity: 0.75;
}
</style>