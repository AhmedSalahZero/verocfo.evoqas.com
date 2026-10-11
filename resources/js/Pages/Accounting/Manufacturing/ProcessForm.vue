<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/ProcessForm.vue

  Create and Edit share one page (Rule 2) — mode by props.process?.id.
  Deliberately simple — a Process is just a named category of work,
  nothing else to configure (see Process model header). Mirrors
  LaborCategoryForm.vue exactly.

  @layout    AppLayout
  @route     accounting.processes.create / accounting.processes.edit
  @props     process (edit only)
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.processForm.processes'), href: route('accounting.processes.index') }, { label: isEdit ? t('common.edit') : t('accounting.manufacturing.processForm.new') }]"
      :title="isEdit ? t('accounting.manufacturing.processForm.editProcess') : t('accounting.manufacturing.processForm.newProcess')"
      :subtitle="t('accounting.manufacturing.processForm.aCategoryOfWorkWeldingCutting')"
    />

    <form @submit.prevent="submit">
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.manufacturing.processForm.identity') }}</div>
            <div class="form-section-sub">{{ t('accounting.manufacturing.processForm.whatThisProcessRepresents') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-8-4">
            <div class="field-group" :class="{ 'field-group-span-2': !isEdit }">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.processForm.processName') }}</label>
              <input v-model="form.name" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.name }"
                     :placeholder="t('accounting.manufacturing.processForm.eGWeldingCuttingAssembly')"/>
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
          <Link :href="route('accounting.processes.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('accounting.manufacturing.processForm.createProcess')) }}
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
  process: { type: Object, default: null },
})

const isEdit = computed(() => !!props.process?.id)

const form = useForm({
  name: props.process?.name ?? '',
  is_active: props.process?.is_active ?? true,
})

function submit() {
  isEdit.value
    ? form.put(route('accounting.processes.update', props.process.id))
    : form.post(route('accounting.processes.store'))
}
</script>
