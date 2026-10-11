<!--
══════════════════════════════════════════════════════════════════
  Accounting/CustomerCredits/Index.vue

  Customer Credits — the money the company owes a customer after a
  Credit Note worth more than its invoice still owed (normally
  because the invoice was already paid). Each row is one such surplus.
  It can be:
    • APPLIED to one of the customer's open invoices, or
    • REFUNDED in cash from a safe / bank account (if the customer had
      already withheld tax, the matching withholding is given up and
      only the rest is paid in cash).
  Both can be undone. Expand a row to see what was done with it.

  Everything here is in the company's main currency.

  @layout    AppLayout
  @route     accounting.customer-credits.index
  @props     balances (paginator), filters, totalOpen, customers,
             bankAccounts, safeAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.customerCredits.index.customerCredits')"
      :subtitle="t('accounting.customerCredits.index.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h12a2 2 0 012 2v1h1a1 1 0 011 1v3a1 1 0 01-1 1h-1v3a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>'
    />

    <div class="zv-card cc-total">
      <span class="cc-total-label">{{ t('accounting.customerCredits.index.totalOwed') }}</span>
      <span class="cc-total-value">{{ fmt(totalOpen) }}</span>
    </div>

    <div class="zv-card">
      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="balances.total"
        :results-label="t('accounting.customerCredits.index.creditBalance')"
        @clear="clearFilters"
      >
        <input v-model="searchTerm" type="text" class="zv-input" :placeholder="t('accounting.customerCredits.index.searchByCode')" @input="debouncedSearch" />
        <SelectBox v-model="partnerFilter" :options="customers" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.customerCredits.index.allCustomers')" @change="applyFilters" />
        <SelectBox v-model="statusFilter" :options="statusOptions" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="balances.data.length === 0"
        :title="t('accounting.customerCredits.index.nothingHere')"
        :message="t('accounting.customerCredits.index.nothingHereHint')"
        icon-path="M3 7a2 2 0 012-2h12a2 2 0 012 2v1h1a1 1 0 011 1v3a1 1 0 01-1 1h-1v3a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.customerCredits.index.code') }}</th>
              <th>{{ t('common.customer') }}</th>
              <th>{{ t('accounting.customerCredits.index.creditNote') }}</th>
              <th>{{ t('accounting.customerCredits.index.date') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerCredits.index.original') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerCredits.index.applied') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerCredits.index.refunded') }}</th>
              <th class="zv-table-num">{{ t('accounting.customerCredits.index.remaining') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="b in balances.data" :key="b.id">
              <tr>
                <td><span class="zv-code">{{ b.balance_code }}</span></td>
                <td><PartnerCell :name="b.partner?.name_en" /></td>
                <td>
                  <span class="zv-tag zv-tag-gold">{{ b.credit_note?.credit_note_code }}</span>
                  <span class="cc-sub">{{ b.customer_invoice?.invoice_code }}</span>
                </td>
                <td>{{ String(b.credit_date).slice(0, 10) }}</td>
                <td class="zv-table-num">{{ fmt(b.original_amount) }}</td>
                <td class="zv-table-num">{{ fmt(b.applied_amount) }}</td>
                <td class="zv-table-num">{{ fmt(b.refunded_amount) }}</td>
                <td class="zv-table-num"><strong>{{ fmt(b.remaining_amount) }}</strong></td>
                <td class="text-center"><StatusBadge :status="b.status" :overrides="BADGES" /></td>
                <td class="zv-col-actions">
                  <div class="zv-row-actions">
                    <button v-if="b.status === 'open'" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openApply(b)">{{ t('accounting.customerCredits.index.apply') }}</button>
                    <button v-if="b.status === 'open'" type="button" class="zv-btn zv-btn-primary zv-btn-sm" @click="openRefund(b)">{{ t('accounting.customerCredits.index.refund') }}</button>
                    <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="toggle(b.id)">
                      {{ expanded.has(b.id) ? t('accounting.customerCredits.index.hide') : t('accounting.customerCredits.index.history') }}
                    </button>
                  </div>
                </td>
              </tr>

              <tr v-if="expanded.has(b.id)" class="cc-detail-row">
                <td colspan="10">
                  <div class="cc-detail">
                    <h4>{{ t('accounting.customerCredits.index.applications') }}</h4>
                    <p v-if="!b.applications.length" class="cc-none">{{ t('accounting.customerCredits.index.noneYet') }}</p>
                    <table v-else class="zv-table">
                      <thead><tr>
                        <th>{{ t('accounting.customerCredits.index.invoice') }}</th>
                        <th>{{ t('accounting.customerCredits.index.date') }}</th>
                        <th class="zv-table-num">{{ t('accounting.customerCredits.index.amount') }}</th>
                        <th class="text-center">{{ t('common.status') }}</th>
                        <th></th>
                      </tr></thead>
                      <tbody>
                        <tr v-for="a in b.applications" :key="'a' + a.id">
                          <td><span class="zv-tag zv-tag-gold">{{ a.customer_invoice?.invoice_code }}</span></td>
                          <td>{{ String(a.application_date).slice(0, 10) }}</td>
                          <td class="zv-table-num">{{ fmt(a.amount) }}</td>
                          <td class="text-center"><StatusBadge :status="a.status" :overrides="BADGES" /></td>
                          <td class="text-right">
                            <button v-if="a.status === 'applied'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="undoApplication(a)">{{ t('accounting.customerCredits.index.undo') }}</button>
                          </td>
                        </tr>
                      </tbody>
                    </table>

                    <h4>{{ t('accounting.customerCredits.index.refunds') }}</h4>
                    <p v-if="!b.refunds.length" class="cc-none">{{ t('accounting.customerCredits.index.noneYet') }}</p>
                    <table v-else class="zv-table">
                      <thead><tr>
                        <th>{{ t('accounting.customerCredits.index.code') }}</th>
                        <th>{{ t('accounting.customerCredits.index.date') }}</th>
                        <th class="zv-table-num">{{ t('accounting.customerCredits.index.amount') }}</th>
                        <th class="zv-table-num">{{ t('accounting.customerCredits.index.whtGivenUp') }}</th>
                        <th class="zv-table-num">{{ t('accounting.customerCredits.index.paidInCash') }}</th>
                        <th class="text-center">{{ t('common.status') }}</th>
                        <th></th>
                      </tr></thead>
                      <tbody>
                        <tr v-for="r in b.refunds" :key="'r' + r.id">
                          <td><span class="zv-code">{{ r.refund_code }}</span></td>
                          <td>{{ String(r.refund_date).slice(0, 10) }}</td>
                          <td class="zv-table-num">{{ fmt(r.amount) }}</td>
                          <td class="zv-table-num">{{ fmt(r.wht_adjustment) }}</td>
                          <td class="zv-table-num">{{ fmt(r.cash_amount) }}</td>
                          <td class="text-center"><StatusBadge :status="r.status" :overrides="BADGES" /></td>
                          <td class="text-right">
                            <button v-if="r.status === 'posted'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="reverseRefund(r)">{{ t('accounting.customerCredits.index.reverse') }}</button>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="balances" />
    </div>

    <!-- Apply to an invoice -->
    <div v-if="applyOpen" class="zv-modal-backdrop zv-acc" @click.self="applyOpen = false">
      <div class="zv-modal zv-modal-sm">
        <div class="zv-modal-header">
          <div class="zv-modal-title">{{ t('accounting.customerCredits.index.applyToInvoice') }} — {{ current?.balance_code }}</div>
          <button type="button" class="zv-modal-close" @click="applyOpen = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        <div class="zv-modal-body">
          <p class="field-hint mb-3">{{ t('accounting.customerCredits.index.availableToUse') }}: <strong>{{ fmt(current?.remaining_amount) }}</strong></p>

          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.customerCredits.index.invoice') }}</label>
            <SelectBox
              v-model="applyForm.customer_invoice_id"
              :options="openInvoices" value-key="id"
              :label-fn="i => `${i.invoice_code} — ${t('accounting.customerCredits.index.stillOwed')}: ${fmt(i.amount_outstanding)}`"
              :placeholder="openInvoices.length ? t('accounting.customerCredits.index.chooseInvoice') : t('accounting.customerCredits.index.noOpenInvoices')"
              :clearable="false" @change="onInvoicePicked"
            />
            <p v-if="applyForm.errors.customer_invoice_id" class="zv-field-error">{{ applyForm.errors.customer_invoice_id }}</p>
          </div>

          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.customerCredits.index.amount') }}</label>
            <input v-model="applyForm.amount" type="number" step="0.01" min="0" class="zv-input" />
            <p v-if="applyForm.errors.amount" class="zv-field-error">{{ applyForm.errors.amount }}</p>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('accounting.customerCredits.index.date') }}</label>
            <input v-model="applyForm.application_date" type="date" class="zv-input" />
          </div>
        </div>
        <div class="zv-modal-footer">
          <button type="button" class="zv-btn zv-btn-ghost" @click="applyOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="applyForm.processing || !applyForm.customer_invoice_id" @click="submitApply">{{ t('accounting.customerCredits.index.apply') }}</button>
        </div>
      </div>
    </div>

    <!-- Cash refund -->
    <div v-if="refundOpen" class="zv-modal-backdrop zv-acc" @click.self="refundOpen = false">
      <div class="zv-modal zv-modal-sm">
        <div class="zv-modal-header">
          <div class="zv-modal-title">{{ t('accounting.customerCredits.index.cashRefund') }} — {{ current?.balance_code }}</div>
          <button type="button" class="zv-modal-close" @click="refundOpen = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        <div class="zv-modal-body">
          <p class="field-hint mb-3">{{ t('accounting.customerCredits.index.availableToUse') }}: <strong>{{ fmt(current?.remaining_amount) }}</strong></p>

          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.customerCredits.index.amountToRefund') }}</label>
            <input v-model="refundForm.amount" type="number" step="0.01" min="0" class="zv-input" @input="whtTouched = false" />
            <p v-if="refundForm.errors.amount" class="zv-field-error">{{ refundForm.errors.amount }}</p>
          </div>

          <div class="field-group mb-3">
            <label class="zv-label">{{ t('accounting.customerCredits.index.whtGivenUp') }}</label>
            <input v-model="refundForm.wht_adjustment" type="number" step="0.01" min="0" class="zv-input" @input="whtTouched = true" />
            <p class="field-hint">{{ t('accounting.customerCredits.index.whtHint') }}</p>
            <p v-if="refundForm.errors.wht_adjustment" class="zv-field-error">{{ refundForm.errors.wht_adjustment }}</p>
          </div>

          <div class="cc-cash-line">
            <span>{{ t('accounting.customerCredits.index.paidInCash') }}</span>
            <strong>{{ fmt(cashToPay) }}</strong>
          </div>

          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.customerCredits.index.payFrom') }}</label>
            <div class="zv-tile-group">
              <button type="button" class="zv-tile" :class="{ 'zv-tile-active': refundForm.source_type === 'cash_safe' }" @click="refundForm.source_type = 'cash_safe'">{{ t('accounting.customerCredits.index.cashSafe') }}</button>
              <button type="button" class="zv-tile" :class="{ 'zv-tile-active': refundForm.source_type === 'bank_account' }" @click="refundForm.source_type = 'bank_account'">{{ t('accounting.customerCredits.index.bankAccount') }}</button>
            </div>
          </div>

          <div v-if="refundForm.source_type === 'cash_safe'" class="field-group mb-3">
            <SelectBox v-model="refundForm.safe_account_id" :options="safeAccounts" value-key="id" :label-fn="a => `${a.code} — ${a.name_en}`" :placeholder="t('accounting.customerCredits.index.chooseSource')" :clearable="false" />
            <p v-if="refundForm.errors.safe_account_id" class="zv-field-error">{{ refundForm.errors.safe_account_id }}</p>
          </div>
          <div v-else class="field-group mb-3">
            <SelectBox v-model="refundForm.bank_account_id" :options="bankAccounts" value-key="id" :label-fn="a => `${a.account_name} (${a.account_number})`" :placeholder="t('accounting.customerCredits.index.chooseSource')" :clearable="false" />
            <p v-if="refundForm.errors.bank_account_id" class="zv-field-error">{{ refundForm.errors.bank_account_id }}</p>
          </div>

          <div class="field-group mb-3">
            <label class="zv-label">{{ t('accounting.customerCredits.index.date') }}</label>
            <input v-model="refundForm.refund_date" type="date" class="zv-input" />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.customerCredits.index.reference') }}</label>
            <input v-model="refundForm.reference" type="text" class="zv-input" />
          </div>
        </div>
        <div class="zv-modal-footer">
          <button type="button" class="zv-btn zv-btn-ghost" @click="refundOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="refundForm.processing" @click="submitRefund">{{ t('accounting.customerCredits.index.refund') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { router, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'
import PartnerCell from '@/Components/PartnerCell.vue'

const { t } = useI18n()

const props = defineProps({
  balances:     { type: Object, required: true },
  filters:      { type: Object, default: () => ({}) },
  totalOpen:    { type: [String, Number], default: '0.00' },
  customers:    { type: Array, default: () => [] },
  bankAccounts: { type: Array, default: () => [] },
  safeAccounts: { type: Array, default: () => [] },
})

const BADGES = {
  open:     { label: 'Open',     badge: 'zv-badge-amber', dot: 'zv-status-dot-amber' },
  closed:   { label: 'Closed',   badge: 'zv-badge-gray',  dot: 'zv-status-dot-gray' },
  reversed: { label: 'Reversed', badge: 'zv-badge-gray',  dot: 'zv-status-dot-gray' },
  applied:  { label: 'Applied',  badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
  posted:   { label: 'Paid',     badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
}

const statusOptions = computed(() => [
  { value: 'open', label: t('accounting.customerCredits.index.open') },
  { value: 'closed', label: t('accounting.customerCredits.index.closed') },
  { value: 'reversed', label: t('accounting.customerCredits.index.reversed') },
])

// ── Filters ────────────────────────────────────────────────────
const searchTerm = ref(props.filters?.search ?? '')
const partnerFilter = ref(props.filters?.partner_id ? Number(props.filters.partner_id) : '')
const statusFilter = ref(props.filters?.status ?? '')
const hasActiveFilters = computed(() => !!searchTerm.value || !!partnerFilter.value || !!statusFilter.value)

let timer = null
function debouncedSearch() { clearTimeout(timer); timer = setTimeout(applyFilters, 350) }
function applyFilters() {
  router.get(route('accounting.customer-credits.index'), {
    search: searchTerm.value || undefined,
    partner_id: partnerFilter.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}
function clearFilters() { searchTerm.value = ''; partnerFilter.value = ''; statusFilter.value = ''; applyFilters() }

// ── Row expand ─────────────────────────────────────────────────
const expanded = reactive(new Set())
function toggle(id) { expanded.has(id) ? expanded.delete(id) : expanded.add(id) }

// ── Apply ──────────────────────────────────────────────────────
const today = todayLocal()
const current = ref(null)
const applyOpen = ref(false)
const openInvoices = ref([])
const applyForm = useForm({ customer_invoice_id: '', amount: '', application_date: today })

async function openApply(b) {
  current.value = b
  applyForm.reset(); applyForm.clearErrors()
  applyForm.application_date = today
  applyForm.amount = b.remaining_amount
  openInvoices.value = []
  applyOpen.value = true
  try {
    const res = await fetch(route('accounting.customer-credits.open-invoices', b.id), { headers: { Accept: 'application/json' } })
    openInvoices.value = (await res.json()).invoices ?? []
  } catch (e) { openInvoices.value = [] }
}
function onInvoicePicked() {
  const inv = openInvoices.value.find(i => i.id === applyForm.customer_invoice_id)
  if (!inv) return
  const room = Math.min(Number(current.value.remaining_amount), Number(inv.amount_outstanding))
  applyForm.amount = room.toFixed(2)
}
function submitApply() {
  applyForm.post(route('accounting.customer-credits.apply', current.value.id), {
    preserveScroll: true,
    onSuccess: () => { applyOpen.value = false },
  })
}
function undoApplication(a) {
  if (!window.confirm(t('accounting.customerCredits.index.confirmUndo'))) return
  router.patch(route('accounting.customer-credits.undo-application', a.id), {}, { preserveScroll: true })
}

// ── Refund ─────────────────────────────────────────────────────
const refundOpen = ref(false)
const whtTouched = ref(false)
const refundForm = useForm({
  amount: '', wht_adjustment: '', source_type: 'cash_safe',
  safe_account_id: '', bank_account_id: '', refund_date: today, reference: '',
})

function defaultWht(amount) {
  const b = current.value
  if (!b) return 0
  const remaining = Number(b.remaining_amount)
  const pool = Number(b.wht_remaining)
  const a = Number(amount) || 0
  if (pool <= 0 || remaining <= 0) return 0
  return a >= remaining ? pool : Math.round((pool * a / remaining) * 100) / 100
}
watch(() => refundForm.amount, (v) => {
  if (!whtTouched.value && refundOpen.value) refundForm.wht_adjustment = defaultWht(v).toFixed(2)
})
const cashToPay = computed(() => Math.max(0, (Number(refundForm.amount) || 0) - (Number(refundForm.wht_adjustment) || 0)))

function openRefund(b) {
  current.value = b
  refundForm.reset(); refundForm.clearErrors()
  whtTouched.value = false
  refundForm.refund_date = today
  refundForm.source_type = 'cash_safe'
  refundOpen.value = true
  refundForm.amount = b.remaining_amount
  refundForm.wht_adjustment = defaultWht(b.remaining_amount).toFixed(2)
}
function submitRefund() {
  refundForm.transform((d) => ({
    ...d,
    safe_account_id: d.source_type === 'cash_safe' ? d.safe_account_id : null,
    bank_account_id: d.source_type === 'bank_account' ? d.bank_account_id : null,
  })).post(route('accounting.customer-credits.refund', current.value.id), {
    preserveScroll: true,
    onSuccess: () => { refundOpen.value = false },
  })
}
function reverseRefund(r) {
  const reason = window.prompt(t('accounting.customerCredits.index.reversePrompt'))
  if (!reason || reason.trim().length < 5) return
  router.patch(route('accounting.customer-credits.reverse-refund', r.id), { reversal_reason: reason.trim() }, { preserveScroll: true })
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style scoped>
.cc-total { display: flex; align-items: baseline; gap: 14px; padding: 14px 18px; margin-bottom: 14px; }
.cc-total-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--zv-text-muted, #94a3b8); }
.cc-total-value { font-size: 22px; font-weight: 700; color: var(--zv-text-primary, #0f172a); }
.cc-sub { margin-left: 8px; font-size: 12px; color: var(--zv-text-muted, #94a3b8); }
.cc-detail-row > td { background: var(--zv-surface-alt, #f8fafc); padding: 12px 18px; }
.cc-detail h4 { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin: 10px 0 6px; }
.cc-none { font-size: 13px; color: var(--zv-text-muted, #94a3b8); margin-bottom: 6px; }
.cc-cash-line { display: flex; justify-content: space-between; padding: 10px 12px; margin-bottom: 14px; border-radius: 8px; background: var(--zv-surface-alt, #f1f5f9); font-size: 14px; }
</style>
