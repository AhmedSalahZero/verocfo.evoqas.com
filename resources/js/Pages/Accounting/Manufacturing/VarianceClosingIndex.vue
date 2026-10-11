<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/VarianceClosingIndex.vue

  Lists past Manufacturing Variance Closings and lets a person pick a
  period to preview. Manufacturing Variance Closing Design v1.0,
  Section 1/Step 3, piece 4. One closing per period ever — enforced
  server-side, this page just lists what's already been closed and
  offers a period picker for a new one.

  @layout    AppLayout
  @route     accounting.variance-closing.index
  @props     closings (paginator)
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Manufacturing"
      :title="t('accounting.manufacturing.varianceClosingIndex.title')"
      :subtitle="t('accounting.manufacturing.varianceClosingIndex.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5 3.75a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    />

    <div class="form-section mb-5">
      <div class="form-section-header">
        <div class="form-section-icon" style="background:#0F2554">
          <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
          </svg>
        </div>
        <div>
          <div class="form-section-title">{{ t('accounting.manufacturing.varianceClosingIndex.pickAPeriod') }}</div>
          <div class="form-section-sub">{{ t('accounting.manufacturing.varianceClosingIndex.pickAPeriodSub') }}</div>
        </div>
      </div>

      <div class="form-section-body">
        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.varianceClosingIndex.month') }}</label>
            <select v-model.number="form.period_month" class="zv-input">
              <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.varianceClosingIndex.year') }}</label>
            <input v-model.number="form.period_year" type="number" min="2020" max="2100" class="zv-input"/>
          </div>
        </div>
        <p v-if="form.errors.period_year || form.errors.period_month" class="zv-field-error mt-2">
          {{ form.errors.period_year || form.errors.period_month }}
        </p>
      </div>

      <div class="zv-card-footer flex items-center justify-end gap-3">
        <button type="button" class="zv-btn zv-btn-primary" :disabled="form.processing" @click="preview">
          {{ form.processing ? t('common.loading') : t('accounting.manufacturing.varianceClosingIndex.previewThisPeriod') }}
        </button>
      </div>
    </div>

    <div class="zv-card">
      <EmptyState
        v-if="closings.data.length === 0"
        :title="t('accounting.manufacturing.varianceClosingIndex.noClosingsYet')"
        :message="t('accounting.manufacturing.varianceClosingIndex.noClosingsYetSub')"
        icon-path="M9 14.25l6-6m4.5 3.75a9 9 0 11-18 0 9 9 0 0118 0z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.varianceClosingIndex.period') }}</th>
              <th>{{ t('accounting.manufacturing.varianceClosingIndex.journalEntry') }}</th>
              <th>{{ t('accounting.manufacturing.varianceClosingIndex.postedBy') }}</th>
              <th>{{ t('accounting.manufacturing.varianceClosingIndex.postedAt') }}</th>
              <th>{{ t('accounting.manufacturing.varianceClosingIndex.status') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in closings.data" :key="c.id">
              <td class="font-semibold">{{ periodLabel(c) }}</td>
              <td>{{ c.journal_entry?.entry_number ?? '—' }}</td>
              <td>{{ c.posted_by?.name ?? '—' }}</td>
              <td>{{ formatDate(c.posted_at) }}</td>
              <td>
                <span v-if="c.reversed_at" class="zv-badge" :title="c.reversal_reason">
                  {{ t('accounting.manufacturing.varianceClosingIndex.reversed') }}
                </span>
                <span v-else>{{ t('accounting.manufacturing.varianceClosingIndex.posted') }}</span>
              </td>
              <td class="text-end">
                <button v-if="!c.reversed_at" type="button" class="zv-btn zv-btn-ghost" @click="openReverse(c)">
                  {{ t('accounting.manufacturing.varianceClosingIndex.reverse') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="closings" />
    </div>

    <div v-if="reversing" class="zv-modal-backdrop" @click.self="reversing = null">
      <div class="zv-card" style="max-width:480px;margin:10vh auto">
        <div class="zv-card-header">{{ t('accounting.manufacturing.varianceClosingIndex.reverseTitle', { period: periodLabel(reversing) }) }}</div>
        <div class="zv-card-body">
          <p class="field-hint mb-3">{{ t('accounting.manufacturing.varianceClosingIndex.reverseHint') }}</p>
          <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.varianceClosingIndex.reason') }}</label>
          <textarea v-model="reverseForm.reason" rows="3" class="zv-input"></textarea>
          <p v-if="reverseForm.errors.reason" class="zv-field-error mt-1">{{ reverseForm.errors.reason }}</p>
        </div>
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <button type="button" class="zv-btn zv-btn-ghost" @click="reversing = null">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="reverseForm.processing || !reverseForm.reason.trim()" @click="submitReverse">
            {{ t('accounting.manufacturing.varianceClosingIndex.confirmReverse') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

const props = defineProps({
  closings: { type: Object, required: true },
})

const MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const monthOptions = MONTHS.slice(1).map((label, i) => ({ value: i + 1, label }))

function periodLabel(c) { return `${MONTHS[c.period_month]} ${c.period_year}` }
function formatDate(d) { return d ? String(d).slice(0, 10) : '—' }

const today = new Date()
const form = useForm({
  period_month: today.getMonth() + 1,
  period_year: today.getFullYear(),
})

const reversing = ref(null)
const reverseForm = useForm({ reason: '' })

function openReverse(c) {
  reverseForm.reset()
  reverseForm.clearErrors()
  reversing.value = c
}

function submitReverse() {
  reverseForm.post(route('accounting.variance-closing.reverse', reversing.value.id), {
    preserveScroll: true,
    onSuccess: () => { reversing.value = null },
  })
}

function preview() {
  router.post(route('accounting.variance-closing.preview'), {
    period_month: form.period_month,
    period_year: form.period_year,
  })
}
</script>
