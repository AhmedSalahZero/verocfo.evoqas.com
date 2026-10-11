<!--
══════════════════════════════════════════════════════════════════
  Accounting/PurchaseReturns/Index.vue

  Accounting › Purchases › Purchase Returns (Audit H16). One page:
  a "New Purchase Return" panel on top (pick a posted Goods Receipt
  Note, enter the quantity going back per line and the location it
  leaves from), and the list of posted returns below with Cancel.
  Goods leave stock at their original receipt cost. For goods already
  billed, a Supplier Debit Note is raised and posted automatically.
  A return is posted when it is saved — no draft, no edit.

  @layout    AppLayout
  @route     accounting.purchase-returns.index
  @props     returns: Paginator — PurchaseReturnService::paginate()
             receipts: Array — returnable GRNs and lines
             locations: Array — inventory locations
             filters: Object — { search }
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">
    <PageHeader
      eyebrow="Purchases"
      :title="t('accounting.purchaseReturns.title')"
      :subtitle="t('accounting.purchaseReturns.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 14L4 9l5-5M4 9h10.5a5.5 5.5 0 010 11H11"/>'
    />

    <div class="form-section mb-5">
      <div class="form-section-header">
        <div>
          <div class="form-section-title">{{ t('accounting.purchaseReturns.newReturn') }}</div>
          <div class="form-section-sub">{{ t('accounting.purchaseReturns.newReturnSub') }}</div>
        </div>
      </div>
      <div class="form-section-body">
        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.purchaseReturns.grn') }}</label>
            <select v-model="form.goods_receipt_note_id" class="zv-input" @change="pickReceipt">
              <option value="">{{ t('accounting.purchaseReturns.selectGrn') }}</option>
              <option v-for="g in receipts" :key="g.id" :value="g.id">{{ g.code }} — {{ g.partner }} ({{ g.date }})</option>
            </select>
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.purchaseReturns.returnDate') }}</label>
            <input v-model="form.return_date" type="date" class="zv-input"/>
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.purchaseReturns.reason') }}</label>
            <input v-model="form.reason" type="text" class="zv-input"/>
          </div>
        </div>
        <p class="field-hint mt-2">{{ t('accounting.purchaseReturns.billedHint') }}</p>

        <div v-if="form.lines.length" class="zv-table-wrap mt-4">
          <table class="zv-table">
            <thead>
              <tr>
                <th>{{ t('accounting.purchaseReturns.item') }}</th>
                <th class="zv-table-num">{{ t('accounting.purchaseReturns.returnable') }}</th>
                <th class="zv-table-num">{{ t('accounting.purchaseReturns.billed') }}</th>
                <th class="zv-table-num">{{ t('accounting.purchaseReturns.unitCost') }}</th>
                <th class="zv-table-num">{{ t('accounting.purchaseReturns.quantity') }}</th>
                <th>{{ t('accounting.purchaseReturns.fromLocation') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in form.lines" :key="l.goods_receipt_note_line_id">
                <td>{{ l.product || l.description }}</td>
                <td class="zv-table-num">{{ qty(l.returnable) }}</td>
                <td class="zv-table-num">{{ qty(l.billed) }}</td>
                <td class="zv-table-num">{{ fmt(l.unit_cost) }}</td>
                <td class="zv-table-num"><input v-model="l.quantity" type="number" step="0.0001" min="0" :max="l.returnable" class="zv-input pr-qty"/></td>
                <td>
                  <select v-model="l.location_id" class="zv-input">
                    <option v-for="loc in locations" :key="loc.id" :value="loc.id">{{ loc.code }} — {{ loc.name_en }}</option>
                  </select>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="!receipts.length" class="field-hint mt-4">{{ t('accounting.purchaseReturns.noReceipts') }}</p>
      </div>
      <div class="zv-card-footer flex justify-end">
        <button type="button" class="zv-btn zv-btn-primary" :disabled="form.processing || !form.lines.length" @click="submit">
          {{ t('accounting.purchaseReturns.postReturn') }}
        </button>
      </div>
    </div>

    <div class="zv-card">
      <div class="zv-card-header flex justify-between items-center">
        <span>{{ t('accounting.purchaseReturns.list') }}</span>
        <input v-model="search" type="search" class="zv-input pr-search" :placeholder="t('accounting.purchaseReturns.search')" @keyup.enter="doSearch"/>
      </div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.purchaseReturns.code') }}</th>
              <th>{{ t('accounting.purchaseReturns.date') }}</th>
              <th>{{ t('accounting.purchaseReturns.supplier') }}</th>
              <th>{{ t('accounting.purchaseReturns.grn') }}</th>
              <th>{{ t('accounting.purchaseReturns.items') }}</th>
              <th class="zv-table-num">{{ t('accounting.purchaseReturns.costOut') }}</th>
              <th>{{ t('accounting.purchaseReturns.journalEntry') }}</th>
              <th>{{ t('accounting.purchaseReturns.status') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in returns.data" :key="r.id">
              <td>{{ r.return_code }}</td>
              <td>{{ String(r.return_date).slice(0, 10) }}</td>
              <td>{{ r.partner?.name_en }}</td>
              <td>{{ r.goods_receipt_note?.grn_code }}</td>
              <td>
                <div v-for="l in r.lines" :key="l.id" class="pr-item">{{ l.product?.name_en }} × {{ qty(l.quantity) }}</div>
              </td>
              <td class="zv-table-num">
                {{ fmt(r.total_cost) }}
                <div v-if="r.debit_note_ids?.length" class="field-hint">{{ t('accounting.purchaseReturns.debitNotesRaised', { n: r.debit_note_ids.length }) }}</div>
              </td>
              <td>{{ r.journal_entry?.entry_number ?? '—' }}</td>
              <td><span class="zv-badge" :class="r.status === 'posted' ? 'zv-badge-green' : 'zv-badge-gray'">{{ r.status === 'posted' ? t('accounting.purchaseReturns.posted') : t('accounting.purchaseReturns.cancelled') }}</span></td>
              <td class="text-end">
                <button v-if="r.status === 'posted' && !r.debit_note_ids?.length" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="cancelReturn(r)">{{ t('accounting.purchaseReturns.cancel') }}</button>
              </td>
            </tr>
            <tr v-if="!returns.data.length"><td colspan="9" class="text-center opacity-60">{{ t('accounting.purchaseReturns.empty') }}</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :paginator="returns" />
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { router, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

const props = defineProps({
  returns:   { type: Object, required: true },
  receipts:  { type: Array, default: () => [] },
  locations: { type: Array, default: () => [] },
  filters:   { type: Object, default: () => ({}) },
})

const today = todayLocal()
const form = useForm({ goods_receipt_note_id: '', return_date: today, reason: '', lines: [] })
const search = ref(props.filters.search || '')

function pickReceipt() {
  const g = props.receipts.find(x => x.id === form.goods_receipt_note_id)
  form.lines = g ? g.lines.map(l => ({ ...l, goods_receipt_note_line_id: l.id, quantity: '', location_id: g.location_id })) : []
}

function submit() {
  form.transform(data => ({
    ...data,
    lines: data.lines.filter(l => Number(l.quantity) > 0).map(l => ({ goods_receipt_note_line_id: l.goods_receipt_note_line_id, quantity: l.quantity, location_id: l.location_id })),
  })).post(route('accounting.purchase-returns.store'), {
    preserveScroll: true,
    onSuccess: () => { form.reset(); form.lines = [] },
  })
}

function cancelReturn(r) {
  const reason = window.prompt(t('accounting.purchaseReturns.cancelPrompt'))
  if (!reason) return
  router.patch(route('accounting.purchase-returns.cancel', r.id), { cancel_reason: reason }, { preserveScroll: true })
}

function doSearch() {
  router.get(route('accounting.purchase-returns.index'), { search: search.value }, { preserveState: true, preserveScroll: true })
}

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function qty(n) { return Number(n ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 }) }
</script>

<style scoped>
.pr-qty { max-width: 120px; text-align: end; }
.pr-search { max-width: 240px; }
.pr-item { font-size: 12px; }
</style>
