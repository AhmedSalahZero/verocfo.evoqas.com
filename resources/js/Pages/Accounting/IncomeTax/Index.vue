<!--
══════════════════════════════════════════════════════════════════
  Accounting/IncomeTax/Index.vue

  Accounting › General Ledger › Income Tax (Audit H19, IAS 12).
  For one fiscal year: Profit Before Tax (reference), tax already
  charged, Debit WHT available (142) and tax still owed (226003).
  Two actions — record the year's provision (Dr 591 / Cr 226003,
  optional deferred tax), and set Debit WHT off against the tax owed
  (Dr 226003 / Cr 142). The remaining balance is paid through Other
  Payment. Entries can be cancelled (reversed), never edited.

  @layout    AppLayout
  @route     accounting.income-tax.index
  @props     summary: Object — IncomeTaxService::summary()
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">
    <PageHeader
      eyebrow="General Ledger"
      :title="t('accounting.incomeTax.title')"
      :subtitle="t('accounting.incomeTax.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5 3.75a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    />

    <div class="zv-card mb-5">
      <div class="zv-card-body">
        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.incomeTax.fiscalYear') }}</label>
            <select v-model="yearId" class="zv-input" @change="changeYear">
              <option v-for="y in summary.fiscal_years" :key="y.id" :value="y.id">{{ y.name }}</option>
            </select>
          </div>
        </div>
      </div>
      <div class="zv-card-body itx-cards">
        <div><div class="itx-label">{{ t('accounting.incomeTax.profitBeforeTax') }}</div><div class="itx-big">{{ fmt(summary.profit_before_tax) }}</div></div>
        <div><div class="itx-label">{{ t('accounting.incomeTax.taxCharged') }}</div><div class="itx-big">{{ fmt(summary.tax_charged) }}</div></div>
        <div><div class="itx-label">{{ t('accounting.incomeTax.whtBalance') }}</div><div class="itx-big">{{ fmt(summary.wht_balance) }}</div></div>
        <div><div class="itx-label">{{ t('accounting.incomeTax.taxPayable') }}</div><div class="itx-big">{{ fmt(summary.tax_payable) }}</div></div>
      </div>
      <p class="field-hint px-4 pb-3">{{ t('accounting.incomeTax.balancesAsOf', { date: summary.year_end || '—' }) }}</p>
    </div>

    <div class="itx-grid mb-5">
      <!-- Provision -->
      <div class="form-section">
        <div class="form-section-header">
          <div>
            <div class="form-section-title">{{ t('accounting.incomeTax.provisionTitle') }}</div>
            <div class="form-section-sub">{{ t('accounting.incomeTax.provisionSub') }}</div>
          </div>
        </div>
        <div class="form-section-body">
          <div class="field-grid field-grid-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.incomeTax.date') }}</label>
              <input v-model="provision.entry_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.incomeTax.currentTax') }}</label>
              <input v-model="provision.current_tax" type="number" step="0.01" min="0" class="zv-input"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.incomeTax.deferredTax') }}</label>
              <input v-model="provision.deferred_tax" type="number" step="0.01" class="zv-input"/>
              <p class="field-hint">{{ t('accounting.incomeTax.deferredTaxHint') }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.incomeTax.notes') }}</label>
              <input v-model="provision.notes" type="text" class="zv-input"/>
            </div>
          </div>
        </div>
        <div class="zv-card-footer flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="provision.processing" @click="provision.post(route('accounting.income-tax.provision'), { preserveScroll: true, onSuccess: () => provision.reset('current_tax', 'deferred_tax', 'notes') })">
            {{ t('accounting.incomeTax.postProvision') }}
          </button>
        </div>
      </div>

      <!-- WHT offset -->
      <div class="form-section">
        <div class="form-section-header">
          <div>
            <div class="form-section-title">{{ t('accounting.incomeTax.offsetTitle') }}</div>
            <div class="form-section-sub">{{ t('accounting.incomeTax.offsetSub') }}</div>
          </div>
        </div>
        <div class="form-section-body">
          <div class="field-grid field-grid-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.incomeTax.date') }}</label>
              <input v-model="offset.entry_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.incomeTax.amount') }}</label>
              <input v-model="offset.amount" type="number" step="0.01" min="0" class="zv-input"/>
              <p class="field-hint">{{ t('accounting.incomeTax.offsetHint', { max: fmt(maxOffset) }) }}</p>
            </div>
            <div class="field-group field-group-span-2">
              <label class="zv-label">{{ t('accounting.incomeTax.notes') }}</label>
              <input v-model="offset.notes" type="text" class="zv-input"/>
            </div>
          </div>
        </div>
        <div class="zv-card-footer flex justify-end gap-2">
          <button type="button" class="zv-btn zv-btn-ghost" @click="offset.amount = maxOffset">{{ t('accounting.incomeTax.useMax') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="offset.processing" @click="offset.post(route('accounting.income-tax.wht-offset'), { preserveScroll: true, onSuccess: () => offset.reset('amount', 'notes') })">
            {{ t('accounting.incomeTax.postOffset') }}
          </button>
        </div>
      </div>
    </div>

    <p class="field-hint mb-4">{{ t('accounting.incomeTax.payBalanceHint') }}</p>

    <div class="zv-card">
      <div class="zv-card-header">{{ t('accounting.incomeTax.entries') }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.incomeTax.date') }}</th>
              <th>{{ t('accounting.incomeTax.type') }}</th>
              <th class="zv-table-num">{{ t('accounting.incomeTax.currentTax') }}</th>
              <th class="zv-table-num">{{ t('accounting.incomeTax.deferredTax') }}</th>
              <th>{{ t('accounting.incomeTax.journalEntry') }}</th>
              <th>{{ t('accounting.incomeTax.status') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in summary.entries" :key="e.id">
              <td>{{ String(e.entry_date).slice(0, 10) }}</td>
              <td>{{ e.entry_type === 'provision' ? t('accounting.incomeTax.typeProvision') : t('accounting.incomeTax.typeOffset') }}</td>
              <td class="zv-table-num">{{ fmt(e.current_tax) }}</td>
              <td class="zv-table-num">{{ e.entry_type === 'provision' ? fmt(e.deferred_tax) : '—' }}</td>
              <td>{{ e.journal_entry?.entry_number ?? '—' }}</td>
              <td><span class="zv-badge" :class="e.status === 'posted' ? 'zv-badge-green' : 'zv-badge-gray'">{{ e.status === 'posted' ? t('accounting.incomeTax.posted') : t('accounting.incomeTax.cancelled') }}</span></td>
              <td class="text-end">
                <button v-if="e.status === 'posted'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="cancelEntry(e)">{{ t('accounting.incomeTax.cancel') }}</button>
              </td>
            </tr>
            <tr v-if="!summary.entries.length"><td colspan="7" class="text-center opacity-60">{{ t('accounting.incomeTax.noEntries') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { router, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

const props = defineProps({
  summary: { type: Object, required: true },
})

const yearId = ref(props.summary.fiscal_year_id)
function changeYear() {
  router.get(route('accounting.income-tax.index'), { fiscal_year_id: yearId.value }, { preserveScroll: true })
}

const provision = useForm({ entry_date: props.summary.year_end || '', current_tax: '', deferred_tax: '', notes: '' })
const offset = useForm({ entry_date: props.summary.year_end || '', amount: '', notes: '' })

const maxOffset = computed(() => Math.max(0, Math.min(Number(props.summary.wht_balance || 0), Number(props.summary.tax_payable || 0))).toFixed(2))

function cancelEntry(e) {
  const reason = window.prompt(t('accounting.incomeTax.cancelPrompt'))
  if (!reason) return
  router.patch(route('accounting.income-tax.cancel', e.id), { cancel_reason: reason }, { preserveScroll: true })
}

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
</script>

<style scoped>
.itx-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
.itx-label { font-size: 12px; opacity: .7; margin-bottom: 4px; }
.itx-big { font-size: 1.25rem; font-weight: 600; }
.itx-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 1000px) { .itx-grid { grid-template-columns: 1fr; } }
</style>
