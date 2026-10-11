<!--
══════════════════════════════════════════════════════════════════
  Accounting/Leave/Index.vue

  The leave register: every request, who it belongs to, and what has
  to happen to it next.

  Actions are shown by status rather than all at once, because the
  lifecycle is strictly ordered (draft → submitted → approved) and
  offering "Approve" on a draft would only produce a refusal from the
  service. What you can see is what will work.

  Paid vs unpaid is tagged on every row: it is the one property of a
  leave type that reaches money, so it should be visible without
  opening anything.

  @layout    AppLayout
  @route     hr.leave.index
  @props     requests (paginator), employees, leaveTypes, statuses, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.leaveIndex.leaveManagement')"
      :subtitle="t('accounting.hr.leaveIndex.everyLeaveRequestAndItsApprovalTrail')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>'
    >
      <template #actions>
        <Link :href="route('hr.leave.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.hr.leaveIndex.newRequest') }}
        </Link>
      </template>
    </PageHeader>

    <FilterBar
      :has-active-filters="hasActiveFilters"
      :results-count="requests.total"
      :results-label="t('accounting.hr.leaveIndex.request')"
      :results-label-plural="t('accounting.hr.leaveIndex.requests')"
      @clear="clearFilters"
    >
      <input
        v-model="filterForm.search"
        type="text"
        class="zv-input zv-filter-search"
        :placeholder="t('accounting.hr.leaveIndex.searchPlaceholder')"
        @keyup.enter="applyFilters"
      />
      <SelectBox
        v-model="filterForm.status"
        :options="statusOptions"
        :placeholder="t('accounting.hr.leaveIndex.allStatuses')"
        @change="applyFilters"
      />
      <SelectBox
        v-model="filterForm.partner_id"
        :options="employees"
        value-key="id"
        label-key="name_en"
        :placeholder="t('accounting.hr.leaveIndex.allEmployees')"
        @change="applyFilters"
      />
      <SelectBox
        v-model="filterForm.leave_type_id"
        :options="leaveTypes"
        value-key="id"
        label-key="name_en"
        :placeholder="t('accounting.hr.leaveIndex.allTypes')"
        @change="applyFilters"
      />
    </FilterBar>

    <div v-if="errorMessage" class="zv-alert zv-alert-danger mb-4">{{ errorMessage }}</div>

    <div class="zv-card">
      <EmptyState
        v-if="requests.data.length === 0"
        :title="t('accounting.hr.leaveIndex.noRequestsYet')"
        :message="t('accounting.hr.leaveIndex.useTheButtonAboveToAddOne')"
        icon-path="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('common.code') }}</th>
              <th>{{ t('accounting.hr.leaveIndex.employee') }}</th>
              <th>{{ t('accounting.hr.leaveIndex.leaveType') }}</th>
              <th>{{ t('accounting.hr.leaveIndex.dates') }}</th>
              <th class="text-right">{{ t('accounting.hr.leaveIndex.days') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in requests.data" :key="r.id">
              <td class="font-mono text-sm">{{ r.request_code }}</td>
              <td class="font-semibold">{{ r.partner?.name_en }}</td>
              <td>
                {{ r.leave_type?.name_en }}
                <span class="zv-tag ml-1" :class="r.leave_type?.is_paid ? 'zv-tag-gray' : 'zv-tag-amber'">
                  {{ r.leave_type?.is_paid ? t('accounting.hr.leaveIndex.paid') : t('accounting.hr.leaveIndex.unpaid') }}
                </span>
              </td>
              <td class="text-sm">{{ r.from_date }} → {{ r.to_date }}</td>
              <td class="text-right font-mono">{{ r.days_count }}</td>
              <td class="text-center"><StatusBadge :status="r.status" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link v-if="r.status === 'draft'" :href="route('hr.leave.edit', r.id)" class="zv-action-btn" :title="t('common.edit')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </Link>

                  <button v-if="r.status === 'draft'" type="button" class="zv-btn zv-btn-sm zv-btn-ghost" @click="submitRequest(r)">
                    {{ t('accounting.hr.leaveIndex.submit') }}
                  </button>

                  <button v-if="r.status === 'submitted'" type="button" class="zv-btn zv-btn-sm zv-btn-primary" @click="approveRequest(r)">
                    {{ t('accounting.hr.leaveIndex.approve') }}
                  </button>
                  <button v-if="r.status === 'submitted'" type="button" class="zv-btn zv-btn-sm zv-btn-ghost" @click="openReject(r)">
                    {{ t('accounting.hr.leaveIndex.reject') }}
                  </button>

                  <button
                    v-if="['draft', 'submitted', 'approved'].includes(r.status)"
                    type="button" class="zv-action-btn" :title="t('accounting.hr.leaveIndex.cancelRequest')"
                    @click="cancelRequest(r)"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="requests" />
    </div>

    <!-- Reject, with a reason the employee will read -->
    <div v-if="rejectOpen" class="zv-modal-backdrop zv-acc" @click.self="rejectOpen = false">
      <div class="zv-modal zv-modal-sm">
        <div class="zv-modal-header">
          <div>
            <div class="zv-modal-title">{{ t('accounting.hr.leaveIndex.rejectRequest') }}</div>
            <p class="zv-page-subtitle">{{ t('accounting.hr.leaveIndex.whyIsItBeingRejected') }}</p>
          </div>
          <button type="button" class="zv-modal-close" @click="rejectOpen = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div class="zv-modal-body">
          <!-- Round 8: a refusal used to appear only behind the dark backdrop. -->
          <div v-if="rejectForm.errors.status" class="zv-alert zv-alert-danger mb-3">{{ rejectForm.errors.status }}</div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.hr.leaveIndex.reasonOptional') }}</label>
            <textarea v-model="rejectForm.rejection_reason" rows="3" class="zv-textarea"></textarea>
          </div>
        </div>
        <div class="zv-modal-footer">
          <button type="button" class="zv-btn zv-btn-secondary" @click="rejectOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" class="zv-btn zv-btn-danger" :disabled="rejectForm.processing" @click="confirmReject">
            {{ t('accounting.hr.leaveIndex.reject') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import SelectBox from '@/Components/SelectBox.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

const props = defineProps({
  requests:   { type: Object, required: true },
  employees:  { type: Array,  default: () => [] },
  leaveTypes: { type: Array,  default: () => [] },
  statuses:   { type: Array,  default: () => [] },
  filters:    { type: Object, default: () => ({}) },
})

// Business refusals (overlap, exhausted entitlement, wrong status)
// come back as a `status` error rather than a field error.
const page = usePage()
const errorMessage = computed(() => page.props.errors?.status || null)

const filterForm = ref({
  search:        props.filters.search ?? '',
  status:        props.filters.status ?? '',
  partner_id:    props.filters.partner_id ?? '',
  leave_type_id: props.filters.leave_type_id ?? '',
})

// The controller sends the raw enum ('draft', 'submitted', …). Rendering
// it verbatim, as this page used to, showed lowercase English in both
// languages; the label is looked up and the raw value is still what gets
// sent back as the filter.
const statusOptions = computed(() =>
  props.statuses.map(s => ({
    value: s,
    label: t(`accounting.hr.leaveIndex.statusLabels.${s}`),
  })))

const hasActiveFilters = computed(() =>
  Object.values(filterForm.value).some(v => v !== '' && v !== null))

function applyFilters() {
  router.get(route('hr.leave.index'), filterForm.value, { preserveState: true, replace: true })
}

function clearFilters() {
  filterForm.value = { search: '', status: '', partner_id: '', leave_type_id: '' }
  applyFilters()
}

function submitRequest(r) {
  router.patch(route('hr.leave.submit', r.id), {}, { preserveScroll: true })
}

function approveRequest(r) {
  router.patch(route('hr.leave.approve', r.id), {}, { preserveScroll: true })
}

function cancelRequest(r) {
  if (!window.confirm(t('accounting.hr.leaveIndex.confirmCancel'))) return
  router.patch(route('hr.leave.cancel', r.id), {}, { preserveScroll: true })
}

const rejectOpen = ref(false)
const rejectingId = ref(null)
const rejectForm = useForm({ rejection_reason: '' })

function openReject(r) {
  rejectingId.value = r.id
  rejectForm.reset()
  rejectForm.clearErrors()
  rejectOpen.value = true
}

function confirmReject() {
  rejectForm.patch(route('hr.leave.reject', rejectingId.value), {
    preserveScroll: true,
    onSuccess: () => { rejectOpen.value = false },
  })
}
</script>

<style scoped>
/* The modal above uses the shared zv-modal system in app.css — the
   same one 20 other pages use, including its .zv-acc accounting skin
   and its print/mobile handling. This page used to carry a private
   copy of it in a NON-SCOPED <style> block, which both leaked those
   rules into every other page in the app and missed the accounting
   skin entirely, so the dialog did not look like any other dialog in
   the product. Nothing page-specific is needed here any more. */
</style>
