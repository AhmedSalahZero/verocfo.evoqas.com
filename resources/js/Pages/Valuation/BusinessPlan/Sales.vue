<!--
══════════════════════════════════════════════════════════════════
  Valuation/BusinessPlan/Sales.vue

  Thin wrapper around the shared SalesBody component (Decision 1 —
  one shared flow, two products). All real UI lives in
  Components/Planning/SalesBody.vue.

  @layout    AppLayout (applied automatically)
  @route     valuation.business-plan.sales
  @props     plan: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <SalesBody :plan="plan" :wizard-steps="wizardSteps" route-prefix="valuation.business-plan" />
</template>

<script setup>
import SalesBody from '@/Components/Planning/SalesBody.vue'

defineProps({ plan: Object, wizardSteps: Object })
</script>
