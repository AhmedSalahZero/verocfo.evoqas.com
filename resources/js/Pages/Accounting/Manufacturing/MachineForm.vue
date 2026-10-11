<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/MachineForm.vue

  Create and Edit share one page (Rule 2) — mode by props.machine?.id.
  VeroCFO_Manufacturing_Costing_Redesign_v1_0.md, Section 4.

  Three sections: Identity (name, Process, status), Overhead Pools
  (multi-select tiles — at least one required, Section 4.4), and the
  OPTIONAL Fixed Asset link (Section 4.2 discussion — a Machine is an
  operational/costing identity, not the same thing as the accounting
  Fixed Asset record, so this stays a single clearable picker, never
  required).

  Code is system-assigned (M-00001...) and shown read-only on edit —
  never entered by the user, same pattern as Product's P-00001.

  @layout    AppLayout
  @route     accounting.machines.create / accounting.machines.edit
  @props     machine? (edit only), processes, overheadPools, fixedAssets
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.machineForm.machines'), href: route('accounting.machines.index') }, { label: isEdit ? t('common.edit') : t('accounting.manufacturing.machineForm.new') }]"
      :title="isEdit ? t('accounting.manufacturing.machineForm.editMachine') : t('accounting.manufacturing.machineForm.newMachine')"
      :subtitle="isEdit ? props.machine.code : t('accounting.manufacturing.machineForm.registerAPhysicalMachine')"
    />

    <form @submit.prevent="submit">

      <!-- ══════════════════════════════════════════════════════
           IDENTITY
      ══════════════════════════════════════════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.machineForm.identity') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.machineForm.whatThisMachineIs') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-2">
            <div class="field-group" v-if="isEdit">
              <label class="zv-label">{{ t('accounting.manufacturing.machineForm.code') }}</label>
              <div class="text-sm font-semibold" style="padding: 8px 0;">{{ props.machine.code }}</div>
            </div>
            <div class="field-group" :class="{ 'field-group-span-2': !isEdit }">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.machineForm.machineName') }}</label>
              <input v-model="form.name" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.name }"
                     :placeholder="t('accounting.manufacturing.machineForm.eGCuttingMachine2Automated')"/>
              <p v-if="form.errors.name" class="zv-field-error">{{ form.errors.name }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-2">
            <div class="field-group">
              <label class="zv-label zv-label-required mt-5">{{ t('accounting.manufacturing.machineForm.process') }}</label>
              <SelectBox v-model="form.process_id" :options="processes" value-key="id" label-key="name"
                         :placeholder="t('common.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.process_id }" />
              <p class="text-sm opacity-70 mt-1">{{ t('accounting.manufacturing.machineForm.whichCategoryOfWorkThisMachine') }}</p>
              <p v-if="form.errors.process_id" class="zv-field-error">{{ form.errors.process_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required mt-5">{{ t('common.status') }}</label>
              <div class="field-grid field-grid-3">
                <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.status === 'active' }" @click="form.status = 'active'">
                  <div class="zv-tile-label">{{ t('accounting.manufacturing.machineForm.active') }}</div>
                </button>
                <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.status === 'under_maintenance' }" @click="form.status = 'under_maintenance'">
                  <div class="zv-tile-label">{{ t('accounting.manufacturing.machineForm.underMaintenance') }}</div>
                </button>
                <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.status === 'retired' }" @click="form.status = 'retired'">
                  <div class="zv-tile-label">{{ t('accounting.manufacturing.machineForm.retired') }}</div>
                </button>
              </div>
              <p class="text-sm opacity-70 mt-1">{{ t('accounting.manufacturing.machineForm.underMaintenanceOrRetiredMachines') }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           OVERHEAD POOLS
      ══════════════════════════════════════════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#1490A8">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.machineForm.overheadPools') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.machineForm.whichSharedCostBucketsThisMachine') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <p class="text-sm opacity-70 mb-3">{{ t('accounting.manufacturing.machineForm.aMachineCanFeedSeveralPools') }}</p>

          <div v-if="overheadPools.length === 0" class="text-sm opacity-70">
            {{ t('accounting.manufacturing.machineForm.noOverheadPoolsYetCreateOne') }}
          </div>
          <div v-else class="field-grid field-grid-2-2-2-2-2-2">
            <button v-for="pool in overheadPools" :key="pool.id" type="button"
              class="zv-tile" :class="{ 'zv-tile-active': form.overhead_pool_ids.includes(pool.id) }"
              @click="togglePool(pool.id)">
              <div class="zv-tile-label">{{ pool.name }}</div>
            </button>
          </div>
          <p v-if="form.errors.overhead_pool_ids" class="zv-field-error mt-2">{{ form.errors.overhead_pool_ids }}</p>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           FIXED ASSET LINK (optional)
      ══════════════════════════════════════════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#B45309">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.machineForm.fixedAssetLink') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.machineForm.optionalLinkToTheAccountingBook') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <p class="text-sm opacity-70 mb-3">{{ t('accounting.manufacturing.machineForm.ifThisMachineWasBoughtThrough') }}</p>

          <div class="field-group" style="max-width: 420px;">
            <label class="zv-label">{{ t('accounting.manufacturing.machineForm.fixedAsset') }}</label>
            <SelectBox v-model="form.fixed_asset_id" :options="fixedAssetOptions" value-key="id" label-key="label"
                       :placeholder="t('accounting.manufacturing.machineForm.noneNotLinked')" :clearable="true"
                       :class="{ 'zv-input-error': form.errors.fixed_asset_id }" />
            <p v-if="fixedAssets.length === 0" class="text-sm opacity-70 mt-1">{{ t('accounting.manufacturing.machineForm.noUnlinkedFixedAssetsAvailable') }}</p>
            <p v-if="form.errors.fixed_asset_id" class="zv-field-error">{{ form.errors.fixed_asset_id }}</p>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           NOTES
      ══════════════════════════════════════════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('common.notes') }}</label>
            <textarea v-model="form.notes" rows="3" class="zv-textarea" :placeholder="t('accounting.manufacturing.machineForm.optionalInternalNotes')"></textarea>
            <p v-if="form.errors.notes" class="zv-field-error">{{ form.errors.notes }}</p>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.machines.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.manufacturing.machineForm.createMachine')) }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  machine:       { type: Object, default: null },
  processes:     { type: Array,  default: () => [] },
  overheadPools: { type: Array,  default: () => [] },
  fixedAssets:   { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.machine?.id)

// "FA-00042 — Cutting Machine (Automated)" style labels — SelectBox
// takes label-key, so build the combined string once here rather
// than a subKey (a Fixed Asset's own name already carries enough
// context that a two-line row isn't needed).
const fixedAssetOptions = computed(() =>
  props.fixedAssets.map(fa => ({ id: fa.id, label: `${fa.asset_code} — ${fa.name}` }))
)

const existingPoolIds = (props.machine?.machine_overhead_pools ?? []).map(l => l.overhead_pool_id)

const form = useForm({
  name:               props.machine?.name ?? '',
  process_id:         props.machine?.process_id ?? '',
  status:             props.machine?.status ?? 'active',
  overhead_pool_ids:  existingPoolIds,
  fixed_asset_id:     props.machine?.fixed_asset_id ?? '',
  notes:              props.machine?.notes ?? '',
})

function togglePool(id) {
  const idx = form.overhead_pool_ids.indexOf(id)
  if (idx === -1) form.overhead_pool_ids.push(id)
  else form.overhead_pool_ids.splice(idx, 1)
}

function submit() {
  isEdit.value
    ? form.put(route('accounting.machines.update', props.machine.id))
    : form.post(route('accounting.machines.store'))
}
</script>
