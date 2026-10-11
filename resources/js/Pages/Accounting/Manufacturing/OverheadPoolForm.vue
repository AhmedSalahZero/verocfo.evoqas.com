<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/OverheadPoolForm.vue

  Create and Edit share one page (Rule 2) — mode detected by
  props.pool?.id. Design matches the ZAVERO premium standard used by
  Settings/Partners/Form.vue: colored, iconed choice-tiles rather than
  a plain dropdown for the one decision that actually shapes how this
  pool behaves — its allocation base.

  Each allocation base gets its own accent color and icon, carried
  through consistently: this is the same color a reader will later
  see on this pool's entries in ProductionOrderShow's cost log, so
  the association becomes visual, not just textual.

  @layout    AppLayout
  @route     accounting.overhead-pools.create / accounting.overhead-pools.edit
  @props     pool (edit only)
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.overheadPoolForm.overheadPools'), href: route('accounting.overhead-pools.index') }, { label: isEdit ? t('common.edit') : t('accounting.manufacturing.overheadPoolForm.new') }]"
      :title="isEdit ? t('accounting.manufacturing.overheadPoolForm.editOverheadPool') : t('accounting.manufacturing.overheadPoolForm.newOverheadPool')"
      :subtitle="t('accounting.manufacturing.overheadPoolForm.aCostBucketRentDepreciationUtilities')"
    />

    <form @submit.prevent="submit">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h12a2 2 0 012 2v2H4V6zm0 5h16v2H4v-2zm0 5h16v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.overheadPoolForm.identity') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.overheadPoolForm.whatThisPoolRepresents') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-8-4">
            <div class="field-group" :class="{ 'field-group-span-2': !isEdit }">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.overheadPoolForm.poolName') }}</label>
              <input v-model="form.name" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.name }"
                     :placeholder="t('accounting.manufacturing.overheadPoolForm.eGFactoryRentMachineDepreciation')"/>
              <p v-if="form.errors.name" class="zv-field-error">{{ form.errors.name }}</p>
            </div>

            <div class="field-group" v-if="isEdit">
              <label class="zv-label">{{ t('common.status') }}</label>
              <div class="zv-toggle-row">
                <button type="button" class="zv-toggle" :class="{ 'zv-toggle-on': form.is_active }" @click="form.is_active = !form.is_active">
                  <span class="zv-toggle-knob"></span>
                </button>
                <span>{{ form.is_active ? t('common.active') : t('common.inactive') }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#7C3AED">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v18M15 3v18M3 9h18M3 15h18"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.overheadPoolForm.allocationBase') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.overheadPoolForm.howThisPoolSCostIsSpread') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="pool-tile-grid">
            <button type="button" class="pool-tile pool-tile--labor" :class="{ 'pool-tile--selected': form.allocation_base === 'labor_hours' }"
                    @click="form.allocation_base = 'labor_hours'">
              <div class="pool-tile-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
              </div>
              <div class="pool-tile-label">{{ t('accounting.manufacturing.overheadPoolForm.laborHours') }}</div>
              <div class="pool-tile-desc">{{ t('accounting.manufacturing.overheadPoolForm.spreadByHowManyLaborHours') }}</div>
            </button>

            <button type="button" class="pool-tile pool-tile--machine" :class="{ 'pool-tile--selected': form.allocation_base === 'machine_hours' }"
                    @click="form.allocation_base = 'machine_hours'">
              <div class="pool-tile-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                  <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </div>
              <div class="pool-tile-label">{{ t('accounting.manufacturing.overheadPoolForm.machineHours') }}</div>
              <div class="pool-tile-desc">{{ t('accounting.manufacturing.overheadPoolForm.spreadByMachineRunTimeDepreciation') }}</div>
            </button>

            <button type="button" class="pool-tile pool-tile--units" :class="{ 'pool-tile--selected': form.allocation_base === 'production_units' }"
                    @click="form.allocation_base = 'production_units'">
              <div class="pool-tile-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
              </div>
              <div class="pool-tile-label">{{ t('accounting.manufacturing.overheadPoolForm.productionUnits') }}</div>
              <div class="pool-tile-desc">{{ t('accounting.manufacturing.overheadPoolForm.spreadEvenlyPerUnitProducedRegardless') }}</div>
            </button>
          </div>
          <p v-if="form.errors.allocation_base" class="zv-field-error mt-3">{{ form.errors.allocation_base }}</p>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.overhead-pools.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.manufacturing.overheadPoolForm.createPool')) }}
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

const { t } = useI18n()

const props = defineProps({
  pool: { type: Object, default: null },
})

const isEdit = computed(() => !!props.pool?.id)

const form = useForm({
  name: props.pool?.name ?? '',
  allocation_base: props.pool?.allocation_base ?? 'labor_hours',
  is_active: props.pool?.is_active ?? true,
})

function submit() {
  isEdit.value
    ? form.put(route('accounting.overhead-pools.update', props.pool.id))
    : form.post(route('accounting.overhead-pools.store'))
}
</script>

<style>
.pool-tile-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
@media (max-width: 900px) { .pool-tile-grid { grid-template-columns: 1fr; } }

.pool-tile {
  text-align: start; padding: 1.1rem; border-radius: var(--zv-radius-md, 10px);
  border: 1.5px solid var(--zv-border, #e2e8f0); background: var(--zv-bg-card, #fff);
  cursor: pointer; transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
}
.pool-tile:hover { transform: translateY(-1px); box-shadow: var(--zv-shadow-sm); }
.pool-tile-icon { width: 2.25rem; height: 2.25rem; border-radius: var(--zv-radius, 8px); display: flex; align-items: center; justify-content: center; margin-bottom: 0.6rem; background: var(--zv-subtle-bg, #f1f5f9); color: var(--zv-text-muted, #94a3b8); }
.pool-tile-label { font-weight: 700; font-size: 0.9rem; margin-bottom: 0.25rem; color: var(--zv-text-primary, #0f172a); }
.pool-tile-desc { font-size: 0.78rem; color: var(--zv-text-muted, #94a3b8); line-height: 1.4; }

.pool-tile--labor.pool-tile--selected   { border-color: #2563EB; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
.pool-tile--labor.pool-tile--selected   .pool-tile-icon { background: #2563EB; color: #fff; }
.pool-tile--machine.pool-tile--selected { border-color: #D97706; box-shadow: 0 0 0 3px rgba(217,119,6,0.12); }
.pool-tile--machine.pool-tile--selected .pool-tile-icon { background: #D97706; color: #fff; }
.pool-tile--units.pool-tile--selected   { border-color: #059669; box-shadow: 0 0 0 3px rgba(5,150,105,0.12); }
.pool-tile--units.pool-tile--selected   .pool-tile-icon { background: #059669; color: #fff; }
</style>
