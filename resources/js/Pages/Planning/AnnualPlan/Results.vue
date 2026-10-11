<!--
══════════════════════════════════════════════════════════════════
  Planning/AnnualPlan/Results.vue

  Thin wrapper around the shared ResultsBody component (Decision 1 —
  one shared flow, two products). Annual Plan's results never include
  a Valuation section — ResultsBody only renders one when
  results.valuation is present, and PlanCalculationService only
  computes that for Business Plan — so nothing extra is needed here.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.results
  @props     plan: Object, results: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="t('planning.wizard.results') + ' — ' + plan.name" />
    <ResultsBody :plan="plan" :results="results" :wizard-steps="wizardSteps" route-prefix="planning.annual-plan" />
</template>

<script setup>
import { Head } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import ResultsBody from '@/Components/Planning/ResultsBody.vue'

const { t } = useI18n()
defineProps({ plan: Object, results: Object, wizardSteps: Object })
</script>
