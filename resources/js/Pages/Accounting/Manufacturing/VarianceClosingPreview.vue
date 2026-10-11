<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/VarianceClosingPreview.vue

  Shows the FULL calculated Manufacturing Variance Closing proposal
  for one period — nothing has been posted yet. Manufacturing
  Variance Closing Design v1.0, Section 1.4's confirmed "calculated
  automatically, held for review before posting": a person can adjust
  the Finished-Goods/remaining-segment amount for any product or Job
  Order row before confirming — the COGS side recalculates live to
  keep the row's total unchanged, so 514/515 always clear correctly
  regardless of what's overridden. Posting re-runs the calculation
  fresh on the server and applies these same overrides on top of it —
  see VarianceClosingService::post()'s own header for why.

  @layout    AppLayout
  @route     accounting.variance-closing.preview (shown on), .store (posts)
  @props     proposal
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Manufacturing"
      :title="t('accounting.manufacturing.varianceClosingPreview.title', { period: periodLabel })"
      :subtitle="t('accounting.manufacturing.varianceClosingPreview.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5 3.75a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.variance-closing.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
      </template>
    </PageHeader>

    <!-- ── Summary ── -->
    <div class="zv-card mb-5">
      <div class="zv-card-body grid grid-cols-2 gap-4">
        <div>
          <div class="text-sm opacity-70">{{ t('accounting.manufacturing.varianceClosingPreview.laborVarianceTotal') }}</div>
          <div class="text-xl font-semibold">{{ fmt(proposal.labor_variance_total) }}</div>
          <div class="text-xs opacity-60">{{ t('accounting.manufacturing.varianceClosingPreview.totalLaborHoursThisPeriod') }}: {{ proposal.total_labor_hours }}</div>
        </div>
        <div>
          <div class="text-sm opacity-70">{{ t('accounting.manufacturing.varianceClosingPreview.overheadPools') }}</div>
          <div v-for="(pool, poolId) in proposal.overhead_variance_by_pool" :key="poolId" class="text-sm">
            {{ pool.pool?.name ?? ('#' + poolId) }} — <span class="font-semibold">{{ fmt(pool.variance) }}</span>
            <span class="text-xs opacity-60">({{ pool.total_hours }} {{ t('accounting.manufacturing.varianceClosingPreview.hours') }})</span>
          </div>
        </div>
      </div>
    </div>

    <div v-if="Number(proposal.material_variance_total) !== 0 || (proposal.material_orders ?? []).length" class="zv-card mb-5">
      <div class="zv-card-header">{{ t('accounting.manufacturing.varianceClosingPreview.materialVariance') }}</div>
      <div class="zv-card-body">
        <div class="text-xl font-semibold">{{ fmt(proposal.material_variance_total) }}</div>
        <p class="field-hint">{{ t('accounting.manufacturing.varianceClosingPreview.materialVarianceHint') }}</p>
        <ul class="text-sm mt-2">
          <li v-for="m in (proposal.material_orders ?? [])" :key="m.type + m.id">{{ m.code }} — {{ fmt(m.amount) }}</li>
        </ul>
      </div>
    </div>

    <!-- ── WIP adjustments — no override, informational only ── -->
    <div v-if="wipRows.length" class="zv-card mb-5">
      <div class="zv-card-header">{{ t('accounting.manufacturing.varianceClosingPreview.wipAdjustments') }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.varianceClosingPreview.order') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.amount') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in wipRows" :key="row.key">
              <td>{{ row.label }}</td>
              <td class="zv-table-num">{{ fmt(row.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="field-hint px-4 pb-3">{{ t('accounting.manufacturing.varianceClosingPreview.wipHint') }}</p>
    </div>

    <!-- ── Products — editable split ── -->
    <div v-if="productRows.length" class="zv-card mb-5">
      <div class="zv-card-header">{{ t('accounting.manufacturing.varianceClosingPreview.products') }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.varianceClosingPreview.product') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.share') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.soldQty') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.endingQty') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.cogs') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.finishedGoods') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in productRows" :key="row.id">
              <td class="font-semibold">{{ row.product.name_en }}</td>
              <td class="zv-table-num">{{ fmt(row.share) }}</td>
              <td class="zv-table-num">{{ row.sold_qty }}</td>
              <td class="zv-table-num">{{ row.ending_qty }}</td>
              <td class="zv-table-num">{{ fmt(cogsFor('products', row.id, row.share)) }}</td>
              <td class="zv-table-num">
                <input
                  v-model="overrides.products[row.id]"
                  type="number" step="0.01"
                  class="zv-input so-cell-input text-end"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="field-hint px-4 pb-3">{{ t('accounting.manufacturing.varianceClosingPreview.productOverrideHint') }}</p>
    </div>

    <!-- ── Job Orders — editable split, own segment only ── -->
    <div v-if="jobOrderRows.length" class="zv-card mb-5">
      <div class="zv-card-header">{{ t('accounting.manufacturing.varianceClosingPreview.jobOrders') }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.varianceClosingPreview.jobOrder') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.share') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.soldQty') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.endingQty') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.cogs') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.varianceClosingPreview.remainingInSegment') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in jobOrderRows" :key="row.id">
              <td class="font-semibold">{{ row.job_order.job_code }}</td>
              <td class="zv-table-num">{{ fmt(row.share) }}</td>
              <td class="zv-table-num">{{ row.sold_qty }}</td>
              <td class="zv-table-num">{{ row.ending_qty }}</td>
              <td class="zv-table-num">{{ fmt(cogsFor('job_orders', row.id, row.share)) }}</td>
              <td class="zv-table-num">
                <input
                  v-model="overrides.job_orders[row.id]"
                  type="number" step="0.01"
                  class="zv-input so-cell-input text-end"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="field-hint px-4 pb-3">{{ t('accounting.manufacturing.varianceClosingPreview.jobOrderOverrideHint') }}</p>
    </div>

    <div v-if="!wipRows.length && !productRows.length && !jobOrderRows.length" class="zv-card">
      <EmptyState
        :title="t('accounting.manufacturing.varianceClosingPreview.nothingToClose')"
        :message="t('accounting.manufacturing.varianceClosingPreview.nothingToCloseSub')"
        icon-path="M9 14.25l6-6m4.5 3.75a9 9 0 11-18 0 9 9 0 0118 0z"
      />
    </div>

    <div v-if="hasOverrides" class="zv-card mb-5">
      <div class="zv-card-body">
        <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.varianceClosingPreview.overrideReason') }}</label>
        <textarea v-model="reason" rows="2" class="zv-input"></textarea>
        <p class="field-hint">{{ t('accounting.manufacturing.varianceClosingPreview.overrideReasonHint') }}</p>
        <p v-if="form.errors.override_reason" class="zv-field-error mt-1">{{ form.errors.override_reason }}</p>
      </div>
    </div>

    <div class="zv-card">
      <div class="zv-card-footer flex items-center justify-end gap-3">
        <Link :href="route('accounting.variance-closing.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
        <button type="button" class="zv-btn zv-btn-primary" :disabled="form.processing || (hasOverrides && !reason.trim())" @click="confirmAndPost">
          {{ form.processing ? t('common.saving') : t('accounting.manufacturing.varianceClosingPreview.confirmAndPost') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { sub as subMoney } from '@/Utils/money'
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'

const { t } = useI18n()

const props = defineProps({
  proposal: { type: Object, required: true },
})

const MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const periodLabel = computed(() => `${MONTHS[props.proposal.period_month]} ${props.proposal.period_year}`)

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

const wipRows = computed(() => (props.proposal.wip_adjustments ?? []).map((a, i) => ({
  key: i,
  label: a.type === 'production_order' ? `Production Order ${a.order.order_code}` : `Job Order ${a.order.job_code}`,
  amount: a.amount,
})))

const productRows = computed(() => Object.entries(props.proposal.product_splits ?? {}).map(([id, s]) => ({
  id, product: s.product, share: s.variance_share, sold_qty: s.sold_qty, ending_qty: s.ending_qty,
})))

const jobOrderRows = computed(() => Object.entries(props.proposal.job_order_splits ?? {}).map(([id, s]) => ({
  id, job_order: s.job_order, share: s.variance_share, sold_qty: s.sold_qty, ending_qty: s.ending_qty,
})))

// Overrides start out equal to the server's own calculated FG /
// remaining amount — editing them only changes what gets POSTED, the
// calculated numbers shown elsewhere never mutate in place.
const overrides = reactive({
  products: Object.fromEntries(Object.entries(props.proposal.product_splits ?? {}).map(([id, s]) => [id, s.fg_amount])),
  job_orders: Object.fromEntries(Object.entries(props.proposal.job_order_splits ?? {}).map(([id, s]) => [id, s.remaining_segment_amount])),
})

// COGS is always whatever's left of the row's own total share — never
// entered directly, so the two sides can never fail to add up.
function cogsFor(group, id, share) {
  const overridden = overrides[group][id] ?? 0
  return subMoney(share ?? 0, overridden)
}

const reason = ref('')

// True when any edited amount differs from what the server calculated.
const hasOverrides = computed(() => {
  const differs = (group, key) => Object.entries(props.proposal[group] ?? {}).some(([id, s]) =>
    Number(overrides[key === 'products' ? 'products' : 'job_orders'][id] ?? 0).toFixed(2) !==
    Number(key === 'products' ? s.fg_amount : s.remaining_segment_amount).toFixed(2))
  return differs('product_splits', 'products') || differs('job_order_splits', 'job_orders')
})

const form = useForm({
  override_reason: '',
  period_year: props.proposal.period_year,
  period_month: props.proposal.period_month,
  overrides: {},
})

function confirmAndPost() {
  form.override_reason = hasOverrides.value ? reason.value : ''
  form.overrides = {
    products: { ...overrides.products },
    job_orders: { ...overrides.job_orders },
  }
  form.post(route('accounting.variance-closing.store'))
}
</script>
