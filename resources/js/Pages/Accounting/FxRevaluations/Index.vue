<!--
══════════════════════════════════════════════════════════════════
  Accounting/FxRevaluations/Index.vue

  Month-End FX Revaluation (Audit H12, IAS 21). Lists past runs and
  offers a month-end picker that opens the preview. Nothing is posted
  from this page — posting happens on the preview after review.
  Same layout pattern as Manufacturing › Variance Closing.

  @layout    AppLayout
  @route     accounting.fx-revaluations.index
  @props     runs: Paginator — past runs, newest month-end first
             defaultDate: String — last day of the previous month
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="General Ledger"
      :title="t('accounting.fxRevaluations.title')"
      :subtitle="t('accounting.fxRevaluations.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>'
    />

    <div class="form-section mb-5">
      <div class="form-section-header">
        <div class="form-section-icon" style="background:#0F2554">
          <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
          </svg>
        </div>
        <div>
          <div class="form-section-title">{{ t('accounting.fxRevaluations.pickDate') }}</div>
          <div class="form-section-sub">{{ t('accounting.fxRevaluations.pickDateSub') }}</div>
        </div>
      </div>

      <div class="form-section-body">
        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.fxRevaluations.monthEnd') }}</label>
            <input v-model="monthValue" type="month" class="zv-input"/>
            <p class="field-hint">{{ monthEndDate }}</p>
          </div>
        </div>
      </div>

      <div class="zv-card-footer flex items-center justify-end gap-3">
        <Link :href="route('accounting.fx-revaluations.create', { date: monthEndDate })" class="zv-btn zv-btn-primary">
          {{ t('accounting.fxRevaluations.previewButton') }}
        </Link>
      </div>
    </div>

    <div class="zv-card">
      <EmptyState
        v-if="runs.data.length === 0"
        :title="t('accounting.fxRevaluations.noRunsYet')"
        :message="t('accounting.fxRevaluations.noRunsYetSub')"
        icon-path="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.fxRevaluations.runCode') }}</th>
              <th>{{ t('accounting.fxRevaluations.revaluationDate') }}</th>
              <th>{{ t('accounting.fxRevaluations.reversalDate') }}</th>
              <th class="zv-table-num">{{ t('accounting.fxRevaluations.netEffect') }}</th>
              <th>{{ t('accounting.fxRevaluations.journalEntry') }}</th>
              <th>{{ t('accounting.fxRevaluations.status') }}</th>
              <th>{{ t('accounting.fxRevaluations.postedBy') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in runs.data" :key="r.id">
              <td class="font-semibold">
                <Link :href="route('accounting.fx-revaluations.show', r.id)" class="fxr-link">{{ r.run_code }}</Link>
              </td>
              <td>{{ day(r.revaluation_date) }}</td>
              <td>{{ day(r.reversal_date) }}</td>
              <td class="zv-table-num" :class="Number(r.net_amount) < 0 ? 'zv-text-danger' : ''">{{ fmt(r.net_amount) }}</td>
              <td>{{ r.journal_entry?.entry_number ?? '—' }} <span v-if="r.reversal_journal_entry" class="opacity-60">/ {{ r.reversal_journal_entry.entry_number }}</span></td>
              <td>
                <span class="zv-badge" :class="r.status === 'posted' ? 'zv-badge-green' : 'zv-badge-gray'">
                  {{ r.status === 'posted' ? t('accounting.fxRevaluations.posted') : t('accounting.fxRevaluations.cancelled') }}
                </span>
              </td>
              <td>{{ r.creator?.name ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="runs" />
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

const props = defineProps({
  runs: { type: Object, required: true },
  defaultDate: { type: String, required: true },
})

// A month picker, so only a month-end can ever be chosen.
const monthValue = ref(String(props.defaultDate).slice(0, 7))
const monthEndDate = computed(() => {
  const [y, m] = (monthValue.value || String(props.defaultDate).slice(0, 7)).split('-').map(Number)
  const last = new Date(Date.UTC(y, m, 0)).getUTCDate()
  return `${y}-${String(m).padStart(2, '0')}-${String(last).padStart(2, '0')}`
})

function day(d) { return d ? String(d).slice(0, 10) : '—' }
function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
</script>

<style scoped>
.fxr-link { color: var(--zv-blue); text-decoration: none; }
.fxr-link:hover { text-decoration: underline; }
</style>
