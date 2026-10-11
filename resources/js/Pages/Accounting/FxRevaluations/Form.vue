<!--
══════════════════════════════════════════════════════════════════
  Accounting/FxRevaluations/Form.vue

  Month-End FX Revaluation (Audit H12, IAS 21) — ONE page for both
  modes (Rule 2):
    • Preview (props.preview set, props.run null): what WOULD be
      posted for a month-end — closing rates, every revalued item,
      totals, warnings. "Confirm and post" sends only the date and a
      note; the server recomputes everything (never trusts the page).
    • Record (props.run set): a posted or cancelled run, item by
      item, with its two journal entries and a Cancel action.

  Amounts are shown debit − credit (payables / loans negative), so a
  positive difference is always a gain and a negative one a loss.

  @layout    AppLayout
  @route     accounting.fx-revaluations.create (preview),
             accounting.fx-revaluations.show (record)
  @props     preview: Object|null — FxRevaluationService::preview()
             run: Object|null — FxRevaluationRun with lines
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="General Ledger"
      :title="isRecord ? t('accounting.fxRevaluations.runTitle', { code: run.run_code }) : t('accounting.fxRevaluations.previewTitle', { date: data.revaluation_date })"
      :subtitle="isRecord ? t('accounting.fxRevaluations.runSub', { date: data.revaluation_date, reversal: data.reversal_date }) : t('accounting.fxRevaluations.previewSub')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>'
    >
      <template #actions>
        <Link :href="route('accounting.fx-revaluations.index')" class="zv-btn zv-btn-ghost">{{ t('accounting.fxRevaluations.backToList') }}</Link>
      </template>
    </PageHeader>

    <!-- ── Blocking messages (preview only) ── -->
    <div v-if="!isRecord && preview.missing_rates.length" class="zv-alert zv-alert-danger mb-4">
      <span class="zv-alert-icon">⚠</span>
      <div>{{ t('accounting.fxRevaluations.missingRates', { codes: preview.missing_rates.join(', ') }) }}</div>
    </div>
    <div v-if="!isRecord && preview.existing_run" class="zv-alert zv-alert-warning mb-4">
      <span class="zv-alert-icon">⚠</span>
      <div>
        {{ t('accounting.fxRevaluations.existingRun', { code: preview.existing_run.run_code }) }}
        <Link :href="route('accounting.fx-revaluations.show', preview.existing_run.id)" class="fxr-link">{{ preview.existing_run.run_code }}</Link>
      </div>
    </div>
    <div v-if="isRecord && run.status === 'cancelled'" class="zv-alert zv-alert-warning mb-4">
      <span class="zv-alert-icon">⚠</span>
      <div>{{ t('accounting.fxRevaluations.cancelledBy', { name: run.canceller?.name ?? '—', date: day(run.cancelled_at), reason: run.cancel_reason ?? '' }) }}</div>
    </div>

    <!-- ── Summary ── -->
    <div class="zv-card mb-5">
      <div class="zv-card-body fxr-summary">
        <div>
          <div class="fxr-label">{{ t('accounting.fxRevaluations.closingRates') }}</div>
          <div v-if="rateList.length" class="fxr-rates">
            <span v-for="r in rateList" :key="r.code" class="zv-badge zv-badge-gray">{{ r.code }} {{ r.rate }}</span>
          </div>
          <div v-else class="opacity-60">—</div>
        </div>
        <div>
          <div class="fxr-label">{{ t('accounting.fxRevaluations.totalGain') }}</div>
          <div class="fxr-big">{{ fmt(data.total_gain) }}</div>
        </div>
        <div>
          <div class="fxr-label">{{ t('accounting.fxRevaluations.totalLoss') }}</div>
          <div class="fxr-big zv-text-danger">{{ fmt(data.total_loss) }}</div>
        </div>
        <div>
          <div class="fxr-label">{{ t('accounting.fxRevaluations.net') }}</div>
          <div class="fxr-big" :class="Number(data.net_amount) < 0 ? 'zv-text-danger' : ''">{{ fmt(data.net_amount) }}</div>
        </div>
        <div v-if="isRecord">
          <div class="fxr-label">{{ t('accounting.fxRevaluations.journalEntry') }} / {{ t('accounting.fxRevaluations.reversalEntry') }}</div>
          <div>{{ run.journal_entry?.entry_number ?? '—' }} · {{ run.reversal_journal_entry?.entry_number ?? '—' }}</div>
        </div>
      </div>
    </div>

    <!-- ── Items ── -->
    <div v-if="items.length" class="zv-card mb-5">
      <div class="zv-card-header">{{ t('accounting.fxRevaluations.items') }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.fxRevaluations.type') }}</th>
              <th>{{ t('accounting.fxRevaluations.item') }}</th>
              <th>{{ t('accounting.fxRevaluations.currency') }}</th>
              <th class="zv-table-num">{{ t('accounting.fxRevaluations.nativeBalance') }}</th>
              <th class="zv-table-num">{{ t('accounting.fxRevaluations.bookBalance') }}</th>
              <th class="zv-table-num">{{ t('accounting.fxRevaluations.closingRate') }}</th>
              <th class="zv-table-num">{{ t('accounting.fxRevaluations.revaluedBalance') }}</th>
              <th class="zv-table-num">{{ t('accounting.fxRevaluations.difference') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(it, i) in items" :key="i">
              <td>{{ typeLabel(it.item_type) }}</td>
              <td>{{ it.item_label }}</td>
              <td>{{ it.currency_code }}</td>
              <td class="zv-table-num">{{ fmt(it.native_balance) }}</td>
              <td class="zv-table-num">{{ fmt(it.book_balance) }}</td>
              <td class="zv-table-num">{{ it.closing_rate }}</td>
              <td class="zv-table-num">{{ fmt(it.revalued_balance) }}</td>
              <td class="zv-table-num font-semibold" :class="Number(it.difference) < 0 ? 'zv-text-danger' : ''">{{ fmt(it.difference) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="field-hint px-4 pb-3">{{ t('accounting.fxRevaluations.signNote') }}</p>
    </div>

    <div v-else class="zv-card mb-5">
      <EmptyState
        :title="t('accounting.fxRevaluations.nothingToRevalue')"
        :message="t('accounting.fxRevaluations.nothingToRevalueSub')"
        icon-path="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
      />
    </div>

    <!-- ── Warnings (preview only — a record keeps only what was posted) ── -->
    <div v-if="!isRecord && preview.warnings.length" class="zv-card mb-5">
      <div class="zv-card-header">{{ t('accounting.fxRevaluations.warnings') }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <tbody>
            <tr v-for="(w, i) in preview.warnings" :key="i">
              <td class="font-semibold" style="width: 30%">{{ w.label }}</td>
              <td>{{ w.message }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p class="field-hint mb-5">{{ t('accounting.fxRevaluations.notCovered') }}</p>

    <!-- ── Confirm (preview) ── -->
    <div v-if="!isRecord" class="zv-card">
      <div class="zv-card-body">
        <div class="field-group">
          <label class="zv-label">{{ t('accounting.fxRevaluations.notes') }}</label>
          <textarea v-model="postForm.notes" rows="2" class="zv-input"></textarea>
        </div>
      </div>
      <div class="zv-card-footer flex items-center justify-end gap-3">
        <Link :href="route('accounting.fx-revaluations.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
        <button type="button" class="zv-btn zv-btn-primary" :disabled="!canPost || postForm.processing" @click="confirmAndPost">
          {{ postForm.processing ? t('common.saving') : t('accounting.fxRevaluations.confirmAndPost') }}
        </button>
      </div>
    </div>

    <!-- ── Cancel (record, posted only) ── -->
    <div v-if="isRecord && run.status === 'posted'" class="zv-card">
      <div class="zv-card-body">
        <div class="field-group">
          <label class="zv-label zv-label-required">{{ t('accounting.fxRevaluations.cancelReason') }}</label>
          <textarea v-model="cancelForm.cancel_reason" rows="2" class="zv-input"></textarea>
          <p class="field-hint">{{ t('accounting.fxRevaluations.cancelHint') }}</p>
          <p v-if="cancelForm.errors.cancel_reason" class="zv-field-error">{{ cancelForm.errors.cancel_reason }}</p>
        </div>
      </div>
      <div class="zv-card-footer flex items-center justify-end gap-3">
        <button type="button" class="zv-btn zv-btn-danger" :disabled="!cancelForm.cancel_reason || cancelForm.processing" @click="cancelRun">
          {{ cancelForm.processing ? t('common.saving') : t('accounting.fxRevaluations.confirmCancel') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'

const { t } = useI18n()

const props = defineProps({
  preview: { type: Object, default: null },
  run: { type: Object, default: null },
})

const isRecord = computed(() => !!props.run?.id)

// One shape for both modes: the preview array, or the saved run.
const data = computed(() => isRecord.value
  ? {
      revaluation_date: day(props.run.revaluation_date),
      reversal_date: day(props.run.reversal_date),
      rates: props.run.closing_rates || {},
      total_gain: props.run.total_gain,
      total_loss: props.run.total_loss,
      net_amount: props.run.net_amount,
    }
  : props.preview)

const items = computed(() => isRecord.value
  ? (props.run.lines || []).map(l => ({ ...l, currency_code: l.currency?.code ?? '' }))
  : (props.preview.items || []))

const rateList = computed(() => Object.entries(data.value.rates || {}).map(([code, rate]) => ({ code, rate })))

const canPost = computed(() => !isRecord.value
  && !props.preview.missing_rates.length
  && !props.preview.existing_run
  && items.value.some(i => Number(i.difference) !== 0))

const postForm = useForm({ revaluation_date: props.preview?.revaluation_date ?? '', notes: '' })
function confirmAndPost() {
  postForm.post(route('accounting.fx-revaluations.store'))
}

const cancelForm = useForm({ cancel_reason: '' })
function cancelRun() {
  cancelForm.patch(route('accounting.fx-revaluations.cancel', props.run.id), { preserveScroll: true })
}

const TYPE_KEYS = {
  account: 'typeAccount',
  customer_invoice: 'typeCustomerInvoice',
  supplier_bill: 'typeSupplierBill',
  cheque_receivable: 'typeChequeReceivable',
  cheque_payable: 'typeChequePayable',
}
function typeLabel(type) { return TYPE_KEYS[type] ? t('accounting.fxRevaluations.' + TYPE_KEYS[type]) : type }

function day(d) { return d ? String(d).slice(0, 10) : '—' }
function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
</script>

<style scoped>
.fxr-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
.fxr-label { font-size: 12px; opacity: .7; margin-bottom: 4px; }
.fxr-big { font-size: 1.25rem; font-weight: 600; }
.fxr-rates { display: flex; flex-wrap: wrap; gap: 6px; }
.fxr-link { color: var(--zv-blue); margin-inline-start: 4px; }
</style>
