<!--
══════════════════════════════════════════════════════════════════
  Settings/FixedAssetTypes/Form.vue

  Create and Edit Fixed Asset Types — one page (Rule 2: Create/Edit
  share a single Vue page, mode detected by props.fixedAssetType).

  Deliberately minimal — no usage_type tiles, no UOM, no barcode, no
  selling price. A Fixed Asset Type only needs: a name, which category
  account it posts to, and the defaults every individual asset created
  from it starts with (useful life, residual value). Category and UOM
  are auto-resolved behind the scenes (see ProductService::
  buildFixedAssetTypeData()) — the user never sees or picks them.

  @layout    AppLayout
  @route     settings.fixed-asset-types.create / settings.fixed-asset-types.edit
  @props     fixedAssetType: Product|null, fixedAssetAccounts: Array
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page" style="max-width:800px">

    <div class="zv-page-header">
      <div>
        <div class="zv-breadcrumb">
          <Link :href="route('settings.fixed-asset-types.index')" class="zv-text-blue">{{ t('settings.fixedAssetTypes.form.fixedAssetTypes') }}</Link>
          <span class="zv-breadcrumb-sep">/</span>
          <span>{{ isEdit ? t('settings.fixedAssetTypes.form.editFixedAssetType') : t('settings.fixedAssetTypes.form.newFixedAssetType') }}</span>
        </div>
        <h1 class="zv-page-title">{{ isEdit ? fixedAssetType.name_en : t('settings.fixedAssetTypes.form.newFixedAssetType') }}</h1>
      </div>
    </div>

    <form @submit.prevent="submit">
      <div class="zv-card mb-4">
        <div class="zv-card-header">
          <div class="form-section-icon me-3" style="background:#4338CA">
            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5m0 0H3m9-14h.01M9 11h.01M15 11h.01M9 15h.01M15 15h.01M9 7h.01M15 7h.01"/>
            </svg>
          </div>
          <h2 class="zv-card-title">{{ t('settings.fixedAssetTypes.form.fixedAssetTypeDetails') }}</h2>
        </div>
        <div class="zv-card-body">

          <div class="fat-grid mb-4">
            <div>
              <label class="zv-label zv-label-required">{{ t('settings.fixedAssetTypes.form.nameEN') }}</label>
              <input v-model="form.name_en" type="text" class="zv-input w-full" :placeholder="t('settings.fixedAssetTypes.form.eGCompanyVehicleLaptop')" />
              <p v-if="errors.name_en" class="zv-field-error">{{ errors.name_en }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('settings.fixedAssetTypes.form.nameAR') }}</label>
              <input v-model="form.name_ar" type="text" class="zv-input w-full" dir="rtl" />
            </div>
          </div>

          <div class="mb-4">
            <label class="zv-label">{{ t('settings.fixedAssetTypes.form.description') }}</label>
            <textarea v-model="form.description" class="zv-input w-full" rows="2"></textarea>
          </div>

          <div class="mb-1">
            <label class="zv-label zv-label-required">{{ t('settings.fixedAssetTypes.form.fixedAssetCategory') }}</label>
            <select v-model="form.fixed_asset_account_id" class="zv-select w-full">
              <option :value="null">{{ t('settings.fixedAssetTypes.form.selectCategory') }}</option>
              <option v-for="acc in fixedAssetAccounts" :key="acc.id" :value="acc.id">{{ accountOptionLabel(acc) }}</option>
            </select>
            <p v-if="errors.fixed_asset_account_id" class="zv-field-error">{{ errors.fixed_asset_account_id }}</p>
            <p class="field-hint">{{ t('settings.fixedAssetTypes.form.itsAccumulatedDepreciationCounterpart') }}</p>
          </div>

        </div>
      </div>

      <div class="zv-card mb-4">
        <div class="zv-card-header">
          <div class="form-section-icon me-3" style="background:var(--zv-nav-bg)">
            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <h2 class="zv-card-title">{{ t('settings.fixedAssetTypes.form.depreciationDefaults') }}</h2>
        </div>
        <div class="zv-card-body">
          <div class="fat-grid">
            <div>
              <label class="zv-label zv-label-required">{{ t('settings.fixedAssetTypes.form.defaultUsefulLifeMonths') }}</label>
              <input v-model="form.default_useful_life_months" type="number" min="1" class="zv-input w-full" />
              <p v-if="errors.default_useful_life_months" class="zv-field-error">{{ errors.default_useful_life_months }}</p>
            </div>
            <div>
              <label class="zv-label">{{ t('settings.fixedAssetTypes.form.defaultResidualValue') }}</label>
              <input v-model="form.default_residual_value" type="number" min="0" step="0.01" class="zv-input w-full" placeholder="0.00" />
            </div>
          </div>
          <p class="field-hint mt-2">{{ t('settings.fixedAssetTypes.form.copiedOntoEveryIndividualAsset') }}</p>
        </div>
      </div>

      <div class="flex justify-end gap-2">
        <Link :href="route('settings.fixed-asset-types.index')" class="zv-btn zv-btn-secondary">{{ t('common.cancel') }}</Link>
        <button type="submit" class="zv-btn zv-btn-primary" :disabled="form.processing">
          {{ form.processing ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>

  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, useForm } from '@inertiajs/vue3'

const { t } = useI18n()

const props = defineProps({
  fixedAssetType:     Object,
  fixedAssetAccounts: { type: Array, default: () => [] },
})

const isEdit = computed(() => !!props.fixedAssetType?.id)
const errors = computed(() => form.errors)

const form = useForm({
  name_en:                    props.fixedAssetType?.name_en ?? '',
  name_ar:                    props.fixedAssetType?.name_ar ?? '',
  description:                props.fixedAssetType?.description ?? '',
  fixed_asset_account_id:     props.fixedAssetType?.fixed_asset_account_id ?? null,
  default_useful_life_months: props.fixedAssetType?.default_useful_life_months ?? '',
  default_residual_value:     props.fixedAssetType?.default_residual_value ?? '',
})

function submit() {
  if (isEdit.value) {
    form.put(route('settings.fixed-asset-types.update', props.fixedAssetType.id))
  } else {
    form.post(route('settings.fixed-asset-types.store'))
  }
}

// Visually nests sub-accounts under their parent category — the
// query behind fixedAssetAccounts already returns every postable
// account under 111xxx (parent AND any sub-accounts, e.g. a company-
// created "111004001 — Personal Cars" under "111004 Vehicles"), but
// a flat list made a sub-account look like a seventh peer category
// instead of a narrower option within Vehicles. Indent by nesting
// depth, same "↳" convention already used for Product Category
// sub-categories elsewhere in Settings.
function accountOptionLabel(acc) {
  // Every parent code in this family is 6 digits (111001-111006);
  // anything longer is a sub-account, one level of nesting per
  // extra group of 3 digits.
  const depth = Math.max(0, Math.floor((acc.code.length - 6) / 3))
  const indent = depth > 0 ? '\u00A0\u00A0'.repeat(depth) + '↳ ' : ''
  return `${indent}${acc.code} — ${acc.name_en}`
}
</script>

<style>
.fat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
@media (max-width: 700px) { .fat-grid { grid-template-columns: 1fr; } }
</style>