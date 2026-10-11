<!--
══════════════════════════════════════════════════════════════════
  Planning/AnnualPlan/Cogs.vue

  Thin wrapper around the shared CogsBody component (Decision 1 —
  one shared flow, two products). All real UI lives in
  Components/Planning/CogsBody.vue.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.cogs
  @props     plan: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <CogsBody :plan="plan" :wizard-steps="wizardSteps" route-prefix="planning.annual-plan" />
</template>

<script setup>
import CogsBody from '@/Components/Planning/CogsBody.vue'

defineProps({ plan: Object, wizardSteps: Object })
</script>
