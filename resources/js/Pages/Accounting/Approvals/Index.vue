<!--
══════════════════════════════════════════════════════════════════
  Accounting/Approvals/Index.vue

  Accounting › Approvals (Audit H3 / H5, Sept 2026). Everything that
  is waiting for a SECOND person:
    1. Manual journal entries — every one needs approval before it
       posts. Each card shows the exact debit / credit lines.
    2. Requests to reopen a posted customer invoice or supplier bill,
       with the requester's written reason.
  Approve / Reject buttons are hidden on your own requests (the
  server refuses them anyway). Reject asks for a reason.

  @layout    AppLayout
  @route     accounting.approvals.index
  @props     manualJournals: Array — pending requests with their lines
             reopenRequests: Array — pending reopen requests
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.approvals.index.title')"
      :subtitle="t('accounting.approvals.index.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    />

    <!-- 1. Manual journal entries -->
    <div class="zv-card mb-5">
      <div class="zv-card-header">
        <div>
          <h2 class="zv-card-title">{{ t('accounting.approvals.index.manualJournals') }}</h2>
          <p class="text-xs" style="color:var(--zv-text-muted)">{{ t('accounting.approvals.index.manualJournalsHint') }}</p>
        </div>
      </div>

      <p v-if="manualJournals.length === 0" class="p-4 text-sm" style="color:var(--zv-text-muted)">
        {{ t('accounting.approvals.index.nothingWaiting') }}
      </p>

      <div v-for="je in manualJournals" :key="je.id" class="appr-item">
        <div class="appr-head">
          <div>
            <div class="font-semibold">{{ je.narration }}</div>
            <div class="text-xs" style="color:var(--zv-text-muted)">
              {{ t('accounting.approvals.index.date') }}: {{ je.entry_date }} ·
              {{ t('accounting.approvals.index.requestedBy') }}: {{ je.requested_by }} · {{ je.requested_at }}
            </div>
          </div>
          <div class="appr-actions">
            <span class="zv-table-num font-semibold">{{ fmt(je.total_amount) }}</span>
            <span v-if="je.is_mine" class="zv-badge zv-badge-amber">{{ t('accounting.approvals.index.yours') }}</span>
            <template v-else>
              <button type="button" class="zv-btn zv-btn-primary zv-btn-sm" :disabled="busy" @click="approve('journals', je.id, t('accounting.approvals.index.confirmApproveJournal'))">{{ t('accounting.approvals.index.approve') }}</button>
              <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" :disabled="busy" @click="reject('journals', je.id)">{{ t('accounting.approvals.index.reject') }}</button>
            </template>
          </div>
        </div>
        <div class="zv-table-wrap">
          <table class="zv-table">
            <thead>
              <tr>
                <th>{{ t('accounting.approvals.index.account') }}</th>
                <th>{{ t('accounting.approvals.index.description') }}</th>
                <th class="zv-table-num">{{ t('accounting.approvals.index.debit') }}</th>
                <th class="zv-table-num">{{ t('accounting.approvals.index.credit') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(line, i) in je.lines" :key="i">
                <td>{{ line.account }}</td>
                <td>{{ line.description }}</td>
                <td class="zv-table-num">{{ line.debit ? fmt(line.debit) : '' }}</td>
                <td class="zv-table-num">{{ line.credit ? fmt(line.credit) : '' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 2. Reopen requests -->
    <div class="zv-card">
      <div class="zv-card-header">
        <div>
          <h2 class="zv-card-title">{{ t('accounting.approvals.index.reopenRequests') }}</h2>
          <p class="text-xs" style="color:var(--zv-text-muted)">{{ t('accounting.approvals.index.reopenRequestsHint') }}</p>
        </div>
      </div>

      <p v-if="reopenRequests.length === 0" class="p-4 text-sm" style="color:var(--zv-text-muted)">
        {{ t('accounting.approvals.index.nothingWaiting') }}
      </p>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.approvals.index.document') }}</th>
              <th>{{ t('accounting.approvals.index.reason') }}</th>
              <th>{{ t('accounting.approvals.index.requestedBy') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in reopenRequests" :key="r.id">
              <td>
                <span class="zv-code">{{ r.document_code }}</span>
                <div class="text-xs" style="color:var(--zv-text-muted)">
                  {{ r.document_type === 'customer_invoice' ? t('accounting.approvals.index.customerInvoice') : t('accounting.approvals.index.supplierBill') }}
                </div>
              </td>
              <td style="max-width:28rem; white-space:normal">{{ r.reason }}</td>
              <td>{{ r.requested_by }}<div class="text-xs" style="color:var(--zv-text-muted)">{{ r.requested_at }}</div></td>
              <td class="text-end" style="white-space:nowrap">
                <span v-if="r.is_mine" class="zv-badge zv-badge-amber">{{ t('accounting.approvals.index.yours') }}</span>
                <template v-else>
                  <button type="button" class="zv-btn zv-btn-primary zv-btn-sm me-1" :disabled="busy" @click="approve('reopens', r.id, t('accounting.approvals.index.confirmApproveReopen'))">{{ t('accounting.approvals.index.approve') }}</button>
                  <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" :disabled="busy" @click="reject('reopens', r.id)">{{ t('accounting.approvals.index.reject') }}</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

defineProps({
  manualJournals: { type: Array, default: () => [] },
  reopenRequests: { type: Array, default: () => [] },
})

const busy = ref(false)

function approve(kind, id, question) {
  if (!confirm(question)) return
  busy.value = true
  router.post(route(`accounting.approvals.${kind}.approve`, id), {}, {
    preserveScroll: true,
    onFinish: () => { busy.value = false },
  })
}

function reject(kind, id) {
  const reason = window.prompt(t('accounting.approvals.index.rejectPrompt'))
  if (reason === null || reason.trim().length < 5) return
  busy.value = true
  router.post(route(`accounting.approvals.${kind}.reject`, id), { reason: reason.trim() }, {
    preserveScroll: true,
    onFinish: () => { busy.value = false },
  })
}

function fmt(value) {
  return Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style scoped>
.appr-item { border-top: 1px solid var(--zv-border); padding: 14px 16px; }
.appr-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
.appr-actions { display: flex; align-items: center; gap: 8px; }
</style>
