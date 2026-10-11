<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Factoring/CompanyForm.vue
  Thin wrapper for the shared InstitutionCompanyForm with factoring routes.
  @layout AppLayout | @route fi.factoring-companies.create / fi.factoring-companies.edit
  @props  company: FactoringCompany|null
══════════════════════════════════════════════════════════════════
-->
<template>
    <InstitutionCompanyForm
        :company="company"
        back-route="fi.factoring-companies.index"
        store-route="fi.factoring-companies.store"
        update-route="fi.factoring-companies.update"
        :entity-label="t('financialInstitutions.factoring.companyForm.factoringCompany')"
    />
</template>
<script setup>
import InstitutionCompanyForm from '@/Pages/FinancialInstitutions/Shared/InstitutionCompanyForm.vue'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
defineProps({ company: Object })
</script>
