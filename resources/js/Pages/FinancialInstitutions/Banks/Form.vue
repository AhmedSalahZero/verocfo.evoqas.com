<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Form.vue

  Create + Edit for Bank master data.
  Mode: props.bank?.id → Edit, else → Create.

  @layout    AppLayout
  @route     fi.banks.create / fi.banks.edit
  @props     bank: Bank|null
══════════════════════════════════════════════════════════════════
-->
<template>
        <div class="fi-page">
            <div class="fi-breadcrumb">
                <Link :href="route('fi.banks.index')" class="fi-bread-link">{{ t('financialInstitutions.banks.form.banks') }}</Link>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="fi-bread-sep"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                <span class="fi-bread-current">{{ isEdit ? t('financialInstitutions.banks.form.editBank') : t('financialInstitutions.banks.form.newBank') }}</span>
            </div>

            <h1 class="fi-title">{{ isEdit ? t('financialInstitutions.banks.form.editBank') : t('financialInstitutions.banks.form.addBank') }}</h1>

            <form @submit.prevent="submit" class="fi-form">
                <div class="fi-card fi-form-card">
                    <div class="fi-grid-2">
                        <div class="fi-field">
                            <label class="fi-label fi-required">{{ t('financialInstitutions.banks.form.bankNameEnglish') }}</label>
                            <input v-model="form.name_en" type="text" class="fi-input" :class="{ 'fi-input-error': form.errors.name_en }" :placeholder="t('financialInstitutions.banks.form.eGNationalBankOfEgypt')"/>
                            <p v-if="form.errors.name_en" class="fi-error">{{ form.errors.name_en }}</p>
                        </div>
                        <div class="fi-field">
                            <label class="fi-label">{{ t('financialInstitutions.banks.form.bankNameArabic') }}</label>
                            <input v-model="form.name_ar" type="text" class="fi-input fi-rtl" :placeholder="t('financialInstitutions.banks.form.text')"/>
                        </div>
                    </div>
                    <div class="fi-grid-2">
                        <div class="fi-field">
                            <label class="fi-label">{{ t('financialInstitutions.banks.form.branchName') }}</label>
                            <input v-model="form.branch_name" type="text" class="fi-input" :placeholder="t('financialInstitutions.banks.form.eGMaadiBranch')"/>
                        </div>
                        <div class="fi-field">
                            <label class="fi-label">{{ t('financialInstitutions.banks.form.sWIFTCode') }}</label>
                            <input v-model="form.swift_code" type="text" class="fi-input fi-mono" placeholder="NBEAEGCX"/>
                        </div>
                    </div>
                    <div class="fi-field">
                        <label class="fi-label">{{ t('financialInstitutions.banks.form.branchAddress') }}</label>
                        <input v-model="form.branch_address" type="text" class="fi-input" :placeholder="t('financialInstitutions.banks.form.fullBranchAddress')"/>
                    </div>
                    <div class="fi-grid-3">
                        <div class="fi-field">
                            <label class="fi-label">{{ t('financialInstitutions.banks.form.contactPerson') }}</label>
                            <input v-model="form.contact_person" type="text" class="fi-input" :placeholder="t('financialInstitutions.banks.form.relationshipManagerName')"/>
                        </div>
                        <div class="fi-field">
                            <label class="fi-label">{{ t('financialInstitutions.banks.form.phone') }}</label>
                            <input v-model="form.phone" type="text" class="fi-input" placeholder="+20 2 1234 5678"/>
                        </div>
                        <div class="fi-field">
                            <label class="fi-label">{{ t('financialInstitutions.banks.form.email') }}</label>
                            <input v-model="form.email" type="email" class="fi-input" placeholder="rm@bank.com"/>
                        </div>
                    </div>
                    <div class="fi-field fi-toggle-row">
                        <label class="fi-toggle-label">{{ t('common.active') }}</label>
                        <button type="button" class="fi-toggle" :class="{ 'fi-toggle-on': form.is_active }" @click="form.is_active = !form.is_active">
                            <span class="fi-toggle-thumb"></span>
                        </button>
                    </div>
                </div>

                <div class="fi-actions">
                    <Link :href="route('fi.banks.index')" class="fi-btn-cancel">{{ t('common.cancel') }}</Link>
                    <button type="submit" class="fi-btn-save" :disabled="form.processing">
                        {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('financialInstitutions.banks.form.addBank')) }}
                    </button>
                </div>
            </form>
        </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
