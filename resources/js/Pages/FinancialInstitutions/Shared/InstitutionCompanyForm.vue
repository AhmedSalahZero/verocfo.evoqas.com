<!--
══════════════════════════════════════════════════════════════════
  Shared: Institution Company Form

  Used for both:
    - FinancialInstitutions/Leasing/CompanyForm.vue
    - FinancialInstitutions/Factoring/CompanyForm.vue

  Only fields differ in the back button route and page title.
  Each page imports this template directly — it is self-contained.

  Redesigned to match the ZAVERO premium form standard (same pattern
  as Projects/Form.vue, Projects/SalesOrders/Form.vue, and
  FinancialInstitutions/.../CleanOverdraftForm.vue):
    - zv-page-header with breadcrumb
    - form-section card with icon header
    - field-grid / field-group layout
    - zv-toggle-row for the Active switch (now a global class)
    - zv-input / zv-label / zv-btn base classes (global, from app.css)

  @layout    AppLayout
  @props     company: Object|null, backRoute: String, storeRoute: String, updateRoute: String, entityLabel: String
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="zv-page">

            <!-- ══════════════════════════════════════════════════════
                 PAGE HEADER
            ══════════════════════════════════════════════════════ -->
            <div class="zv-page-header">
                <div>
                    <div class="zv-breadcrumb">
                        <Link :href="route(backRoute)">{{ entityLabel }}</Link>
                        <span class="zv-breadcrumb-sep">/</span>
                        <span>{{ isEdit ? t('common.edit') : t('financialInstitutions.shared.institutionCompanyForm.new') }}</span>
                    </div>
                    <h1 class="zv-page-title">
                        {{ isEdit ? t('common.edit') + ' ' + entityLabel : t('financialInstitutions.shared.institutionCompanyForm.add') + ' ' + entityLabel }}
                    </h1>
                </div>
            </div>

            <!-- Error banner -->
            <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                <span>{{ t('financialInstitutions.shared.institutionCompanyForm.pleaseFixTheErrorsBelowBefore') }}</span>
            </div>

            <form @submit.prevent="submit" novalidate>

                <!-- ══════════════════════════════════════════════════
                     SECTION 1 — COMPANY DETAILS
                ══════════════════════════════════════════════════ -->
                <div class="form-section mb-5">
                    <div class="form-section-header">
                        <div class="form-section-icon" style="background:#0F2554">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="form-section-title">{{ t('financialInstitutions.shared.institutionCompanyForm.companyDetails') }}</div>
                            <div class="form-section-sub">{{ t('financialInstitutions.shared.institutionCompanyForm.nameContactInformationAndStatus') }}</div>
                        </div>
                    </div>

                    <div class="form-section-body">
                        <div class="field-grid field-grid-2 mb-5">
                            <div class="field-group">
                                <label class="zv-label zv-label-required">{{ t('financialInstitutions.shared.institutionCompanyForm.nameEnglish') }}</label>
                                <input v-model="form.name_en" type="text" class="zv-input"
                                       :class="{ 'zv-input-error': form.errors.name_en }"
                                       :placeholder="t('financialInstitutions.shared.institutionCompanyForm.companyNameInEnglish')"/>
                                <p v-if="form.errors.name_en" class="zv-field-error">{{ form.errors.name_en }}</p>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.shared.institutionCompanyForm.nameArabic') }}</label>
                                <input v-model="form.name_ar" type="text" class="zv-input zv-input-ar"
                                       :placeholder="t('financialInstitutions.shared.institutionCompanyForm.text')"/>
                            </div>
                        </div>

                        <div class="field-grid field-grid-3 mb-5">
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.shared.institutionCompanyForm.contactPerson') }}</label>
                                <input v-model="form.contact_person" type="text" class="zv-input"
                                       :placeholder="t('financialInstitutions.shared.institutionCompanyForm.accountManagerName')"/>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.shared.institutionCompanyForm.phone') }}</label>
                                <input v-model="form.phone" type="text" class="zv-input" placeholder="+20 2 1234 5678"/>
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('financialInstitutions.shared.institutionCompanyForm.email') }}</label>
                                <input v-model="form.email" type="email" class="zv-input" placeholder="manager@company.com"/>
                            </div>
                        </div>

                        <div class="zv-toggle-row">
                            <button type="button" class="zv-toggle" :class="{ 'zv-toggle-on': form.is_active }"
                                    @click="form.is_active = !form.is_active">
                                <span class="zv-toggle-knob"></span>
                            </button>
                            <span class="zv-toggle-row-label">{{ t('common.active') }}</span>
                        </div>
                    </div>
                </div>

                <!-- Info callout -->
                <div class="zv-alert zv-alert-info mb-5">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="text-sm">{{ t('financialInstitutions.shared.institutionCompanyForm.interestRatesAndContractTermsAre') }}</span>
                </div>

                <!-- ══════════════════════════════════════════════════
                     FORM ACTIONS
                ══════════════════════════════════════════════════ -->
                <div class="zv-card">
                    <div class="zv-card-footer flex items-center justify-end gap-3">
                        <Link :href="route(backRoute)" class="zv-btn zv-btn-ghost">
                            {{ t('common.cancel') }}
                        </Link>
                        <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
                            <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
                            {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('financialInstitutions.shared.institutionCompanyForm.add') + ' ' + entityLabel) }}
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

const { t } = useI18n()

const props = defineProps({
    company:     Object,
    backRoute:   String,
    storeRoute:  String,
    updateRoute: String,
    entityLabel: { type: String, default: 'Company' },
})

const isEdit = computed(() => !!props.company?.id)

const form = useForm({
    name_en:        props.company?.name_en        ?? '',
    name_ar:        props.company?.name_ar        ?? '',
    contact_person: props.company?.contact_person ?? '',
    phone:          props.company?.phone          ?? '',
    email:          props.company?.email          ?? '',
    is_active:      props.company?.is_active      ?? true,
})

function submit() {
    isEdit.value
        ? form.put(route(props.updateRoute, props.company.id))
        : form.post(route(props.storeRoute))
}
</script>