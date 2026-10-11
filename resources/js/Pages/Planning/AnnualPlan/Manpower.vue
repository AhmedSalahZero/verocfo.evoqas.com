<!--
══════════════════════════════════════════════════════════════════
  Planning/AnnualPlan/Manpower.vue

  Thin wrapper around the shared ManpowerBody component (Decision 1 —
  one shared flow, two products). All real UI lives in
  Components/Planning/ManpowerBody.vue.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.manpower
  @props     plan: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <ManpowerBody :plan="plan" :wizard-steps="wizardSteps" route-prefix="planning.annual-plan" />
</template>

<script setup>
import ManpowerBody from '@/Components/Planning/ManpowerBody.vue'

defineProps({ plan: Object, wizardSteps: Object })
</script>