const props = defineProps({ bank: Object })
const isEdit = computed(() => !!props.bank?.id)
const form = useForm({
    name_en:        props.bank?.name_en        ?? '',
    name_ar:        props.bank?.name_ar        ?? '',
    branch_name:    props.bank?.branch_name    ?? '',
    branch_address: props.bank?.branch_address ?? '',
    contact_person: props.bank?.contact_person ?? '',
    phone:          props.bank?.phone          ?? '',
    email:          props.bank?.email          ?? '',
    swift_code:     props.bank?.swift_code     ?? '',
    is_active:      props.bank?.is_active      ?? true,
})
function submit() {
    isEdit.value ? form.put(route('fi.banks.update', props.bank.id)) : form.post(route('fi.banks.store'))
}
</script>

<style scoped>
.fi-page { padding: 28px 32px; max-width: 1200px; margin: 0 auto; }
.fi-breadcrumb { display: flex; align-items: center; gap: 6px; margin-bottom: 20px; }
.fi-bread-link { font-size: 13px; color: #1490A8; text-decoration: none; }
.fi-bread-sep { width: 14px; height: 14px; color: var(--vero-text-muted); }
.fi-bread-current { font-size: 13px; color: var(--vero-text-muted); }
.fi-title { font-size: 22px; font-weight: 700; color: var(--vero-text); margin: 0 0 24px; }
.fi-form { display: flex; flex-direction: column; gap: 16px; }
.fi-card { background: var(--vero-card); border: 1px solid var(--vero-border); border-radius: 12px; }
.fi-form-card { padding: 24px; }
.fi-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.fi-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.fi-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px; }
.fi-field:last-child { margin-bottom: 0; }
.fi-label { font-size: 12.5px; font-weight: 600; color: var(--vero-text); }
.fi-required::after { content: ' *'; color: #ef4444; }
.fi-input { padding: 9px 12px; border: 1px solid var(--vero-border); border-radius: 8px; font-size: 13px; background: var(--vero-bg); color: var(--vero-text); outline: none; transition: border-color 0.15s; width: 100%; }
.fi-input:focus { border-color: #1490A8; }
.fi-input-error { border-color: #ef4444 !important; }
.fi-rtl { direction: rtl; }
.fi-mono { font-family: monospace; text-transform: uppercase; }
.fi-error { font-size: 11.5px; color: #ef4444; }
.fi-toggle-row { flex-direction: row; align-items: center; gap: 12px; margin-top: 4px; }
.fi-toggle-label { font-size: 13px; font-weight: 500; }
.fi-toggle { width: 42px; height: 24px; border-radius: 12px; background: var(--vero-border); border: none; cursor: pointer; position: relative; transition: background 0.2s; padding: 0; }
.fi-toggle-on { background: #10b981; }
.fi-toggle-thumb { position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: left 0.2s; }
.fi-toggle-on .fi-toggle-thumb { left: 21px; }
.fi-actions { display: flex; justify-content: flex-end; gap: 10px; }
.fi-btn-cancel { padding: 9px 20px; border: 1px solid var(--vero-border); border-radius: 8px; background: transparent; color: var(--vero-text-muted); font-size: 13px; text-decoration: none; font-weight: 500; }
.fi-btn-save { padding: 9px 24px; background: #0F2554; color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.15s; }
.fi-btn-save:hover:not(:disabled) { background: #1a3a7a; }
.fi-btn-save:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
