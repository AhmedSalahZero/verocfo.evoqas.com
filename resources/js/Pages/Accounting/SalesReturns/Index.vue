<!--
══════════════════════════════════════════════════════════════════
  Accounting/SalesReturns/Index.vue

  Accounting › Sales › Sales Returns (Audit H16). One page:
  a "New Sales Return" panel on top (pick a posted Delivery Note,
  enter the quantity coming back per line and where it goes, choose
  what happens to goods already invoiced — credit note or
  replacement), and the list of posted returns below with Cancel.
  A return is posted when it is saved — there is no draft and no
  edit (Rule 2 does not apply: nothing to edit). Goods go back into
  stock at their original delivery cost.

  @layout    AppLayout
  @route     accounting.sales-returns.index
  @props     returns: Paginator — SalesReturnService::paginate()
             deliveries: Array — returnable Delivery Notes and lines
             locations: Array — inventory locations
             filters: Object — { search }
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">
    <PageHeader
      eyebrow="Sales"
      :title="t('accounting.salesReturns.title')"
      :subtitle="t('accounting.salesReturns.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 14L4 9l5-5M4 9h10.5a5.5 5.5 0 010 11H11"/>'
    />

    <!-- New return -->
    <div class="form-section mb-5">
      <div class="form-section-header">
        <div>
          <div class="form-section-title">{{ t('accounting.salesReturns.newReturn') }}</div>
          <div class="form-section-sub">{{ t('accounting.salesReturns.newReturnSub') }}</div>
        </div>
      </div>
      <div class="form-section-body">
        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.salesReturns.deliveryNote') }}</label>
            <select v-model="form.delivery_note_id" class="zv-input" @change="pickDelivery">
              <option value="">{{ t('accounting.salesReturns.selectDelivery') }}</option>
              <option v-for="d in deliveries" :key="d.id" :value="d.id">{{ d.code }} — {{ d.partner }} ({{ d.date }})</option>
            </select>
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.salesReturns.returnDate') }}</label>
            <input v-model="form.return_date" type="date" class="zv-input"/>
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.salesReturns.reason') }}</label>
            <input v-model="form.reason" type="text" class="zv-input"/>
          </div>
        </div>

        <div class="field-group mt-4">
          <label class="zv-label">{{ t('accounting.salesReturns.invoicedTreatment') }}</label>
          <div class="zv-tile-group">
            <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.invoiced_treatment === 'credit' }" @click="form.invoiced_treatment = 'credit'">
              <strong>{{ t('accounting.salesReturns.treatmentCredit') }}</strong>
              <span class="field-hint">{{ t('accounting.salesReturns.treatmentCreditHint') }}</span>
            </button>
            <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.invoiced_treatment === 'replace' }" @click="form.invoiced_treatment = 'replace'">
              <strong>{{ t('accounting.salesReturns.treatmentReplace') }}</strong>
              <span class="field-hint">{{ t('accounting.salesReturns.treatmentReplaceHint') }}</span>
            </button>
          </div>
          <p class="field-hint">{{ t('accounting.salesReturns.notInvoicedHint') }}</p>
        </div>

        <div v-if="form.lines.length" class="zv-table-wrap mt-4">
          <table class="zv-table">
            <thead>
              <tr>
                <th>{{ t('accounting.salesReturns.item') }}</th>
                <th class="zv-table-num">{{ t('accounting.salesReturns.returnable') }}</th>
                <th class="zv-table-num">{{ t('accounting.salesReturns.unitCost') }}</th>
                <th class="zv-table-num">{{ t('accounting.salesReturns.quantity') }}</th>
                <th>{{ t('accounting.salesReturns.backToLocation') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in form.lines" :key="l.delivery_note_line_id">
                <td>{{ l.product || l.description }}</td>
                <td class="zv-table-num">{{ qty(l.returnable) }}</td>
                <td class="zv-table-num">{{ fmt(l.unit_cost) }}</td>
                <td class="zv-table-num"><input v-model="l.quantity" type="number" step="0.0001" min="0" :max="l.returnable" class="zv-input sr-qty"/></td>
                <td>
                  <select v-model="l.location_id" class="zv-input">
                    <option v-for="loc in locations" :key="loc.id" :value="loc.id">{{ loc.code }} — {{ loc.name_en }}</option>
                  </select>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="!deliveries.length" class="field-hint mt-4">{{ t('accounting.salesReturns.noDeliveries') }}</p>
      </div>
      <div class="zv-card-footer flex justify-end">
        <button type="button" class="zv-btn zv-btn-primary" :disabled="form.processing || !form.lines.length" @click="submit">
          {{ t('accounting.salesReturns.postReturn') }}
        </button>
      </div>
    </div>

    <!-- List -->
    <div class="zv-card">
      <div class="zv-card-header flex justify-between items-center">
        <span>{{ t('accounting.salesReturns.list') }}</span>
        <input v-model="search" type="search" class="zv-input sr-search" :placeholder="t('accounting.salesReturns.search')" @keyup.enter="doSearch"/>
      </div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.salesReturns.code') }}</th>
              <th>{{ t('accounting.salesReturns.date') }}</th>
              <th>{{ t('accounting.salesReturns.customer') }}</th>
              <th>{{ t('accounting.salesReturns.deliveryNote') }}</th>
              <th>{{ t('accounting.salesReturns.items') }}</th>
              <th class="zv-table-num">{{ t('accounting.salesReturns.costBack') }}</th>
              <th>{{ t('accounting.salesReturns.treatment') }}</th>
              <th>{{ t('accounting.salesReturns.status') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in returns.data" :key="r.id">
              <td>{{ r.return_code }}</td>
              <td>{{ String(r.return_date).slice(0, 10) }}</td>
              <td>{{ r.partner?.name_en }}</td>
              <td>{{ r.delivery_note?.delivery_code }}</td>
              <td>
                <div v-for="l in r.lines" :key="l.id" class="sr-item">{{ l.product?.name_en }} × {{ qty(l.quantity) }}</div>
              </td>
              <td class="zv-table-num">{{ fmt(r.total_cost) }}</td>
              <td>
                {{ r.invoiced_treatment === 'credit' ? t('accounting.salesReturns.treatmentCredit') : t('accounting.salesReturns.treatmentReplace') }}
                <div v-if="r.credit_note_ids?.length" class="field-hint">{{ t('accounting.salesReturns.creditNotesRaised', { n: r.credit_note_ids.length }) }}</div>
              </td>
              <td><span class="zv-badge" :class="r.status === 'posted' ? 'zv-badge-green' : 'zv-badge-gray'">{{ r.status === 'posted' ? t('accounting.salesReturns.posted') : t('accounting.salesReturns.cancelled') }}</span></td>
              <td class="text-end">
                <button v-if="r.status === 'posted' && !r.credit_note_ids?.length" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="cancelReturn(r)">{{ t('accounting.salesReturns.cancel') }}</button>
              </td>
            </tr>
            <tr v-if="!returns.data.length"><td colspan="9" class="text-center opacity-60">{{ t('accounting.salesReturns.empty') }}</td></tr>
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
  returns:    { type: Object, required: true },
  deliveries: { type: Array, default: () => [] },
  locations:  { type: Array, default: () => [] },
  filters:    { type: Object, default: () => ({}) },
})

const today = todayLocal()
const form = useForm({ delivery_note_id: '', return_date: today, invoiced_treatment: 'credit', reason: '', lines: [] })
const search = ref(props.filters.search || '')

function pickDelivery() {
  const d = props.deliveries.find(x => x.id === form.delivery_note_id)
  form.lines = d ? d.lines.map(l => ({ ...l, delivery_note_line_id: l.id, quantity: '', location_id: l.location_id })) : []
}

function submit() {
  form.transform(data => ({
    ...data,
    lines: data.lines.filter(l => Number(l.quantity) > 0).map(l => ({ delivery_note_line_id: l.delivery_note_line_id, quantity: l.quantity, location_id: l.location_id })),
  })).post(route('accounting.sales-returns.store'), {
    preserveScroll: true,
    onSuccess: () => { form.reset(); form.lines = [] },
  })
}

function cancelReturn(r) {
  const reason = window.prompt(t('accounting.salesReturns.cancelPrompt'))
  if (!reason) return
  router.patch(route('accounting.sales-returns.cancel', r.id), { cancel_reason: reason }, { preserveScroll: true })
}

function doSearch() {
  router.get(route('accounting.sales-returns.index'), { search: search.value }, { preserveState: true, preserveScroll: true })
}

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function qty(n) { return Number(n ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 }) }
</script>

<style scoped>
.sr-qty { max-width: 120px; text-align: end; }
.sr-search { max-width: 240px; }
.sr-item { font-size: 12px; }
.zv-tile { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; text-align: start; }
</style>
