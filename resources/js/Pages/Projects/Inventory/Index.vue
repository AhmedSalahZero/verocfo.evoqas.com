<!--
══════════════════════════════════════════════════════════════════
  Projects/Inventory/Index.vue

  Projects > Project Inventory — the picker form. Pick ONE subject:
  a Project, or a standalone Sales Order (one with no parent
  Project — a Project-linked SO is reached through its parent
  Project's own statement instead, not offered twice here).

  @layout    AppLayout
  @route     projects.inventory.index
  @props     projects, salesOrders
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      :breadcrumbs="[{ label: t('projects.inventory.index.projectInventory') }]"
      :title="t('projects.inventory.index.projectInventory')"
      :subtitle="t('projects.inventory.index.pickAProjectOrAStandaloneSalesOrder')"
    />

    <div class="zv-card">
      <div class="form-section-body">

        <label class="zv-label mb-2 d-block">{{ t('projects.inventory.index.subject') }}</label>
        <div class="zv-tile-group pi-mode-group">
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': mode === 'project' }" @click="mode = 'project'; selectedId = ''">
            <div class="zv-tile-label">{{ t('projects.inventory.index.project') }}</div>
            <div class="zv-tile-desc">{{ t('projects.inventory.index.projectDesc') }}</div>
          </button>
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': mode === 'sales_order' }" @click="mode = 'sales_order'; selectedId = ''">
            <div class="zv-tile-label">{{ t('projects.inventory.index.standaloneSalesOrder') }}</div>
            <div class="zv-tile-desc">{{ t('projects.inventory.index.standaloneSalesOrderDesc') }}</div>
          </button>
        </div>

        <div class="field-divider"></div>

        <div v-if="mode === 'project'" class="field-grid field-grid-3">
          <div class="field-group field-group-span-2">
            <label class="zv-label zv-label-required">{{ t('projects.inventory.index.project') }}</label>
            <select v-model="selectedId" class="zv-select">
              <option value="" disabled>{{ t('projects.inventory.index.selectProject') }}</option>
              <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name_en }}</option>
            </select>
            <p v-if="projects.length === 0" class="field-hint">{{ t('projects.inventory.index.noProjectsFound') }}</p>
          </div>
        </div>

        <div v-else class="field-grid field-grid-3">
          <div class="field-group field-group-span-2">
            <label class="zv-label zv-label-required">{{ t('projects.inventory.index.salesOrder') }}</label>
            <select v-model="selectedId" class="zv-select">
              <option value="" disabled>{{ t('projects.inventory.index.selectSalesOrder') }}</option>
              <option v-for="so in salesOrders" :key="so.id" :value="so.id">{{ so.code }}{{ so.subject ? ' — ' + so.subject : '' }}</option>
            </select>
            <p v-if="salesOrders.length === 0" class="field-hint">{{ t('projects.inventory.index.noStandaloneSalesOrdersFound') }}</p>
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!selectedId" @click="viewStatement">
            {{ t('projects.inventory.index.viewStatement') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

defineProps({
  projects:    { type: Array, default: () => [] },
  salesOrders: { type: Array, default: () => [] },
})

const mode = ref('project')
const selectedId = ref('')

function viewStatement() {
  if (!selectedId.value) return
  router.get(route('projects.inventory.result'), {
    project_id:     mode.value === 'project' ? selectedId.value : undefined,
    sales_order_id: mode.value === 'sales_order' ? selectedId.value : undefined,
  })
}
</script>

<style>
.pi-mode-group { grid-template-columns: repeat(2, 1fr); }
@media (max-width: 700px) { .pi-mode-group { grid-template-columns: 1fr; } }
.d-block { display: block; }
</style>
