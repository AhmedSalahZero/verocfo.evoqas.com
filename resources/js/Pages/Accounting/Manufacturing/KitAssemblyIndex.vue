<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/KitAssemblyIndex.vue

  Listing page for Kit Assembly transactions. "Reverse" appears only
  on a posted row — calls KitAssemblyController::reverse(), which
  puts every component back into stock, removes the kit, and
  reverses the journal entry (see KitAssemblyService::reverse()).
  Blocked server-side with a clear error if the kit has already been
  sold/moved on — shown via the flash error message.

  @layout    AppLayout
  @route     accounting.kit-assemblies.index
  @props     assemblies (paginator), filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :eyebrow="t('accounting.manufacturing.kitAssemblyIndex.eyebrow')"
      :title="t('accounting.manufacturing.kitAssemblyIndex.kitAssemblies')"
      :subtitle="t('accounting.manufacturing.kitAssemblyIndex.assembleComponentsIntoAFinished')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>'
    >
      <template #actions>
        <Link :href="route('accounting.kit-assemblies.create')" class="zv-btn zv-btn-primary zv-btn-lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('accounting.manufacturing.kitAssemblyIndex.assembleKit') }}
        </Link>
      </template>
    </PageHeader>

    <div class="zv-card">

      <EmptyState
        v-if="assemblies.data.length === 0"
        :title="t('accounting.manufacturing.kitAssemblyIndex.noKitAssembliesYet')"
        :message="t('accounting.manufacturing.kitAssemblyIndex.useTheButtonAboveToAssemble')"
        icon-path="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.manufacturing.kitAssemblyIndex.assemblyNumber') }}</th>
              <th>{{ t('accounting.manufacturing.kitAssemblyIndex.kitProduct') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.kitAssemblyIndex.quantity') }}</th>
              <th class="zv-table-num">{{ t('accounting.manufacturing.kitAssemblyIndex.totalCost') }}</th>
              <th>{{ t('accounting.manufacturing.kitAssemblyIndex.location') }}</th>
              <th>{{ t('accounting.manufacturing.kitAssemblyIndex.date') }}</th>
              <th class="text-center">{{ t('common.status') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in assemblies.data" :key="a.id">
              <td class="zv-code">{{ a.assembly_code }}</td>
              <td>
                <div class="inv-product-cell">
                  <span class="zv-code">{{ a.product?.code }}</span>
                  <span>{{ a.product?.name_en }}</span>
                </div>
              </td>
              <td class="zv-table-num">{{ fmtQty(a.quantity) }}</td>
              <td class="zv-table-num">{{ fmtMoney(a.total_cost) }}</td>
              <td>{{ a.inventory_location?.name_en }}</td>
              <td>{{ fmtDate(a.assembly_date) }}</td>
              <td class="text-center">
                <StatusBadge
                  :status="a.status"
                  :overrides="{ reversed: { label: t('accounting.manufacturing.kitAssemblyIndex.reversed'), badge: 'zv-badge-danger', dot: 'zv-status-dot-danger' } }"
                />
              </td>
              <td class="zv-col-actions">
                <button v-if="a.status === 'posted'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="reverse(a)">
                  {{ t('accounting.manufacturing.kitAssemblyIndex.reverse') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="assemblies" />
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

defineProps({
  assemblies: { type: Object, required: true },
  filters:    { type: Object, default: () => ({}) },
})

function reverse(assembly) {
  if (! confirm(t('accounting.manufacturing.kitAssemblyIndex.confirmReverse', { code: assembly.assembly_code }))) return
  router.patch(route('accounting.kit-assemblies.reverse', assembly.id), {}, { preserveScroll: true })
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
}

function fmtMoney(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<style>
.inv-product-cell { display: flex; align-items: baseline; gap: 0.6rem; }
</style>
