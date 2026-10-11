<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/MachineIndex.vue

  Listing page for Machines (Work Centers) — VeroCFO_Manufacturing_
  Costing_Redesign_v1_0.md, Section 4. Each row shows the machine's
  auto-code, its Process, which Overhead Pool(s) it feeds, its
  optional linked Fixed Asset, and its status.

  @layout    AppLayout
  @route     accounting.machines.index
  @props     machines (paginator), processes, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.manufacturing.machineIndex.machinesWorkCenters')"
      :subtitle="t('accounting.manufacturing.machineIndex.thePhysicalEquipmentEachTagged')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25z"/>'
    >
      <template #actions>
        <Link :href="route('accounting.machines.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.machineIndex.newMachine') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <FilterBar
        :has-active-filters="hasActiveFilters"
        :results-count="machines.total"
        results-label="machine"
        results-label-plural="machines"
        @clear="clearFilters"
      >
        <input v-model="search" type="text" class="zv-input machine-search" :placeholder="t('accounting.manufacturing.machineIndex.searchByCodeOrName')" @input="debouncedApplyFilters"/>
        <SelectBox v-model="processId" :options="processes" value-key="id" label-key="name" :placeholder="t('accounting.manufacturing.machineIndex.allProcesses')" class="zv-filter-select" @change="applyFilters" />
        <SelectBox v-model="status" :options="statusOptions" value-key="value" label-key="label" :placeholder="t('accounting.manufacturing.machineIndex.allStatuses')" class="zv-filter-select" @change="applyFilters" />
      </FilterBar>

      <EmptyState
        v-if="machines.data.length === 0"
        :title="hasActiveFilters ? t('accounting.manufacturing.machineIndex.noMachinesMatchThisSearch') : t('accounting.manufacturing.machineIndex.noMachinesYet')"
        :message="hasActiveFilters ? t('accounting.manufacturing.machineIndex.tryDifferentFiltersOr') : t('accounting.manufacturing.machineIndex.useTheButtonAboveToRegister')"
        icon-path="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5"
      >
        <template v-if="hasActiveFilters" #action>
          <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm mt-3" @click="clearFilters">{{ t('accounting.manufacturing.machineIndex.clearFilters') }}</button>
        </template>
      </EmptyState>

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.machineIndex.code') }}</th>
              <th>{{ t('accounting.manufacturing.machineIndex.machineName') }}</th>
              <th>{{ t('accounting.manufacturing.machineIndex.process') }}</th>
              <th>{{ t('accounting.manufacturing.machineIndex.overheadPools') }}</th>
              <th>{{ t('accounting.manufacturing.machineIndex.fixedAsset') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in machines.data" :key="m.id">
              <td class="font-semibold">{{ m.code }}</td>
              <td>{{ m.name }}</td>
              <td>{{ m.process?.name }}</td>
              <td>
                <span v-if="!poolNames(m).length" class="opacity-50">—</span>
                <span v-else>{{ poolNames(m).join(', ') }}</span>
              </td>
              <td>
                <span v-if="m.fixed_asset" class="zv-badge zv-badge-blue">{{ m.fixed_asset.asset_code }}</span>
                <span v-else class="opacity-50">—</span>
              </td>
              <td class="text-center">
                <StatusBadge :status="m.status" :overrides="MACHINE_STATUS_BADGES" />
              </td>
              <td class="zv-col-actions">
                <div class="zv-row-actions">
                  <Link :href="route('accounting.machines.edit', m.id)" class="zv-action-btn" :title="t('common.edit')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="machines" />
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
  machines:  { type: Object, required: true },
  processes: { type: Array,  default: () => [] },
  filters:   { type: Object, default: () => ({}) },
})

const MACHINE_STATUS_BADGES = {
  active:            { label: 'Active',            badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
  under_maintenance: { label: 'Under Maintenance',  badge: 'zv-badge-amber', dot: 'zv-status-dot-amber' },
  retired:           { label: 'Retired',            badge: 'zv-badge-gray',  dot: 'zv-status-dot-gray'  },
}

const statusOptions = [
  { value: 'active',            label: t('accounting.manufacturing.machineForm.active') },
  { value: 'under_maintenance', label: t('accounting.manufacturing.machineForm.underMaintenance') },
  { value: 'retired',           label: t('accounting.manufacturing.machineForm.retired') },
]

const search    = ref(props.filters?.search ?? '')
const processId = ref(props.filters?.process_id ?? '')
const status    = ref(props.filters?.status ?? '')

const hasActiveFilters = computed(() => !!search.value || !!processId.value || !!status.value)

let debounceTimer = null
function debouncedApplyFilters() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(applyFilters, 350)
}

function applyFilters() {
  router.get(route('accounting.machines.index'), {
    search: search.value || undefined,
    process_id: processId.value || undefined,
    status: status.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

function clearFilters() {
  search.value = ''
  processId.value = ''
  status.value = ''
  applyFilters()
}

function poolNames(machine) {
  return (machine.machine_overhead_pools ?? []).map(l => l.overhead_pool?.name).filter(Boolean)
}
</script>

<style>
.machine-search { max-width: 16rem; }
</style>
