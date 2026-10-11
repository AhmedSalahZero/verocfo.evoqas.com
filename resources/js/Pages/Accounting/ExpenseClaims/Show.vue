<!--
══════════════════════════════════════════════════════════════════
  Accounting/ExpenseClaims/Show.vue

  One claim, its receipts, its approval trail, and both journal
  entries it produced.

  The journal entries are shown in full rather than summarised. A
  claim is one of the few HR documents that posts to the ledger, and
  whoever approves it should be able to see exactly which accounts
  moved — the same reasoning behind showing the entry on a Goods
  Receipt Note or a Supplier Bill.

  Actions appear by status, because the lifecycle is strictly ordered
  and offering an action the service will refuse is worse than not
  offering it:

    draft      → edit, submit, cancel, delete
    submitted  → approve, reject, cancel
    approved   → mark as paid, reopen to draft
    paid       → nothing; the money has gone

  @layout    AppLayout
  @route     hr.expense-claims.show
  @props     claim, bankAccounts, safeAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="`${t('accounting.hr.expenseClaimShow.expenseClaim')} ${claim.claim_code}`"
      :subtitle="claim.title || claim.partner?.name_en"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
    >
      <template #actions>
        <Link v-if="claim.status === 'draft'" :href="route('hr.expense-claims.edit', claim.id)" class="zv-btn zv-btn-ghost">
          {{ t('accounting.hr.expenseClaimShow.edit') }}
        </Link>
        <button v-if="claim.status === 'draft'" type="button" class="zv-btn zv-btn-primary" @click="act('submit')">
          {{ t('accounting.hr.expenseClaimShow.submit') }}
        </button>

        <button v-if="claim.status === 'submitted'" type="button" class="zv-btn zv-btn-primary" @click="act('approve')">
          {{ t('accounting.hr.expenseClaimShow.approve') }}
        </button>
        <button v-if="claim.status === 'submitted'" type="button" class="zv-btn zv-btn-ghost" @click="rejectForm.clearErrors(); rejectOpen = true">
          {{ t('accounting.hr.expenseClaimShow.reject') }}
        </button>

        <button v-if="claim.status === 'approved'" type="button" class="zv-btn zv-btn-primary" @click="payForm.clearErrors(); payOpen = true">
          {{ t('accounting.hr.expenseClaimShow.markAsPaid') }}
        </button>
        <button v-if="claim.status === 'approved'" type="button" class="zv-btn zv-btn-ghost" @click="reopen">
          {{ t('accounting.hr.expenseClaimShow.reopenToDraft') }}
        </button>

        <button
          v-if="['draft', 'submitted'].includes(claim.status)"
          type="button" class="zv-btn zv-btn-ghost" @click="cancelClaim"
        >
          {{ t('accounting.hr.expenseClaimShow.cancelClaim') }}
        </button>
      </template>
    </PageHeader>

    <div v-if="errorMessage" class="zv-alert zv-alert-danger mb-4">{{ errorMessage }}</div>

    <div v-if="claim.rejection_reason" class="zv-alert zv-alert-warning mb-4">
      <strong>{{ t('accounting.hr.expenseClaimShow.rejectedBecause') }}:</strong> {{ claim.rejection_reason }}
    </div>

    <!-- Summary -->
    <div class="zv-card mb-4">
      <div class="ecs-summary">
        <div>
          <div class="ecs-label">{{ t('accounting.hr.expenseClaimShow.employee') }}</div>
          <div class="ecs-value">{{ claim.partner?.name_en }}</div>
        </div>
        <div>
          <div class="ecs-label">{{ t('accounting.hr.expenseClaimShow.claimDate') }}</div>
          <div class="ecs-value">{{ claim.claim_date }}</div>
        </div>
        <div>
          <div class="ecs-label">{{ t('accounting.hr.expenseClaimShow.total') }}</div>
          <div class="ecs-value font-mono">{{ claim.total_amount }} {{ claim.currency?.code }}</div>
        </div>
        <div>
          <div class="ecs-label">{{ t('accounting.hr.expenseClaimShow.status') }}</div>
          <div><StatusBadge :status="claim.status" /></div>
        </div>
      </div>

      <div v-if="claim.approved_at || claim.paid_at" class="ecs-trail">
        <span v-if="claim.approved_at">
          {{ t('accounting.hr.expenseClaimShow.approvedOn') }} {{ formatDate(claim.approved_at) }}
          <template v-if="claim.approved_by?.name"> {{ t('accounting.hr.expenseClaimShow.by') }} {{ claim.approved_by.name }}</template>
        </span>
        <span v-if="claim.paid_at">
          {{ t('accounting.hr.expenseClaimShow.paidOn') }} {{ formatDate(claim.paid_at) }}
        </span>
      </div>
    </div>

    <!-- Receipts -->
    <div class="zv-card mb-4">
      <h3 class="ecs-section-title">{{ t('accounting.hr.expenseClaimShow.receipts') }}</h3>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.expenseClaimShow.expenseDate') }}</th>
              <th>{{ t('accounting.hr.expenseClaimShow.description') }}</th>
              <th>{{ t('accounting.hr.expenseClaimShow.expenseAccount') }}</th>
              <th>{{ t('accounting.hr.expenseClaimShow.costCenter') }}</th>
              <th class="text-right">{{ t('accounting.hr.expenseClaimShow.amount') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in claim.lines" :key="l.id">
              <td class="text-sm">{{ l.expense_date }}</td>
              <td>{{ l.description }}</td>
              <td class="text-sm">{{ l.expense_account?.code }} — {{ l.expense_account?.name_en }}</td>
              <td class="text-sm">{{ l.cost_center?.name_en || '—' }}</td>
              <td class="text-right font-mono">{{ l.amount }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4" class="text-right font-semibold">{{ t('accounting.hr.expenseClaimShow.total') }}</td>
              <td class="text-right font-mono font-semibold">{{ claim.total_amount }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Journal entries -->
    <div class="zv-card">
      <h3 class="ecs-section-title">{{ t('accounting.hr.expenseClaimShow.accounting') }}</h3>

      <p v-if="!claim.journal_entry" class="text-sm opacity-70">
        {{ t('accounting.hr.expenseClaimShow.nothingPostedYet') }}
      </p>

      <template v-else>
        <JournalTable
          :title="t('accounting.hr.expenseClaimShow.accrualEntry')"
          :entry="claim.journal_entry"
          :labels="journalLabels"
        />
        <JournalTable
          v-if="claim.payment_journal_entry"
          :title="t('accounting.hr.expenseClaimShow.paymentEntry')"
          :entry="claim.payment_journal_entry"
          :labels="journalLabels"
          class="mt-4"
        />
      </template>
    </div>

    <!-- Reject -->
    <div v-if="rejectOpen" class="zv-modal-backdrop zv-acc" @click.self="rejectOpen = false">
      <div class="zv-modal zv-modal-sm">
        <div class="zv-modal-header">
          <div class="zv-modal-title">{{ t('accounting.hr.expenseClaimShow.rejectClaim') }}</div>
          <button type="button" class="zv-modal-close" @click="rejectOpen = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        <div class="zv-modal-body">
          <!-- Round 8: a refusal used to appear only behind the dark backdrop. -->
          <div v-if="rejectForm.errors.status" class="zv-alert zv-alert-danger mb-3">{{ rejectForm.errors.status }}</div>
          <p class="text-sm opacity-70 mb-2">{{ t('accounting.hr.expenseClaimShow.whyIsItBeingRejected') }}</p>
          <label class="zv-label">{{ t('accounting.hr.expenseClaimShow.reasonOptional') }}</label>
          <textarea v-model="rejectForm.rejection_reason" rows="3" class="zv-input"></textarea>
        </div>
        <div class="zv-modal-footer">
          <button type="button" class="zv-btn zv-btn-ghost" @click="rejectOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-danger" :disabled="rejectForm.processing" @click="confirmReject">
            {{ t('accounting.hr.expenseClaimShow.reject') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Pay -->
    <div v-if="payOpen" class="zv-modal-backdrop zv-acc" @click.self="payOpen = false">
      <div class="zv-modal zv-modal-sm">
        <div class="zv-modal-header">
          <div class="zv-modal-title">{{ t('accounting.hr.expenseClaimShow.payClaim') }}</div>
          <button type="button" class="zv-modal-close" @click="payOpen = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        <div class="zv-modal-body">
          <div v-if="payForm.errors.status" class="zv-alert zv-alert-danger mb-3">{{ payForm.errors.status }}</div>
          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.hr.expenseClaimShow.payFrom') }}</label>
            <div class="zv-tile-group">
              <button
                type="button" class="zv-tile"
                :class="{ 'zv-tile-active': payForm.payment_source_type === 'bank_account' }"
                @click="payForm.payment_source_type = 'bank_account'"
              >{{ t('accounting.hr.expenseClaimShow.bankAccount') }}</button>
              <button
                type="button" class="zv-tile"
                :class="{ 'zv-tile-active': payForm.payment_source_type === 'cash_safe' }"
                @click="payForm.payment_source_type = 'cash_safe'"
              >{{ t('accounting.hr.expenseClaimShow.cashSafe') }}</button>
            </div>
          </div>

          <div v-if="payForm.payment_source_type === 'bank_account'" class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.hr.expenseClaimShow.bankAccount') }}</label>
            <SelectBox
              v-model="payForm.payment_bank_account_id"
              :options="bankAccounts"
              value-key="id"
              label-key="account_name"
              :placeholder="t('accounting.hr.expenseClaimShow.selectSource')"
              :clearable="false"
            />
            <p v-if="payForm.errors.payment_bank_account_id" class="zv-field-error">{{ payForm.errors.payment_bank_account_id }}</p>
          </div>

          <div v-if="payForm.payment_source_type === 'cash_safe'" class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.hr.expenseClaimShow.cashSafe') }}</label>
            <SelectBox
              v-model="payForm.payment_safe_account_id"
              :options="safeAccounts"
              value-key="id"
              :label-fn="a => `${a.code} — ${a.name_en}`"
              :placeholder="t('accounting.hr.expenseClaimShow.selectSource')"
              :clearable="false"
            />
            <p v-if="payForm.errors.payment_safe_account_id" class="zv-field-error">{{ payForm.errors.payment_safe_account_id }}</p>
          </div>

          <div class="field-group mb-3">
            <label class="zv-label">{{ t('accounting.hr.expenseClaimShow.paymentDate') }}</label>
            <input v-model="payForm.payment_date" type="date" class="zv-input"/>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('accounting.hr.expenseClaimShow.reference') }}</label>
            <input v-model="payForm.payment_reference" type="text" class="zv-input"/>
          </div>
        </div>
        <div class="zv-modal-footer">
          <button type="button" class="zv-btn zv-btn-ghost" @click="payOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="payForm.processing" @click="confirmPay">
            {{ t('accounting.hr.expenseClaimShow.markAsPaid') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed, h, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'
import StatusBadge from '@/Components/StatusBadge.vue'

const { t } = useI18n()

const props = defineProps({
  claim:        { type: Object, required: true },
  bankAccounts: { type: Array,  default: () => [] },
  safeAccounts: { type: Array,  default: () => [] },
})

const page = usePage()
const errorMessage = computed(() => page.props.errors?.status || null)

const journalLabels = computed(() => ({
  account: t('accounting.hr.expenseClaimShow.account'),
  debit:   t('accounting.hr.expenseClaimShow.debit'),
  credit:  t('accounting.hr.expenseClaimShow.credit'),
}))

/**
 * A journal entry rendered as a table. Defined inline rather than as
 * a shared component because it is used twice on this one page and
 * nowhere else — extracting it would move it further from where it
 * is read without making anything reusable.
 */
const JournalTable = (p) =>
  h('div', {}, [
    h('h4', { class: 'ecs-journal-title' }, `${p.title} — ${p.entry.entry_number}`),
    h('div', { class: 'zv-table-wrap' }, [
      h('table', { class: 'zv-table' }, [
        h('thead', {}, [
          h('tr', {}, [
            h('th', {}, p.labels.account),
            h('th', { class: 'text-right' }, p.labels.debit),
            h('th', { class: 'text-right' }, p.labels.credit),
          ]),
        ]),
        h('tbody', {}, (p.entry.lines || []).map((l) =>
          h('tr', { key: l.id }, [
            h('td', { class: 'text-sm' }, `${l.chart_of_account?.code ?? ''} — ${l.chart_of_account?.name_en ?? ''}`),
            h('td', { class: 'text-right font-mono' }, Number(l.debit) ? l.debit : '—'),
            h('td', { class: 'text-right font-mono' }, Number(l.credit) ? l.credit : '—'),
          ])
        )),
      ]),
    ]),
  ])

function formatDate(value) {
  return value ? String(value).slice(0, 10) : ''
}

function act(action) {
  router.patch(route(`hr.expense-claims.${action}`, props.claim.id), {}, { preserveScroll: true })
}

function reopen() {
  if (!window.confirm(t('accounting.hr.expenseClaimShow.confirmReopen'))) return
  router.patch(route('hr.expense-claims.set-to-draft', props.claim.id), {}, { preserveScroll: true })
}

function cancelClaim() {
  if (!window.confirm(t('accounting.hr.expenseClaimShow.confirmCancel'))) return
  router.patch(route('hr.expense-claims.cancel', props.claim.id), {}, { preserveScroll: true })
}

const rejectOpen = ref(false)
const rejectForm = useForm({ rejection_reason: '' })

function confirmReject() {
  rejectForm.patch(route('hr.expense-claims.reject', props.claim.id), {
    preserveScroll: true,
    onSuccess: () => { rejectOpen.value = false },
  })
}

const payOpen = ref(false)
const payForm = useForm({
  payment_source_type: 'bank_account',
  payment_bank_account_id: '',
  payment_safe_account_id: '',
  payment_date: todayLocal(),
  payment_reference: '',
})

function confirmPay() {
  payForm.transform((data) => ({
    ...data,
    payment_bank_account_id: data.payment_source_type === 'bank_account' ? data.payment_bank_account_id : null,
    payment_safe_account_id: data.payment_source_type === 'cash_safe' ? data.payment_safe_account_id : null,
  }))

  payForm.patch(route('hr.expense-claims.mark-paid', props.claim.id), {
    preserveScroll: true,
    onSuccess: () => { payOpen.value = false },
  })
}
</script>

<style scoped>
.ecs-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; }
.ecs-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--zv-text-muted, #94a3b8); margin-bottom: 4px; }
.ecs-value { font-size: 15px; font-weight: 600; color: var(--zv-text-primary, #0f172a); }
.ecs-trail { margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--zv-border, #e2e8f0); display: flex; gap: 20px; flex-wrap: wrap; font-size: 12px; color: var(--zv-text-secondary, #475569); }
.ecs-section-title { font-size: 14px; font-weight: 700; margin-bottom: 12px; color: var(--zv-text-primary, #0f172a); }

/* The dialog here uses the shared zv-modal system in app.css — the
   same one 20 other pages use, with its .zv-acc accounting skin and
   its print/mobile rules. This page used to carry a private copy in a
   NON-SCOPED <style> block, which leaked those rules into every other
   page and still missed the accounting skin, so the dialog looked
   like nothing else in the product. */
.ecs-journal-title { font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--zv-text-secondary, #475569); }
</style>
