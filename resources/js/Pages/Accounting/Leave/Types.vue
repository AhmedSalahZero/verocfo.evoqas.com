<!--
══════════════════════════════════════════════════════════════════
  Accounting/Leave/Types.vue

  The per-company leave type list. Inline modal for create/edit, same
  shape as DeductionReasonIndex.vue — a leave type is four fields.

  Paid vs unpaid is presented as two tiles rather than a checkbox
  because it is the one setting here that reaches money, and the
  consequence of each is worth spelling out at the point of choosing.
  The payroll deduction reason only appears once "unpaid" is picked:
  on a paid type it has nothing to do, and the service drops it.

  Delete is offered but usually refused — a type any request has used
  can only be deactivated. The refusal explains that.

  @layout    AppLayout
  @route     hr.leave-types.index
  @props     types (paginator), deductionReasons, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.leaveTypes.leaveTypes')"
      :subtitle="t('accounting.hr.leaveTypes.theKindsOfLeaveThisCompanyGrants')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
    >
      <template #actions>
        <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="openCreate">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.hr.leaveTypes.newType') }}
        </button>
      </template>
    </PageHeader>

    <div v-if="errorMessage" class="zv-alert zv-alert-danger mb-4">{{ errorMessage }}</div>

    <div class="zv-card">
      <EmptyState
        v-if="types.data.length === 0"
        :title="t('accounting.hr.leaveTypes.noTypesYet')"
        :message="t('accounting.hr.leaveTypes.addAnnualSickUnpaid')"
        icon-path="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('common.name') }}</th>
              <th class="text-center">{{ t('accounting.hr.leaveTypes.paidLeave') }}</th>
              <th class="text-right">{{ t('accounting.hr.leaveTypes.entitlement') }}</th>
              <th>{{ t('accounting.hr.leaveTypes.deductionReason') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="lt in types.data" :key="lt.id">
              <td class="font-semibold">
                {{ lt.name_en }}
                <span v-if="lt.name_ar" class="opacity-60">/ {{ lt.name_ar }}</span>
              </td>
              <td class="text-center">
                <span class="zv-tag" :class="lt.is_paid ? 'zv-tag-gray' : 'zv-tag-amber'">
                  {{ lt.is_paid ? t('accounting.hr.leaveTypes.paidLeave') : t('accounting.hr.leaveTypes.unpaidLeave') }}
                </span>
              </td>
              <td class="text-right font-mono">
                <span v-if="lt.annual_entitlement_days !== null">{{ lt.annual_entitlement_days }}</span>
                <span v-else class="opacity-60">{{ t('accounting.hr.leaveTypes.untracked') }}</span>
              </td>
              <td class="text-sm">{{ lt.deduction_reason?.name_en || '—' }}</td>
              <td class="text-center"><StatusBadge :status="lt.is_active ? 'active' : 'inactive'" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <button type="button" class="zv-action-btn" :title="t('common.edit')" @click="openEdit(lt)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </button>
                  <button
                    type="button" class="zv-action-btn"
                    :title="lt.is_active ? t('common.deactivate') : t('common.activate')"
                    @click="toggleActive(lt)"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9"/>
                    </svg>
                  </button>
                  <button type="button" class="zv-action-btn" :title="t('common.delete')" @click="destroy(lt)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="types" />
    </div>

    <div v-if="modalOpen" class="zv-modal-backdrop zv-acc" @click.self="modalOpen = false">
      <div class="zv-modal zv-modal-sm">
        <div class="zv-modal-header">
          <div class="zv-modal-title">{{ isEdit ? t('common.edit') : t('accounting.hr.leaveTypes.newType') }}</div>
          <button type="button" class="zv-modal-close" @click="modalOpen = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <div class="zv-modal-body">
          <div class="field-group mb-3">
            <label class="zv-label zv-label-required">{{ t('accounting.hr.leaveTypes.nameEnglish') }}</label>
            <input v-model="form.name_en" type="text" class="zv-input"/>
            <p v-if="form.errors.name_en" class="zv-field-error">{{ form.errors.name_en }}</p>
          </div>

          <div class="field-group mb-3">
            <label class="zv-label">{{ t('accounting.hr.leaveTypes.nameArabic') }}</label>
            <input v-model="form.name_ar" type="text" class="zv-input" dir="rtl"/>
          </div>

          <div class="field-group mb-3">
            <div class="zv-tile-group">
              <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.is_paid }" @click="form.is_paid = true">
                {{ t('accounting.hr.leaveTypes.paidLeave') }}
                <div class="text-xs opacity-70 mt-1">{{ t('accounting.hr.leaveTypes.paidLeaveHint') }}</div>
              </button>
              <button type="button" class="zv-tile" :class="{ 'zv-tile-active': !form.is_paid }" @click="form.is_paid = false">
                {{ t('accounting.hr.leaveTypes.unpaidLeave') }}
                <div class="text-xs opacity-70 mt-1">{{ t('accounting.hr.leaveTypes.unpaidLeaveHint') }}</div>
              </button>
            </div>
          </div>

          <div class="field-group mb-3">
            <label class="zv-label">{{ t('accounting.hr.leaveTypes.annualEntitlement') }}</label>
            <input
              v-model="form.annual_entitlement_days" type="number" step="0.5" min="0" class="zv-input"
              :placeholder="t('accounting.hr.leaveTypes.daysPerFiscalYear')"
            />
            <p class="text-xs opacity-70 mt-1">{{ t('accounting.hr.leaveTypes.leaveBlankIfNotTracked') }}</p>
            <p v-if="form.errors.annual_entitlement_days" class="zv-field-error">{{ form.errors.annual_entitlement_days }}</p>
          </div>

          <!-- M-35: Egyptian Labour Law rules -->
          <div class="field-group mb-3">
            <label class="flex items-center gap-2">
              <input type="checkbox" v-model="form.skip_non_working_days"/>
              Do not count weekly rest days and public holidays inside the leave (annual leave)
            </label>
            <p class="text-xs opacity-70 mt-1">Rest days and holidays are set under Leave → Holidays.</p>
          </div>
          <div class="field-grid field-grid-3 mb-3">
            <div class="field-group">
              <label class="zv-label">Higher entitlement (days)</label>
              <input v-model="form.senior_entitlement_days" type="number" step="0.5" min="0" class="zv-input" placeholder="e.g. 30"/>
            </div>
            <div class="field-group">
              <label class="zv-label">…after years of service</label>
              <input v-model="form.senior_after_years" type="number" min="0" class="zv-input"/>
            </div>
            <div class="field-group">
              <label class="zv-label">…or at age</label>
              <input v-model="form.senior_age" type="number" min="0" class="zv-input"/>
            </div>
          </div>
          <div class="field-group mb-3">
            <label class="zv-label">Carry forward unused days (max)</label>
            <input v-model="form.carry_forward_max_days" type="number" step="0.5" min="0" class="zv-input" placeholder="Blank = no carry-forward"/>
            <p class="text-xs opacity-70 mt-1">Egyptian law: 21 days a year, 30 after 10 years' service or at age 50. Age needs the employee's date of birth.</p>
          </div>

          <!-- Only meaningful on unpaid leave — the hand-off to Payroll. -->
          <div v-if="!form.is_paid" class="field-group">
            <label class="zv-label">{{ t('accounting.hr.leaveTypes.deductionReason') }}</label>
            <SelectBox
              v-model="form.deduction_reason_id"
              :options="deductionReasons"
              value-key="id"
              label-key="name_en"
            />
            <p class="text-xs opacity-70 mt-1">{{ t('accounting.hr.leaveTypes.deductionReasonHint') }}</p>
          </div>
        </div>

        <div class="zv-modal-footer">
          <button type="button" class="zv-btn zv-btn-ghost" @click="modalOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-primary" :disabled="form.processing" @click="submit">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { router, useForm, usePage } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

defineProps({
  types:            { type: Object, required: true },
  deductionReasons: { type: Array,  default: () => [] },
  filters:          { type: Object, default: () => ({}) },
})

const page = usePage()
// "This type has been used on N requests — deactivate it instead."
const errorMessage = computed(() => (modalOpen.value ? null : page.props.errors?.name_en || null))

const modalOpen = ref(false)
const isEdit = ref(false)
const editingId = ref(null)

const form = useForm({
  name_en: '',
  name_ar: '',
  is_paid: true,
  annual_entitlement_days: '',
  skip_non_working_days: false,
  senior_entitlement_days: '',
  senior_after_years: 10,
  senior_age: 50,
  carry_forward_max_days: '',
  deduction_reason_id: '',
})

function openCreate() {
  isEdit.value = false
  editingId.value = null
  form.reset()
  form.clearErrors()   // Round 8: messages from an earlier attempt must not follow into a new form
  form.is_paid = true
  modalOpen.value = true
}

function openEdit(type) {
  isEdit.value = true
  editingId.value = type.id
  form.clearErrors()
  form.name_en = type.name_en
  form.name_ar = type.name_ar
  form.is_paid = type.is_paid
  form.annual_entitlement_days = type.annual_entitlement_days ?? ''
  form.skip_non_working_days = !!type.skip_non_working_days
  form.senior_entitlement_days = type.senior_entitlement_days ?? ''
  form.senior_after_years = type.senior_after_years ?? 10
  form.senior_age = type.senior_age ?? 50
  form.carry_forward_max_days = type.carry_forward_max_days ?? ''
  form.deduction_reason_id = type.deduction_reason_id ?? ''
  modalOpen.value = true
}

function submit() {
  // An empty entitlement means "untracked", not zero — send null so
  // the distinction survives (see LeaveTypeRequest).
  form.transform((data) => ({
    ...data,
    annual_entitlement_days: data.annual_entitlement_days === '' ? null : data.annual_entitlement_days,
    senior_entitlement_days: data.senior_entitlement_days === '' ? null : data.senior_entitlement_days,
    carry_forward_max_days: data.carry_forward_max_days === '' ? null : data.carry_forward_max_days,
    deduction_reason_id: data.deduction_reason_id === '' ? null : data.deduction_reason_id,
  }))

  const onSuccess = () => { modalOpen.value = false }

  if (isEdit.value) {
    form.put(route('hr.leave-types.update', editingId.value), { onSuccess })
  } else {
    form.post(route('hr.leave-types.store'), { onSuccess })
  }
}

function toggleActive(type) {
  router.patch(route('hr.leave-types.toggle-active', type.id), {}, { preserveScroll: true })
}

function destroy(type) {
  if (!window.confirm(t('accounting.hr.leaveTypes.confirmDelete'))) return
  router.delete(route('hr.leave-types.destroy', type.id), { preserveScroll: true })
}
</script>
<style scoped>
/* The dialog here uses the shared zv-modal system in app.css — the
   same one 20 other pages use, with its .zv-acc accounting skin and
   its print/mobile rules. This page used to carry a private copy in a
   NON-SCOPED <style> block, which leaked those rules into every other
   page and still missed the accounting skin, so the dialog looked
   like nothing else in the product. */
</style>
