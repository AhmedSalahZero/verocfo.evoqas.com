<!--
══════════════════════════════════════════════════════════════════
  Planning/AnnualPlan/OpeningBalance.vue

  Thin wrapper around the shared OpeningBalanceBody component
  (Decision 1 — one shared flow, two products). All real UI lives in
  Components/Planning/OpeningBalanceBody.vue.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.opening-balance
  @props     plan: Object, figures: Object, report: Object,
             wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <OpeningBalanceBody :plan="plan" :figures="figures" :report="report" :wizard-steps="wizardSteps" route-prefix="planning.annual-plan" />
</template>

<script setup>
import OpeningBalanceBody from '@/Components/Planning/OpeningBalanceBody.vue'

defineProps({ plan: Object, figures: Object, report: Object, wizardSteps: Object })
</script>
