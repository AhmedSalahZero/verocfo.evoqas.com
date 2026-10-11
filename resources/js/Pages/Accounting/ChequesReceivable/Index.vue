<!--
══════════════════════════════════════════════════════════════════
  Accounting/ChequesReceivable/Index.vue

  Lists every cheque received from a customer and its lifecycle
  status — see VeroCFO_Customer_Collection_Design_v1_0.md Section 6.
  A cheque is created automatically when a Customer Collection is
  recorded with Money Type = Cheque; this page manages everything
  that happens to it afterward:

    received → Send for Collection (no posting — picks which of our
               own accounts, Current or Overdraft, it'll clear into)
    sent_for_collection → Collect (real cash/OD paydown lands) or
               Reject (AR reopens)

  @layout    AppLayout
  @route     accounting.cheques-receivable.index
  @props     cheques (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.chequesReceivable.index.chequesReceivable')"
      :subtitle="t('accounting.chequesReceivable.index.everyChequeReceivedFromACustomer')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>'
    />

    <div class="zv-card">

      <FilterBar :has-active-filters="!!statusFilter" :results-count="cheques.total" results-label="Cheque" @clear="clearFilters">
        <SelectBox v-model="statusFilter" :options="[{ value: 'received', label: t('accounting.chequesReceivable.index.inSafe') }, { value: 'sent_for_collection', label: t('accounting.chequesReceivable.index.sentForCollection') }, { value: 'collected', label: t('accounting.chequesReceivable.index.collected') }, { value: 'rejected', label: t('accounting.chequesReceivable.index.rejected') }]" :placeholder="t('common.allStatuses')" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="cheques.data.length === 0"
        :title="statusFilter ? t('accounting.chequesReceivable.index.noChequesMatchThisFilter') : t('accounting.chequesReceivable.index.noChequesReceivedYet')"
        :message="t('accounting.chequesReceivable.index.chequesAppearHereAutomaticallyOnceA')"
        icon-path="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"
      >
        <template v-if="statusFilter" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.chequesReceivable.index.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.chequesReceivable.index.cheque') }}</th>
              <th>{{ t('accounting.chequesReceivable.index.draweeBank') }}</th>
              <th>{{ t('common.partner') }}</th>
              <th>{{ t('accounting.chequesReceivable.index.collection') }}</th>
              <th class="zv-table-num">{{ t('common.amount') }}</th>
              <th>{{ t('accounting.chequesReceivable.index.dueDate') }}</th>
              <th>{{ t('accounting.chequesReceivable.index.receivingSafe') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="cheque in cheques.data" :key="cheque.id">
              <td><span class="zv-code">{{ cheque.cheque_number }}</span></td>
              <td>{{ cheque.drawee_bank?.name_en ?? cheque.drawee_bank_name }}</td>
              <td><PartnerCell :name="cheque.partner?.name_en" /></td>
              <td>
                <span class="zv-code">{{ cheque.customer_payment?.payment_code ?? cheque.other_collection?.collection_code }}</span>
                <span v-if="cheque.ob_note_receivable_id" class="zv-tag zv-tag-gold">{{ t('accounting.chequesReceivable.index.openingBalance') }}</span>
                <span v-else class="zv-tag" :class="cheque.other_collection ? 'zv-tag-gray' : ''">
                  {{ cheque.other_collection ? t('accounting.chequesReceivable.index.other') : t('accounting.chequesReceivable.index.customer') }}
                </span>
              </td>
              <td class="zv-table-num">{{ fmt(cheque.cheque_amount) }}</td>
              <td>{{ cheque.due_date }}</td>
              <td>{{ cheque.receiving_safe_account?.name_en ?? '—' }}</td>
              <td class="text-center"><span class="cr-status-chip" :class="`cr-status-${cheque.status}`">{{ statusLabel(cheque.status) }}</span></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <button v-if="cheque.status === 'received'" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openSendModal(cheque)">{{ t('accounting.chequesReceivable.index.sendForCollection') }}</button>
                  <template v-if="cheque.status === 'sent_for_collection'">
                    <button type="button" class="zv-btn zv-btn-success zv-btn-sm" @click="openCollectModal(cheque)">{{ t('accounting.chequesReceivable.index.collect') }}</button>
                    <button type="button" class="zv-btn zv-btn-danger zv-btn-sm" @click="openRejectModal(cheque)">{{ t('accounting.chequesReceivable.index.reject') }}</button>
                    <button type="button" class="zv-action-btn" :disabled="returningToSafe === cheque.id" :title="returningToSafe === cheque.id ? t('accounting.chequesReceivable.index.returning') : t('accounting.chequesReceivable.index.returnToSafe')" @click="confirmReturnToSafe(cheque)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/>
                      </svg>
                    </button>
                  </template>
                  <button
                    v-if="cheque.status === 'collected'"
                    type="button" class="zv-action-btn" :disabled="undoingCollect === cheque.id"
                    :title="undoingCollect === cheque.id ? t('accounting.chequesReceivable.index.resetting') : t('accounting.chequesReceivable.index.reset')"
                    @click="confirmUndoCollect(cheque)"
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

    <!-- ══════════════════ Send for Collection modal ══════════════════ -->
    <Teleport to="body">
      <div v-if="sendModal.cheque" class="cr-modal-overlay zv-acc" @click.self="closeModals">
        <div class="cr-modal">
          <h3 class="cr-modal-title">{{ t('accounting.chequesReceivable.index.sendForCollection') }} — {{ sendModal.cheque.cheque_number }}</h3>
          <p class="cr-modal-hint">{{ t('accounting.chequesReceivable.index.statusChangeOnlyNothingPostsUntil') }}</p>

          <div v-if="modalError" class="zv-alert zv-alert-danger mb-3">{{ modalError }}</div>

          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.chequesReceivable.index.sendToCollectionDate') }}</label>
            <input v-model="sendForm.sent_for_collection_at" type="date" class="zv-input"/>
            <p class="field-hint">{{ t('accounting.chequesReceivable.index.importantODAgainstCommercialPaperPrices') }}</p>
          </div>

          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.chequesReceivable.index.accountType') }}</label>
            <SelectBox v-model="sendForm.collection_destination_type" :options="[{ value: 'bank_account', label: t('accounting.chequesReceivable.index.currentAccount') }, { value: 'credit_facility', label: t('accounting.chequesReceivable.index.overdraftFacility') }]" />
          </div>
          <div class="field-group mb-4">
            <label class="zv-label zv-label-required">{{ t('accounting.chequesReceivable.index.accountNumber') }}</label>
            <SelectBox v-model="sendForm.destination_id" :options="filteredDestinations" value-key="id" :label-fn="d => d.label" :placeholder="t('accounting.chequesReceivable.index.select')" :clearable="false" />
          </div>

          <div class="cr-modal-actions">
            <button type="button" class="zv-btn zv-btn-ghost" @click="closeModals">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="submitting" @click="submitSend">{{ t('common.confirm') }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ══════════════════ Collect modal ══════════════════ -->
    <Teleport to="body">
      <div v-if="collectModal.cheque" class="cr-modal-overlay zv-acc" @click.self="closeModals">
        <div class="cr-modal">
          <h3 class="cr-modal-title">{{ t('accounting.chequesReceivable.index.collectCheque') }} — {{ collectModal.cheque.cheque_number }}</h3>
          <p class="cr-modal-hint">{{ t('accounting.chequesReceivable.index.postsTheRealCashOrOD') }}</p>

          <div v-if="modalError" class="zv-alert zv-alert-danger mb-3">{{ modalError }}</div>

          <div class="field-group mb-4">
            <label class="zv-label zv-label-required">{{ t('accounting.chequesReceivable.index.collectionDate') }}</label>
            <input v-model="collectDate" type="date" :min="collectModal.cheque.due_date" class="zv-input" :class="{ 'zv-input-error': collectDateTooEarly }"/>
            <p class="field-hint">{{ t('accounting.chequesReceivable.index.due') }}: {{ collectModal.cheque.due_date }} — {{ t('accounting.chequesReceivable.index.aChequeCannotLegallyClearBefore') }}</p>
            <p v-if="collectDateTooEarly" class="zv-field-error">{{ t('accounting.chequesReceivable.index.thisDateIsBeforeTheChequeS') }}</p>
          </div>

          <div class="cr-modal-actions">
            <button type="button" class="zv-btn zv-btn-ghost" @click="closeModals">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="submitting || collectDateTooEarly" @click="submitCollect">{{ t('accounting.chequesReceivable.index.confirmCollected') }}</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ══════════════════ Reject modal ══════════════════ -->
    <Teleport to="body">
      <div v-if="rejectModal.cheque" class="cr-modal-overlay zv-acc" @click.self="closeModals">
        <div class="cr-modal">
          <h3 class="cr-modal-title">{{ t('accounting.chequesReceivable.index.rejectCheque') }} — {{ rejectModal.cheque.cheque_number }}</h3>
          <p class="cr-modal-hint">{{ t('accounting.chequesReceivable.index.theCustomerSAccountsReceivableReopensFor') }}</p>

          <div v-if="modalError" class="zv-alert zv-alert-danger mb-3">{{ modalError }}</div>

          <div class="field-group mb-4">
            <label class="zv-label zv-label-required">{{ t('accounting.chequesReceivable.index.rejectionDate') }}</label>
            <input v-model="rejectDate" type="date" class="zv-input"/>
          </div>

          <div class="cr-modal-actions">
            <button type="button" class="zv-btn zv-btn-ghost" @click="closeModals">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-danger" :disabled="submitting" @click="submitReject">{{ t('accounting.chequesReceivable.index.confirmRejected') }}</button>
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
  router.get(route('accounting.cheques-receivable.index'), { status: statusFilter.value || undefined }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  statusFilter.value = ''
  applyFilters()
}

function statusLabel(status) {
  const map = { received: 'In Safe', sent_for_collection: 'Sent for Collection', collected: 'Collected', rejected: 'Rejected' }
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

// ── Destinations (shared across Send-for-Collection modal) ───────

const allDestinations = ref([])
fetch(route('accounting.customer-payments.destinations'), { headers: { Accept: 'application/json' } })
  .then(res => res.json())
  .then(data => { allDestinations.value = data.destinations ?? [] })

const filteredDestinations = computed(() =>
  allDestinations.value.filter(d => d.type === sendForm.value.collection_destination_type)
)

// ── Modals ─────────────────────────────────────────────────────────

const sendModal    = ref({ cheque: null })
const collectModal = ref({ cheque: null })
const rejectModal  = ref({ cheque: null })
const modalError   = ref('')
const submitting   = ref(false)

const sendForm    = ref({ collection_destination_type: 'bank_account', destination_id: '', sent_for_collection_at: todayLocal() })
const collectDate = ref(todayLocal())
const rejectDate  = ref(todayLocal())

function openSendModal(cheque) {
  modalError.value = ''
  sendForm.value = { collection_destination_type: 'bank_account', destination_id: '', sent_for_collection_at: todayLocal() }
  sendModal.value = { cheque }
}

function openCollectModal(cheque) {
  modalError.value = ''
  // Default to today, unless the due date is still in the future — in
  // which case default to the due date itself, since today would fail
  // the validation the moment they opened the modal.
  const today = todayLocal()
  collectDate.value = cheque.due_date > today ? cheque.due_date : today
  collectModal.value = { cheque }
}

const collectDateTooEarly = computed(() =>
  !!collectModal.value.cheque && collectDate.value < collectModal.value.cheque.due_date
)

function openRejectModal(cheque) {
  modalError.value = ''
  rejectDate.value = todayLocal()
  rejectModal.value = { cheque }
}

function closeModals() {
  sendModal.value = { cheque: null }
  collectModal.value = { cheque: null }
  rejectModal.value = { cheque: null }
}

function submitSend() {
  if (! sendForm.value.sent_for_collection_at) { modalError.value = t('accounting.chequesReceivable.index.sendToCollectionDateIsRequired'); return }
  if (! sendForm.value.destination_id) { modalError.value = t('accounting.chequesReceivable.index.selectAnAccount'); return }
  submitting.value = true
  fetch(route('accounting.cheques-receivable.send-for-collection', sendModal.value.cheque.id), {
    method: 'POST', headers: csrfHeaders(), body: JSON.stringify(sendForm.value),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { modalError.value = data.message || t('accounting.chequesReceivable.index.somethingWentWrong'); return }
      closeModals()
      router.reload({ preserveScroll: true })
    })
    .finally(() => { submitting.value = false })
}

function submitCollect() {
  submitting.value = true
  fetch(route('accounting.cheques-receivable.collect', collectModal.value.cheque.id), {
    method: 'POST', headers: csrfHeaders(), body: JSON.stringify({ collection_date: collectDate.value }),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { modalError.value = data.message || t('accounting.chequesReceivable.index.somethingWentWrong'); return }
      closeModals()
      router.reload({ preserveScroll: true })
    })
    .finally(() => { submitting.value = false })
}

function submitReject() {
  submitting.value = true
  fetch(route('accounting.cheques-receivable.reject', rejectModal.value.cheque.id), {
    method: 'POST', headers: csrfHeaders(), body: JSON.stringify({ rejection_date: rejectDate.value }),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { modalError.value = data.message || t('accounting.chequesReceivable.index.somethingWentWrong'); return }
      closeModals()
      router.reload({ preserveScroll: true })
    })
    .finally(() => { submitting.value = false })
}

// No confirmation modal needed here — unlike Send/Collect/Reject, this
// is a pure status revert with nothing to fill in (sendForCollection()
// never posts a journal entry, so there's nothing to reverse either —
// just a plain "are you sure").
const returningToSafe = ref(null)
function confirmReturnToSafe(cheque) {
  if (! confirm(t('accounting.chequesReceivable.index.returnThisChequeToTheSafe'))) return
  returningToSafe.value = cheque.id
  fetch(route('accounting.cheques-receivable.return-to-safe', cheque.id), {
    method: 'POST', headers: csrfHeaders(),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { alert(data.message || t('accounting.chequesReceivable.index.somethingWentWrong')); return }
      router.reload({ preserveScroll: true })
    })
    .finally(() => { returningToSafe.value = null })
}

// Unlike Return to Safe, this DOES reverse a real journal entry — the
// backend handles that (JournalEntryService::reverse(), never a
// delete); this button just triggers it and warns the person plainly
// what it does.
const undoingCollect = ref(null)
function confirmUndoCollect(cheque) {
  if (! confirm(t('accounting.chequesReceivable.index.resetThisChequeBackToSent'))) return
  undoingCollect.value = cheque.id
  fetch(route('accounting.cheques-receivable.undo-collect', cheque.id), {
    method: 'POST', headers: csrfHeaders(),
  })
    .then(async (res) => {
      const data = await res.json()
      if (!res.ok) { alert(data.message || t('accounting.chequesReceivable.index.somethingWentWrong')); return }
      router.reload({ preserveScroll: true })
    })
    .finally(() => { undoingCollect.value = null })
}
</script>

<style>
.cr-status-chip { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.cr-status-received             { background: rgba(148,163,184,0.15); color: #64748b; }
.cr-status-sent_for_collection  { background: rgba(245,158,11,0.12); color: #f59e0b; }
.cr-status-collected            { background: rgba(16,185,129,0.12); color: #10b981; }
.cr-status-rejected             { background: rgba(239,68,68,0.12); color: #ef4444; }

.cr-modal-overlay {
  position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 100;
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.cr-modal { background: var(--vero-card); border-radius: 12px; padding: 24px; width: 100%; max-width: 440px; }
.cr-modal-title { font-size: 15px; font-weight: 700; margin: 0 0 4px; }
.cr-modal-hint { font-size: 12.5px; color: var(--zv-text-secondary); margin: 0 0 16px; }
.cr-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px; }
</style>
