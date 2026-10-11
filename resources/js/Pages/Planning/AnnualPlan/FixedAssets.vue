<!--
══════════════════════════════════════════════════════════════════
  Planning/AnnualPlan/FixedAssets.vue

  Thin wrapper around the shared FixedAssetsBody component
  (Decision 1 — one shared flow, two products). All real UI lives in
  Components/Planning/FixedAssetsBody.vue.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.fixed-assets
  @props     plan: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <FixedAssetsBody :plan="plan" :wizard-steps="wizardSteps" route-prefix="planning.annual-plan" />
</template>

<script setup>
import FixedAssetsBody from '@/Components/Planning/FixedAssetsBody.vue'

defineProps({ plan: Object, wizardSteps: Object })
</script>
