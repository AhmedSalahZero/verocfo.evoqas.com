<!--
══════════════════════════════════════════════════════════════════
  Accounting/ChequesPayable/Index.vue

  Lists every cheque WE write to a supplier and its lifecycle status.
  A cheque is created automatically when a Supplier Payment is
  recorded with Money Type = Payable Cheque; this page manages
  everything that happens to it afterward.

  Deliberately simpler than Cheques Receivable (confirmed by Mahmoud —
  Mark as Paid + Reset only, no send-for-collection or bounce
  handling):

    issued → Mark as Paid (real cash leaves — from the source account
              already fixed on the parent Supplier Payment at issue
              time, nothing to pick here) → paid
    paid   → Reset (undoes a mistake — reverses the real journal
              entry, back to issued)

  No "drawee bank" or "receiving safe" columns — those are receivable-
  side concepts that don't apply here: the paying account is already
  fixed on the parent payment, not decided later.

  @layout    AppLayout
  @route     accounting.cheques-payable.index
  @props     cheques (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.chequesPayable.index.chequesPayable')"
      :subtitle="t('accounting.chequesPayable.index.everyChequeIssuedToASupplier')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>'
    />

    <div class="zv-card">

      <FilterBar :has-active-filters="!!statusFilter" :results-count="cheques.total" results-label="Cheque" @clear="clearFilters">
        <SelectBox v-model="statusFilter" :options="[{ value: 'issued', label: t('accounting.chequesPayable.index.issued') }, { value: 'paid', label: t('accounting.chequesPayable.index.paid') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="cheques.data.length === 0"
        :title="statusFilter ? t('accounting.chequesPayable.index.noChequesMatchThisFilter') : t('accounting.chequesPayable.index.noChequesIssuedYet')"
        :message="t('accounting.chequesPayable.index.chequesAppearHereAutomaticallyOnceA')"
        icon-path="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"
      >
        <template v-if="statusFilter" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.chequesPayable.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.chequesPayable.index.cheque') }}</th>
              <th>{{ t('common.partner') }}</th>
              <th>{{ t('accounting.chequesPayable.index.payment') }}</th>
              <th class="zv-table-num">{{ t('common.amount') }}</th>
              <th>{{ t('accounting.chequesPayable.index.dueDate') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="cheque in cheques.data" :key="cheque.id">
              <td><span class="zv-code">{{ cheque.cheque_number }}</span></td>
              <td><PartnerCell :name="cheque.partner?.name_en" /></td>
              <td>
                <span class="zv-code">{{ cheque.supplier_payment?.payment_code ?? cheque.other_payment?.payment_code ?? cheque.cash_expense_payment?.payment_code }}</span>
                <span class="zv-tag" :class="sourceTagClass(cheque)">
                  {{ sourceLabel(cheque) }}
                </span>
              </td>
              <td class="zv-table-num">{{ fmt(cheque.cheque_amount) }}</td>
              <td>{{ cheque.due_date }}</td>
              <td class="text-center"><span class="cr-status-chip" :class="`cr-status-${cheque.status}`">{{ statusLabel(cheque.status) }}</span></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <button v-if="cheque.status === 'issued'" type="button" class="zv-btn zv-btn-success zv-btn-sm" @click="openPayModal(cheque)">{{ t('accounting.chequesPayable.index.markAsPaid') }}</button>
                  <button v-if="cheque.status === 'issued'" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="voidCheque(cheque)">Void / Stop</button>
                  <span v-if="cheque.void_reason" class="field-hint" :title="cheque.void_reason">{{ cheque.void_reason }}</span>
                  <button
                    v-if="cheque.status === 'paid'"
                    type="button" class="zv-action-btn" :disabled="resetting === cheque.id"
                    :title="resetting === cheque.id ? t('accounting.chequesPayable.index.resetting') : t('accounting.chequesPayable.index.reset')"
                    @click="confirmReset(cheque)"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M4 9a9 9 0 0115.5 3M20 20v-5h-5M20 15a9 9 0 01-15.5-3"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="cheques" />
    </div>

    <!-- ══════════════════ Mark as Paid modal ══════════════════ -->
    <Teleport to="body">
      <div v-if="payModal.cheque" class="cr-modal-overlay zv-acc" @click.self="closeModals">
        <div class="cr-modal">
          <h3 class="cr-modal-title">{{ t('accounting.chequesPayable.index.markAsPaid') }} — {{ payModal.cheque.cheque_number }}</h3>
          <p class="cr-modal-hint">{{ t('accounting.chequesPayable.index.postsTheRealCashLeavingFrom') }}</p>

          <div v-if="modalError" class="zv-alert zv-alert-danger mb-3">{{ modalError }}</div>

          <div class="field-group mb-4">
            <label class="zv-label zv-label-required">{{ t('accounting.chequesPayable.index.paymentDate') }}</label>
            <input v-model="paymentDate" type="date" :min="payModal.cheque.due_date" class="zv-input" :class="{ 'zv-input-error': paymentDateTooEarly }"/>
            <p class="field-hint">{{ t('accounting.chequesPayable.index.due') }}: {{ payModal.cheque.due_date }} — {{ t('accounting.chequesPayable.index.aChequeCannotLegallyClearBefore') }}</p>
            <p v-if="paymentDateTooEarly" class="zv-field-error">{{ t('accounting.chequesPayable.index.thisDateIsBeforeTheChequeS') }}</p>
          </div>

          <div class="cr-modal-actions">
            <button type="button" class="zv-btn zv-btn-ghost" @click="closeModals">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="submitting || paymentDateTooEarly" @click="submitMarkAsPaid">{{ t('accounting.chequesPayable.index.confirmPaid') }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'
import PartnerCell from '@/Components/PartnerCell.vue'

const { t } = useI18n()

const props = defineProps({
  cheques: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

const statusFilter = ref(props.filters?.status ?? '')

function applyFilters() {
  router.get(route('accounting.cheques-payable.index'), { status: statusFilter.value || undefined }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  statusFilter.value = ''
  applyFilters()
}

function sourceLabel(cheque) {
  if (cheque.ob_note_payable_id) return t('accounting.chequesPayable.index.openingBalance')
  if (cheque.cash_expense_payment) return t('accounting.chequesPayable.index.expense')
  if (cheque.other_payment) return t('accounting.chequesPayable.index.other')
  return t('accounting.chequesPayable.index.supplier')
}

function sourceTagClass(cheque) {
  if (cheque.ob_note_payable_id) return 'zv-tag-gold'
  if (cheque.cash_expense_payment) return 'zv-tag-gold'
  if (cheque.other_payment) return 'zv-tag-gray'
  return ''
}

function statusLabel(status) {
  const map = { issued: 'Issued', paid: 'Paid', void: 'Void', stopped: 'Stopped' }
  return t(map[status] ?? status)
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function csrfHeaders() {
  return {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
    'Accept': 'application/json',
  }
}

// ── Mark as Paid modal ────────────────────────────────────────────

const payModal    = ref({ cheque: null })
const modalError  = ref('')
const submitting  = ref(false)
const paymentDate = ref(todayLocal())

function openPayModal(cheque) {
  modalError.value = ''
  // Default to today, unless the due date is still in the future — in
  // which case default to the due date itself, since today would fail
  // the validation the moment the modal opens.
  const today = todayLocal()
  paymentDate.value = cheque.due_date > today ? cheque.due_date : today
  payModal.value = { cheque }
}

const paymentDateTooEarly = computed(() =>
  !!payModal.value.cheque && paymentDate.value < payModal.value.cheque.due_date
)

function closeModals() {
  payModal.value = { cheque: null }
}

function submitMarkAsPaid() {
  submitting.value = true
  fetch(route('accounting.cheques-payable.mark-as-paid', payModal.value.cheque.id), {
    method: 'POST', headers: csrfHeaders(), body: JSON.stringify({ payment_date: paymentDate.value }),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { modalError.value = data.message || t('accounting.chequesPayable.index.somethingWentWrong'); return }
      closeModals()
      router.reload({ preserveScroll: true })
    })
    .finally(() => { submitting.value = false })
}

// Unlike a plain status revert, this DOES reverse a real journal entry —
// the backend handles that (JournalEntryService::reverse(), never a
// delete); this button just triggers it and warns the person plainly
// what it does.
// M-31: cancel (void) or stop an issued cheque. Asks for a reason.
function voidCheque(cheque) {
  const stopped = confirm('OK = Stopped at the bank (lost / disputed).\nCancel = choose "Void" instead (torn up, never presented).')
  const kind = stopped ? 'stopped' : 'void'
  const reason = prompt('Reason for marking this cheque as ' + kind + ':')
  if (!reason) return
  fetch(route('accounting.cheques-payable.void', cheque.id), {
    method: 'POST', headers: csrfHeaders(), body: JSON.stringify({ kind, reason, date: todayLocal() }),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { alert(data.message || t('accounting.chequesPayable.index.somethingWentWrong')); return }
      alert(data.message)
      router.reload({ preserveScroll: true })
    })
}

const resetting = ref(null)
function confirmReset(cheque) {
  if (! confirm(t('accounting.chequesPayable.index.resetThisChequeBackToIssued'))) return
  resetting.value = cheque.id
  fetch(route('accounting.cheques-payable.reset-payment', cheque.id), {
    method: 'POST', headers: csrfHeaders(),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { alert(data.message || t('accounting.chequesPayable.index.somethingWentWrong')); return }
      router.reload({ preserveScroll: true })
    })
    .finally(() => { resetting.value = null })
}
</script>

<style>
.cr-status-chip { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.cr-status-issued { background: rgba(148,163,184,0.15); color: #64748b; }
.cr-status-paid   { background: rgba(16,185,129,0.12); color: #10b981; }
.cr-status-void, .cr-status-stopped { background: rgba(239,68,68,0.12); color: #ef4444; }

.cr-modal-overlay {
  position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 100;
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.cr-modal { background: var(--vero-card); border-radius: 12px; padding: 24px; width: 100%; max-width: 440px; }
.cr-modal-title { font-size: 15px; font-weight: 700; margin: 0 0 4px; }
.cr-modal-hint { font-size: 12.5px; color: var(--zv-text-secondary); margin: 0 0 16px; }
.cr-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px; }
</style>