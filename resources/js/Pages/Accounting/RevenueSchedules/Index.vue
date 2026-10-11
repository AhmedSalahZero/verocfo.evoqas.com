<!--
══════════════════════════════════════════════════════════════════
  Accounting/RevenueSchedules/Index.vue

  Audit H18 (IFRS 15 over time). Service contracts invoiced for a
  whole period at once (e.g. a year's maintenance) are held in 236
  Deferred Revenue and released month by month. This page shows each
  schedule's progress and runs the monthly release: every month due
  on or before the chosen date moves from 236 to revenue.

  @layout    AppLayout
  @route     accounting.revenue-schedules.index
  @props     schedules: Paginator — RevenueSchedule with lines
             due: Object — {date, count, amount} not yet released
             filters: Object — status, date
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">
    <PageHeader
      eyebrow="Customers Receivables"
      :title="t('accounting.revenueSchedules.title')"
      :subtitle="t('accounting.revenueSchedules.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75"/>'
    />

    <!-- Monthly release -->
    <div class="form-section mb-5">
      <div class="form-section-header">
        <div>
          <div class="form-section-title">{{ t('accounting.revenueSchedules.releaseTitle') }}</div>
          <div class="form-section-sub">{{ t('accounting.revenueSchedules.releaseSub') }}</div>
        </div>
      </div>
      <div class="form-section-body">
        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.revenueSchedules.upTo') }}</label>
            <input v-model="releaseDate" type="date" class="zv-input" @change="refreshDue"/>
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.revenueSchedules.dueNow') }}</label>
            <div class="zv-readonly-field">{{ t('accounting.revenueSchedules.dueSummary', { count: due.count, amount: fmt(due.amount) }) }}</div>
          </div>
        </div>
      </div>
      <div class="zv-card-footer flex justify-end">
        <button type="button" class="zv-btn zv-btn-primary" :disabled="!due.count || releasing" @click="release">{{ t('accounting.revenueSchedules.releaseButton') }}</button>
      </div>
    </div>

    <!-- Schedules -->
    <div class="zv-card">
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.revenueSchedules.invoice') }}</th>
              <th>{{ t('accounting.revenueSchedules.customer') }}</th>
              <th>{{ t('accounting.revenueSchedules.line') }}</th>
              <th>{{ t('accounting.revenueSchedules.period') }}</th>
              <th class="zv-table-num">{{ t('accounting.revenueSchedules.total') }}</th>
              <th class="zv-table-num">{{ t('accounting.revenueSchedules.released') }}</th>
              <th class="zv-table-num">{{ t('accounting.revenueSchedules.stillDeferred') }}</th>
              <th>{{ t('accounting.revenueSchedules.status') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="s in schedules.data" :key="s.id">
              <tr class="rs-row" @click="open = open === s.id ? null : s.id">
                <td><span class="zv-code">{{ s.customer_invoice?.invoice_code }}</span></td>
                <td>{{ s.customer_invoice?.partner?.name_en }}</td>
                <td><div class="zv-cell-clamp">{{ s.customer_invoice_line?.description }}</div></td>
                <td>{{ day(s.period_start) }} → {{ day(s.period_end) }}</td>
                <td class="zv-table-num">{{ fmt(s.total_amount) }}</td>
                <td class="zv-table-num">{{ fmt(s.released_amount) }}</td>
                <td class="zv-table-num">{{ fmt(s.total_amount - s.released_amount) }}</td>
                <td><span class="zv-badge" :class="badge(s.status)">{{ t('accounting.revenueSchedules.status_' + s.status) }}</span></td>
              </tr>
              <tr v-if="open === s.id">
                <td colspan="8">
                  <div class="rs-slices">
                    <span v-for="l in s.lines" :key="l.id" class="zv-badge" :class="l.status === 'released' ? 'zv-badge-green' : (l.status === 'cancelled' ? 'zv-badge-gray' : 'zv-badge-amber')">
                      {{ monthLabel(l.period_month) }} · {{ fmt(l.amount) }}
                    </span>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="!schedules.data.length"><td colspan="8" class="text-center opacity-60">{{ t('accounting.revenueSchedules.none') }}</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :paginator="schedules" />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

const props = defineProps({
  schedules: { type: Object, required: true },
  due: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

const releaseDate = ref(props.filters.date || props.due.date)
const releasing = ref(false)
const open = ref(null)

function refreshDue() {
  router.get(route('accounting.revenue-schedules.index'), { ...props.filters, date: releaseDate.value }, { preserveScroll: true, preserveState: true })
}

function release() {
  if (!confirm(t('accounting.revenueSchedules.confirmRelease'))) return
  releasing.value = true
  router.post(route('accounting.revenue-schedules.release'), { date: releaseDate.value }, { preserveScroll: true, onFinish: () => { releasing.value = false } })
}

function badge(s) { return s === 'completed' ? 'zv-badge-green' : (s === 'cancelled' ? 'zv-badge-gray' : 'zv-badge-amber') }
function monthLabel(d) { const x = new Date(String(d).slice(0, 10) + 'T00:00:00'); return x.toLocaleString(undefined, { month: 'short', year: 'numeric' }) }
function day(d) { return d ? String(d).slice(0, 10) : '—' }
function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
</script>

<style scoped>
.rs-row { cursor: pointer; }
.rs-slices { display: flex; flex-wrap: wrap; gap: 6px; padding: 6px 0; }
</style>
