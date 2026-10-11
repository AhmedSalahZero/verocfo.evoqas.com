<!--
══════════════════════════════════════════════════════════════════
  Accounting/Leave/Form.vue

  Raise or edit a leave request. Only drafts reach this page — the
  index hides the edit action for anything further along, and
  LeaveRequestService refuses it anyway.

  Two things happen live as the form is filled in, both to answer a
  question before the server has to refuse it:

    - the day count, computed the same inclusive way the service
      computes it (5 March to 7 March = 3), shown next to the dates;
    - the employee's balances across every active leave type, fetched
      as soon as an employee is chosen, so nobody submits a request
      for 10 days when 3 remain.

  Neither is authoritative. The service re-checks both, and its
  answer wins — this only saves a round trip.

  @layout    AppLayout
  @route     hr.leave.create / hr.leave.edit
  @props     leaveRequest (nullable), employees, leaveTypes
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="isEdit ? t('accounting.hr.leaveForm.editLeaveRequest') : t('accounting.hr.leaveForm.newLeaveRequest')"
      :subtitle="t('accounting.hr.leaveForm.raiseTimeOffForAnEmployee')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25"/>'
    />

    <div class="lv-layout">
      <div>
        <!-- ══════════════════ Who and what ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#0F2554">
              <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a8.25 8.25 0 0115 0"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('accounting.hr.leaveForm.whoIsTakingLeave') }}</div>
              <div class="form-section-sub">{{ t('accounting.hr.leaveForm.employeeAndLeaveType') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="field-grid field-grid-2">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.hr.leaveForm.employee') }}</label>
                <SelectBox
                  v-model="form.partner_id"
                  :options="employees"
                  value-key="id"
                  label-key="name_en"
                  :placeholder="t('accounting.hr.leaveForm.selectEmployee')"
                  :clearable="false"
                  :error="!!form.errors.partner_id"
                  @change="loadBalances"
                />
                <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.hr.leaveForm.leaveType') }}</label>
                <SelectBox
                  v-model="form.leave_type_id"
                  :options="leaveTypes"
                  value-key="id"
                  label-key="name_en"
                  :placeholder="t('accounting.hr.leaveForm.selectLeaveType')"
                  :clearable="false"
                  :error="!!form.errors.leave_type_id"
                />
                <p v-if="form.errors.leave_type_id" class="zv-field-error">{{ form.errors.leave_type_id }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════ When ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#1D4ED8">
              <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('accounting.hr.leaveForm.whenIsTheLeave') }}</div>
              <div class="form-section-sub">{{ t('accounting.hr.leaveForm.bothDatesAreCounted') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="field-grid field-grid-2">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.hr.leaveForm.fromDate') }}</label>
                <input v-model="form.from_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.from_date }"/>
                <p v-if="form.errors.from_date" class="zv-field-error">{{ form.errors.from_date }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.hr.leaveForm.toDate') }}</label>
                <input v-model="form.to_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.to_date }"/>
                <p v-if="form.errors.to_date" class="zv-field-error">{{ form.errors.to_date }}</p>
              </div>
            </div>

            <div v-if="dayCount !== null" class="lv-days">
              <span class="lv-days-value">{{ dayCount }}</span>
              <span class="lv-days-label">
                {{ t('accounting.hr.leaveForm.daysRequested') }}
                <small>{{ t('accounting.hr.leaveForm.inclusiveOfBothDates') }}</small>
              </span>
            </div>

            <div class="field-group mt-4">
              <label class="zv-label">{{ t('accounting.hr.leaveForm.reason') }}</label>
              <textarea
                v-model="form.reason" rows="3" class="zv-textarea"
                :placeholder="t('accounting.hr.leaveForm.reasonPlaceholder')"
              ></textarea>
            </div>
          </div>
        </div>

        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3">
            <Link :href="route('hr.leave.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="form.processing" @click="submit">
              {{ t('common.save') }}
            </button>
          </div>
        </div>
      </div>

      <!-- ══════════ Balances: what is left, before submitting ══════════ -->
      <div class="form-section">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.hr.leaveForm.currentBalances') }}</div>
            <div class="form-section-sub">{{ t('accounting.hr.leaveForm.whatIsLeftThisYear') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <p v-if="!form.partner_id" class="zv-text-muted text-sm">
            {{ t('accounting.hr.leaveForm.pickAnEmployeeToSeeBalances') }}
          </p>

          <table v-else-if="balances.length" class="zv-table zv-table-compact">
            <thead>
              <tr>
                <th>{{ t('accounting.hr.leaveForm.leaveType') }}</th>
                <th class="text-right">{{ t('accounting.hr.leaveForm.taken') }}</th>
                <th class="text-right">{{ t('accounting.hr.leaveForm.remaining') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in balances" :key="b.leave_type_id">
                <td>{{ b.name_en }}</td>
                <td class="text-right zv-table-num">{{ b.taken }}</td>
                <td class="text-right zv-table-num">
                  <span v-if="b.tracked">{{ b.remaining }}</span>
                  <span v-else class="zv-text-muted">{{ t('accounting.hr.leaveForm.notTracked') }}</span>
                </td>
              </tr>
            </tbody>
          </table>

          <p v-else class="zv-text-muted text-sm">{{ t('accounting.hr.leaveForm.noBalancesToShow') }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  leaveRequest: { type: Object, default: null },
  employees:    { type: Array,  default: () => [] },
  leaveTypes:   { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.leaveRequest)

const form = useForm({
  partner_id:    props.leaveRequest?.partner_id ?? '',
  leave_type_id: props.leaveRequest?.leave_type_id ?? '',
  from_date:     props.leaveRequest?.from_date ?? '',
  to_date:       props.leaveRequest?.to_date ?? '',
  reason:        props.leaveRequest?.reason ?? '',
})

/**
 * Inclusive of both ends, matching LeaveRequestService::calculateDays().
 * Null while the dates are incomplete or the wrong way round — the
 * form shows nothing rather than a misleading number.
 */
const dayCount = computed(() => {
  if (!form.from_date || !form.to_date) return null
  const from = new Date(form.from_date)
  const to = new Date(form.to_date)
  if (Number.isNaN(from) || Number.isNaN(to) || to < from) return null
  return Math.round((to - from) / 86400000) + 1
})

const balances = ref([])

// Round 8: a slow reply for an earlier employee must never replace the
// balances of the employee chosen afterwards (it showed the wrong person's
// remaining days). Only the newest request may write.
let balanceTicket = 0

async function loadBalances() {
  const ticket = ++balanceTicket
  balances.value = []
  if (!form.partner_id) return

  try {
    const response = await fetch(route('hr.leave.balances', form.partner_id), {
      headers: { Accept: 'application/json' },
    })
    if (!response.ok) return
    const payload = await response.json()
    if (ticket !== balanceTicket) return
    balances.value = payload.balances ?? []
  } catch {
    // A balance panel that cannot load is not a reason to block the
    // form — the server enforces entitlement regardless.
    if (ticket === balanceTicket) balances.value = []
  }
}

onMounted(() => { if (form.partner_id) loadBalances() })

function submit() {
  if (isEdit.value) {
    form.put(route('hr.leave.update', props.leaveRequest.id))
  } else {
    form.post(route('hr.leave.store'))
  }
}
</script>

<style scoped>
/* Only what the shared system does not already provide. The sections,
   the field grids and the footer are app-wide classes (form-section,
   field-grid-2, zv-card-footer) — this page used to hand-roll all
   three as .lv-grid / .lv-actions, which is why it did not look like
   any other form in the product. */

.lv-layout { display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 20px; align-items: start; }
@media (max-width: 1100px) { .lv-layout { grid-template-columns: 1fr; } }

/* The day count, called out because it is the one derived number on
   this form and the thing the approver actually reads. */
.lv-days { display: flex; align-items: center; gap: 12px; margin-top: 16px; padding: 12px 16px; border-radius: var(--zv-acc-radius-md, 10px); background: var(--zv-bg-surface); }
.lv-days-value { font-size: 1.625rem; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--zv-text-primary); }
.lv-days-label { font-size: 0.8125rem; font-weight: 600; color: var(--zv-text-secondary); display: flex; flex-direction: column; }
.lv-days-label small { font-weight: 400; color: var(--zv-text-muted); }
</style>
