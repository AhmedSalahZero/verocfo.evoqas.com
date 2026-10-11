<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/LaborCategoryForm.vue

  Create and Edit share one page (Rule 2) — mode by props.category?.id.
  Deliberately simple — no tile-picker section like OverheadPoolForm's
  allocation base, since a labor category has no equivalent choice to
  make (see LaborCategory model header).

  @layout    AppLayout
  @route     accounting.labor-categories.create / accounting.labor-categories.edit
  @props     category (edit only)
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.laborCategoryForm.laborCategories'), href: route('accounting.labor-categories.index') }, { label: isEdit ? t('common.edit') : t('accounting.manufacturing.laborCategoryForm.new') }]"
      :title="isEdit ? t('accounting.manufacturing.laborCategoryForm.editLaborCategory') : t('accounting.manufacturing.laborCategoryForm.newLaborCategory')"
      :subtitle="t('accounting.manufacturing.laborCategoryForm.aNamedTypeOfLaborSkilledOperator')"
    />

    <form @submit.prevent="submit">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.laborCategoryForm.identity') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.laborCategoryForm.whatThisCategoryRepresents') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-8-4">
            <div class="field-group" :class="{ 'field-group-span-2': !isEdit }">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.laborCategoryForm.categoryName') }}</label>
              <input v-model="form.name" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.name }"
                     :placeholder="t('accounting.manufacturing.laborCategoryForm.eGSkilledOperatorUnskilledPacker')"/>
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

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.labor-categories.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.manufacturing.laborCategoryForm.createCategory')) }}
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
  category: { type: Object, default: null },
})

const isEdit = computed(() => !!props.category?.id)

const form = useForm({
  name: props.category?.name ?? '',
  is_active: props.category?.is_active ?? true,
})

function submit() {
  isEdit.value
    ? form.put(route('accounting.labor-categories.update', props.category.id))
    : form.post(route('accounting.labor-categories.store'))
}
</script>
