<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Leasing/CompanyForm.vue
  Thin wrapper that passes leasing-specific routes to the shared form.
  @layout AppLayout | @route fi.leasing-companies.create / fi.leasing-companies.edit
  @props  company: LeasingCompany|null
══════════════════════════════════════════════════════════════════
-->
<template>
    <InstitutionCompanyForm
        :company="company"
        back-route="fi.leasing-companies.index"
        store-route="fi.leasing-companies.store"
        update-route="fi.leasing-companies.update"
        :entity-label="t('financialInstitutions.leasing.companyForm.leasingCompany')"
    />
</template>
<script setup>
import InstitutionCompanyForm from '@/Pages/FinancialInstitutions/Shared/InstitutionCompanyForm.vue'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
defineProps({ company: Object })
</script>
