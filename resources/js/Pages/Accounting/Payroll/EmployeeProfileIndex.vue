<!--
══════════════════════════════════════════════════════════════════
  Accounting/Payroll/EmployeeProfileIndex.vue

  Listing page for employee salary setup. Each row is one
  EmployeePayrollProfile — the expense category tag uses the same
  color vocabulary as PayrollRunShow so a reader recognizes it
  immediately once they've seen the other page.

  @layout    AppLayout
  @route     hr.employees.index
  @props     profiles (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.employeeProfileIndex.employeeSalarySetup')"
      :subtitle="t('accounting.hr.employeeProfileIndex.baseSalaryExpenseCategoryAndEndOf')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"/>'
    >
      <template #actions>
        <Link :href="route('hr.employees.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.hr.employeeProfileIndex.newProfile') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="profiles.total"
        results-label="employee"
        @clear="clearFilters"
      >
        <SelectBox v-model="category" :options="categoryOptions" value-key="value" label-key="label" :placeholder="t('accounting.hr.employeeProfileIndex.allCategories')" @update:modelValue="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="profiles.data.length === 0"
        :title="t('accounting.hr.employeeProfileIndex.noPayrollProfilesYet')"
        :message="t('accounting.hr.employeeProfileIndex.setUpAnEmployeesSalaryToInclude')"
        icon-path="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.employeeProfileIndex.employee') }}</th>
              <th>{{ t('accounting.hr.employeeProfileIndex.expenseCategory') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.employeeProfileIndex.baseSalary') }}</th>
              <th class="text-center">{{ t('accounting.hr.employeeProfileIndex.eosb') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in profiles.data" :key="p.id">
              <td class="font-semibold">{{ p.partner?.name_en }} <span class="opacity-60">({{ p.partner?.code }})</span></td>
              <td><span class="zv-tag" :class="categoryTagClass(p.expense_category)">{{ categoryLabel(p.expense_category) }}</span></td>
              <td class="zv-table-num">{{ fmt(p.base_salary) }}</td>
              <td class="text-center">{{ p.eosb_rate_percent ? p.eosb_rate_percent + '%' : '—' }}</td>
              <td class="text-center"><StatusBadge :status="p.is_active ? 'active' : 'inactive'" /></td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('hr.employees.edit', p.id)" class="zv-action-btn" :title="t('common.edit')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </Link>
                  <button type="button" class="zv-action-btn" :title="p.is_active ? t('common.deactivate') : t('common.activate')" @click="toggleActive(p)">
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

      <Pagination :paginator="profiles" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import FilterBar from '@/Components/FilterBar.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  profiles: { type: Object, required: true },
  filters:  { type: Object, default: () => ({}) },
})

// Same category vocabulary as PayrollRunShow — kept consistent.
const CATEGORY_LABELS = { manufacturing: 'Manufacturing', sales: 'Sales & Distribution', marketing: 'Marketing', general_admin: 'G&A' }
const CATEGORY_TAGS   = { manufacturing: 'zv-tag-amber', sales: '', marketing: 'zv-tag-green', general_admin: 'zv-tag-gray' }
function categoryLabel(c) { return CATEGORY_LABELS[c] ?? c }
function categoryTagClass(c) { return CATEGORY_TAGS[c] ?? 'zv-tag-gray' }

const categoryOptions = Object.entries(CATEGORY_LABELS).map(([value, label]) => ({ value, label }))

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

const category = ref(props.filters?.expense_category ?? '')
const hasActiveFilters = computed(() => !!category.value)

function applyFilters() {
  router.get(route('hr.employees.index'), { expense_category: category.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  category.value = ''
  applyFilters()
}

function toggleActive(profile) {
  router.patch(route('hr.employees.toggle-active', profile.id), {}, { preserveScroll: true })
}
</script>
