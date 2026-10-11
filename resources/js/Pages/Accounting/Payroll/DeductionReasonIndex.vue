<!--
══════════════════════════════════════════════════════════════════
  Accounting/Payroll/DeductionReasonIndex.vue

  Settings list for the Deduction Reason lookup — create/edit happen
  in an inline modal (no separate page) since a reason is just two
  fields plus its behavior. `behavior` is shown as a tag so whoever
  builds a Payroll Run line can see at a glance, before picking a
  reason, whether it reduces earned salary or just the payout.

  @layout    AppLayout
  @route     hr.deduction-reasons.index
  @props     reasons (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.deductionReasonIndex.deductionReasons')"
      :subtitle="t('accounting.hr.deductionReasonIndex.theDropdownUsedOnEveryPayrollRun')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12h-15m0 0l6.75 6.75M4.5 12l6.75-6.75"/>'
    >
      <template #actions>
        <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="openCreate">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.hr.deductionReasonIndex.newReason') }}
        </button>
      </template>
    </PageHeader>

    <div class="zv-card">
      <EmptyState
        v-if="reasons.data.length === 0"
        :title="t('accounting.hr.deductionReasonIndex.noDeductionReasonsYet')"
        :message="t('accounting.hr.deductionReasonIndex.useTheButtonAboveToAddOne')"
        icon-path="M19.5 12h-15m0 0l6.75 6.75M4.5 12l6.75-6.75"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('common.name') }}</th>
              <th>{{ t('accounting.hr.deductionReasonIndex.behavior') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in reasons.data" :key="r.id">
              <td class="font-semibold">{{ r.name_en }} <span v-if="r.name_ar" class="opacity-60">/ {{ r.name_ar }}</span></td>
              <td>
                <span class="zv-tag" :class="r.behavior === 'earned_reduction' ? 'zv-tag-amber' : 'zv-tag-gray'">
                  {{ r.behavior === 'earned_reduction' ? t('accounting.hr.deductionReasonIndex.reducesEarnedSalary') : t('accounting.hr.deductionReasonIndex.reducesPayoutOnly') }}
                </span>
              </td>
              <td class="text-center"><StatusBadge :status="r.is_active ? 'active' : 'inactive'" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <button type="button" class="zv-action-btn" :title="t('common.edit')" @click="openEdit(r)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </button>
                  <button type="button" class="zv-action-btn" :title="r.is_active ? t('common.deactivate') : t('common.activate')" @click="toggleActive(r)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="reasons" />
    </div>

    <div v-if="modalOpen" class="dr-modal-backdrop" @click.self="modalOpen = false">
      <div class="dr-modal">
        <div class="dr-modal-header">
          <h3 class="dr-modal-title">{{ isEdit ? t('common.edit') : t('accounting.hr.deductionReasonIndex.newReason') }}</h3>
          <button type="button" class="dr-modal-close" @click="modalOpen = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <div class="dr-modal-body">
          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.hr.deductionReasonIndex.nameEnglish') }}</label>
            <input v-model="form.name_en" type="text" class="zv-input"/>
            <p v-if="form.errors.name_en" class="zv-field-error">{{ form.errors.name_en }}</p>
          </div>
          <div class="field-group mb-3">
            <label class="zv-label">{{ t('accounting.hr.deductionReasonIndex.nameArabic') }}</label>
            <input v-model="form.name_ar" type="text" class="zv-input" dir="rtl"/>
          </div>
          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.hr.deductionReasonIndex.behavior') }}</label>
            <p class="text-sm opacity-70 mb-2">{{ t('accounting.hr.deductionReasonIndex.setOnceHereNeverDecidedPerLine') }}</p>
            <div class="zv-tile-group">
              <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.behavior === 'earned_reduction' }" @click="form.behavior = 'earned_reduction'">
                {{ t('accounting.hr.deductionReasonIndex.reducesEarnedSalary') }}
                <div class="text-xs opacity-70 mt-1">{{ t('accounting.hr.deductionReasonIndex.eGUnpaidLeaveShrinksTheExpense') }}</div>
              </button>
              <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.behavior === 'payout_penalty' }" @click="form.behavior = 'payout_penalty'">
                {{ t('accounting.hr.deductionReasonIndex.reducesPayoutOnly') }}
                <div class="text-xs opacity-70 mt-1">{{ t('accounting.hr.deductionReasonIndex.eGDisciplinaryFineTheExpenseStays') }}</div>
              </button>
            </div>
            <p v-if="form.errors.behavior" class="zv-field-error">{{ form.errors.behavior }}</p>
          </div>
        </div>

        <div class="dr-modal-footer">
          <button type="button" class="zv-btn zv-btn-ghost" @click="modalOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="form.processing" @click="submit">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { router, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

defineProps({
  reasons: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

const modalOpen = ref(false)
const isEdit = ref(false)
const editingId = ref(null)

const form = useForm({ name_en: '', name_ar: '', behavior: 'earned_reduction' })

function openCreate() {
  isEdit.value = false
  editingId.value = null
  form.reset()
  form.behavior = 'earned_reduction'
  modalOpen.value = true
}

function openEdit(reason) {
  isEdit.value = true
  editingId.value = reason.id
  form.name_en = reason.name_en
  form.name_ar = reason.name_ar
  form.behavior = reason.behavior
  modalOpen.value = true
}

function submit() {
  const onSuccess = () => { modalOpen.value = false }
  if (isEdit.value) {
    form.put(route('hr.deduction-reasons.update', editingId.value), { onSuccess })
  } else {
    form.post(route('hr.deduction-reasons.store'), { onSuccess })
  }
}

function toggleActive(reason) {
  router.patch(route('hr.deduction-reasons.toggle-active', reason.id), {}, { preserveScroll: true })
}
</script>

<style>
/* ── Modal — same pattern as BorrowingRateIndex.vue / ProductionOrderShow.vue ── */
.dr-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 400; display: flex; align-items: center; justify-content: center; }
.dr-modal { width: 460px; max-width: 92vw; background: var(--zv-bg-card, #fff); border-radius: var(--zv-radius-lg, 14px); border: 1px solid var(--zv-border, #e2e8f0); box-shadow: var(--zv-shadow-md); }
.dr-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--zv-border, #e2e8f0); }
.dr-modal-title { font-size: 15px; font-weight: 700; color: var(--zv-text-primary, #0f172a); }
.dr-modal-close { width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--zv-text-muted, #94a3b8); }
.dr-modal-close:hover { background: var(--zv-bg-surface, #f1f5f9); }
.dr-modal-close svg { width: 16px; height: 16px; }
.dr-modal-body { padding: 20px; }
.dr-modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid var(--zv-border, #e2e8f0); }
</style>

