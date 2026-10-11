<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/StandardRateForm.vue

  Create and Edit share one page (Rule 2) — mode by props.rate?.id.

  Standard Rate Draft/Active/Superseded fix (VeroCFO_Manufacturing_
  Flexibility_Design_v1_0.md, Section 2): a Draft version is fully
  editable; once Active or Superseded, every field is locked
  (isLocked below) and the Save button is replaced with "Create New
  Version" — StandardRateService::update() rejects a save on a
  non-Draft version server-side too, this is just the same rule shown
  before the user ever tries. The identity fields (fiscal year, rate
  type, overhead pool / labor category) are always shown read-only in
  edit mode — they never change across versions, mirroring BomForm's
  product_id.

  Labor Categories (added Sept 2026 — VeroCFO_Manufacturing_Costing_
  Redesign_v1_0.md, Section 3) add a third rate-type tile alongside
  Labor and Overhead Pool — mutually exclusive with Overhead Pool,
  same shape.

  Monthly overrides are deliberately NOT on this page — they're a
  rarer, per-record action best reached from the rate's own row on
  the Standard Rates list, not cluttering this flow.

  @layout    AppLayout
  @route     accounting.standard-rates.create / accounting.standard-rates.edit
  @props     rate (edit only), fiscalYears, overheadPools, laborCategories
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.standardRateForm.standardRates'), href: route('accounting.standard-rates.index') }, { label: isEdit ? t('common.edit') : t('accounting.manufacturing.standardRateForm.new') }]"
      :title="isEdit ? t('accounting.manufacturing.standardRateForm.editStandardRate') : t('accounting.manufacturing.standardRateForm.setStandardRate')"
    >
      <template #subtitle>
        <template v-if="isEdit">
          <span class="sr-version-chip">{{ t('accounting.manufacturing.standardRateForm.version') }} {{ rate.version }}</span>
          <StatusBadge
            :status="rate.status"
            :overrides="{ superseded: { label: t('common.superseded'), badge: 'zv-badge-gray', dot: 'zv-status-dot-gray' } }"
            class="ms-2"
          />
        </template>
        <template v-else>{{ t('accounting.manufacturing.standardRateForm.theAnnualRateProductionOrdersUse') }}</template>
      </template>
    </PageHeader>

    <p v-if="isLocked" class="zv-banner zv-banner-info mb-4">
      {{ t('accounting.manufacturing.standardRateForm.thisVersionIsLockedBanner', { status: t('common.' + rate.status) }) }}
    </p>

    <form @submit.prevent="submit">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#7C3AED">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v18M15 3v18M3 9h18M3 15h18"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.standardRateForm.whatThisRateAppliesTo') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.standardRateForm.laborTimeOrOneSpecificOverhead') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div v-if="isEdit" class="zv-readonly-field sr-applies-to-readonly">
            {{ appliesToLabel(rate) }}
            — {{ rate.fiscal_year?.name }}
          </div>

          <div v-else class="rate-tile-grid mb-5">
            <button type="button" class="rate-tile rate-tile--labor" :class="{ 'rate-tile--selected': form.rate_type === 'labor' }"
                    @click="form.rate_type = 'labor'; form.overhead_pool_id = ''; form.labor_category_id = ''">
              <div class="rate-tile-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
              </div>
              <div>
                <div class="rate-tile-label">{{ t('accounting.manufacturing.standardRateForm.labor') }}</div>
                <div class="rate-tile-desc">{{ t('accounting.manufacturing.standardRateForm.standardCostPerLaborHour') }}</div>
              </div>
            </button>

            <button type="button" class="rate-tile rate-tile--overhead" :class="{ 'rate-tile--selected': form.rate_type === 'overhead_pool' }"
                    @click="form.rate_type = 'overhead_pool'; form.labor_category_id = ''">
              <div class="rate-tile-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h12a2 2 0 012 2v2H4V6zm0 5h16v2H4v-2zm0 5h16v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2z"/>
                </svg>
              </div>
              <div>
                <div class="rate-tile-label">{{ t('accounting.manufacturing.standardRateForm.overheadPool') }}</div>
                <div class="rate-tile-desc">{{ t('accounting.manufacturing.standardRateForm.standardRatePerAllocationBaseUnit') }}</div>
              </div>
            </button>

            <button type="button" class="rate-tile rate-tile--category" :class="{ 'rate-tile--selected': form.rate_type === 'labor_category' }"
                    @click="form.rate_type = 'labor_category'; form.overhead_pool_id = ''">
              <div class="rate-tile-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
              </div>
              <div>
                <div class="rate-tile-label">{{ t('accounting.manufacturing.standardRateForm.laborCategory') }}</div>
                <div class="rate-tile-desc">{{ t('accounting.manufacturing.standardRateForm.standardRateForOneNamedLaborCategory') }}</div>
              </div>
            </button>
          </div>

          <div class="field-grid field-grid-6-3-3" v-if="!isEdit">
            <div class="field-group" v-if="form.rate_type === 'overhead_pool'">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.standardRateForm.overheadPool') }}</label>
              <SelectBox v-model="form.overhead_pool_id" :options="overheadPools" value-key="id" :label-fn="p => `${p.name} (${baseLabel(p.allocation_base)})`" :placeholder="t('accounting.manufacturing.standardRateForm.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.overhead_pool_id }" />
              <p v-if="form.errors.overhead_pool_id" class="zv-field-error">{{ form.errors.overhead_pool_id }}</p>
            </div>

            <div class="field-group" v-if="form.rate_type === 'labor_category'">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.standardRateForm.laborCategory') }}</label>
              <SelectBox v-model="form.labor_category_id" :options="laborCategories" value-key="id" label-key="name" :placeholder="t('accounting.manufacturing.standardRateForm.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.labor_category_id }" />
              <p v-if="form.errors.labor_category_id" class="zv-field-error">{{ form.errors.labor_category_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.standardRateForm.fiscalYear') }}</label>
              <SelectBox v-model="form.fiscal_year_id" :options="fiscalYears" value-key="id" :label-fn="fy => fy.name" :placeholder="t('accounting.manufacturing.standardRateForm.select')" :clearable="false" :class="{ 'zv-input-error': form.errors.fiscal_year_id }" />
              <p v-if="form.errors.fiscal_year_id" class="zv-field-error">{{ form.errors.fiscal_year_id }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-2 mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">
                {{ annualRateLabel }}
              </label>
              <div class="zv-input-group">
                <span class="zv-input-prefix text-xs font-semibold">EGP</span>
                <input v-model="form.annual_rate" type="number" step="0.0001" min="0" :disabled="isLocked"
                       class="zv-input zv-input-has-prefix text-end" :class="{ 'zv-input-error': form.errors.annual_rate }"/>
              </div>
              <p v-if="form.errors.annual_rate" class="zv-field-error">{{ form.errors.annual_rate }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.standardRateForm.effectiveFrom') }}</label>
              <input v-model="form.effective_from" type="date" class="zv-input" :disabled="isLocked" :class="{ 'zv-input-error': form.errors.effective_from }"/>
              <p v-if="form.errors.effective_from" class="zv-field-error">{{ form.errors.effective_from }}</p>
              <p v-if="dateAfterYearEnd" class="zv-field-error">This date is after the end of the chosen fiscal year — the rate would never apply.</p>
              <p class="field-hint">{{ t('accounting.manufacturing.standardRateForm.effectiveFromHint') }}</p>
            </div>
          </div>

          <div class="field-group mt-4">
            <label class="zv-label">{{ t('accounting.manufacturing.standardRateForm.notes') }}</label>
            <textarea v-model="form.notes" rows="2" class="zv-input" :disabled="isLocked" :placeholder="t('accounting.manufacturing.standardRateForm.optionalBasisForThisRateSource')"></textarea>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.standard-rates.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <Link v-if="isLocked" :href="route('accounting.standard-rates.create')" class="zv-btn zv-btn-primary">
            {{ t('accounting.manufacturing.standardRateForm.createNewVersion') }}
          </Link>
          <button v-else type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.manufacturing.standardRateForm.saveRate')) }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'
import StatusBadge from '@/Components/StatusBadge.vue'

const { t } = useI18n()

const props = defineProps({
  rate:            { type: Object, default: null },
  fiscalYears:     { type: Array,  default: () => [] },
  overheadPools:   { type: Array,  default: () => [] },
  laborCategories: { type: Array,  default: () => [] },
})

const isEdit = computed(() => !!props.rate?.id)

// Only a Draft version can be saved — Active/Superseded are locked
// read-only here, mirroring StandardRateService::assertEditable().
const isLocked = computed(() => isEdit.value && props.rate?.status !== 'draft')

const form = useForm({
  rate_type: props.rate?.rate_type ?? 'labor',
  overhead_pool_id: props.rate?.overhead_pool_id ?? '',
  labor_category_id: props.rate?.labor_category_id ?? '',
  fiscal_year_id: props.rate?.fiscal_year_id ?? '',
  annual_rate: props.rate?.annual_rate ?? '',
  effective_from: props.rate?.effective_from?.toString().slice(0, 10) ?? todayLocal(),
  notes: props.rate?.notes ?? '',
})

// Section 3 (Labor Categories) — a third rate_type alongside the
// existing blanket 'labor' and 'overhead_pool'. Read-only display and
// the "Annual Rate per..." label both need to know which of the three
// applies, in both create (form.rate_type) and edit (rate.rate_type)
// mode.
function appliesToLabel(rate) {
  if (rate.rate_type === 'labor') return t('accounting.manufacturing.standardRateForm.labor')
  if (rate.rate_type === 'labor_category') return rate.labor_category?.name ?? t('accounting.manufacturing.standardRateForm.laborCategory')
  return rate.overhead_pool?.name ?? t('accounting.manufacturing.standardRateForm.overheadPool')
}

// props.rate isn't reactive-unwrapped the same way a ref is — alias
// it so the computed below reads cleanly.
const rate = computed(() => props.rate)

// Warns before saving: a rate that starts after its fiscal year has ended never applies.
const dateAfterYearEnd = computed(() => {
  const fy = props.fiscalYears.find(y => y.id === form.fiscal_year_id)
  const end = fy?.end_date?.toString().slice(0, 10)
  return !!(end && form.effective_from && form.effective_from > end)
})

const annualRateLabel = computed(() => {
  const type = isEdit.value ? rate.value?.rate_type : form.rate_type
  if (type === 'labor' || type === 'labor_category') return t('accounting.manufacturing.standardRateForm.annualRatePerHour')
  return t('accounting.manufacturing.standardRateForm.annualRatePerAllocationUnit')
})

function baseLabel(base) {
  return { labor_hours: 'Labor Hrs', machine_hours: 'Machine Hrs', production_units: 'Units' }[base] ?? base
}

function submit() {
  if (isLocked.value) return
  isEdit.value
    ? form.put(route('accounting.standard-rates.update', props.rate.id))
    : form.post(route('accounting.standard-rates.store'))
}
</script>

<style>
.rate-tile-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
@media (max-width: 900px) { .rate-tile-grid { grid-template-columns: 1fr; } }

.rate-tile {
  display: flex; align-items: center; gap: 0.85rem; text-align: start; padding: 1rem;
  border-radius: var(--zv-radius-md, 10px); border: 1.5px solid var(--zv-border, #e2e8f0);
  background: var(--zv-bg-card, #fff); cursor: pointer; transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.rate-tile:hover { box-shadow: var(--zv-shadow-sm); }
.rate-tile-icon { width: 2.5rem; height: 2.5rem; flex-shrink: 0; border-radius: var(--zv-radius, 8px); display: flex; align-items: center; justify-content: center; background: var(--zv-subtle-bg, #f1f5f9); color: var(--zv-text-muted, #94a3b8); }
.rate-tile-label { font-weight: 700; font-size: 0.9rem; color: var(--zv-text-primary, #0f172a); }
.rate-tile-desc { font-size: 0.78rem; color: var(--zv-text-muted, #94a3b8); }

.rate-tile--labor.rate-tile--selected { border-color: #2563EB; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
.rate-tile--labor.rate-tile--selected .rate-tile-icon { background: #2563EB; color: #fff; }
.rate-tile--overhead.rate-tile--selected { border-color: #7C3AED; box-shadow: 0 0 0 3px rgba(124,58,237,0.12); }
.rate-tile--overhead.rate-tile--selected .rate-tile-icon { background: #7C3AED; color: #fff; }
.rate-tile--category.rate-tile--selected { border-color: #059669; box-shadow: 0 0 0 3px rgba(5,150,105,0.12); }
.rate-tile--category.rate-tile--selected .rate-tile-icon { background: #059669; color: #fff; }

.sr-version-chip { display: inline-flex; align-items: center; font-size: 0.8rem; font-weight: 700; color: var(--zv-text-secondary, #475569); background: var(--zv-subtle-bg, #f1f5f9); border-radius: 999px; padding: 0.15rem 0.7rem; }
.sr-applies-to-readonly { margin-bottom: 1.25rem; font-weight: 600; }
.zv-banner { padding: 0.65rem 1rem; border-radius: var(--zv-radius, 8px); font-size: 0.875rem; }
.zv-banner-info { background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE; }
</style>
