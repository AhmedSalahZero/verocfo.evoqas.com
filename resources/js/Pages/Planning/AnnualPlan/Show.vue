<!--
══════════════════════════════════════════════════════════════════
  Planning/AnnualPlan/Show.vue

  Thin wrapper around the shared PlanWizard component (Decision 1 —
  one shared flow, two products). All the real UI lives in
  Components/Planning/PlanWizard.vue.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.show
  @props     plan: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="plan.name" />
    <PlanWizard :plan="plan" plan-type="annual" route-prefix="planning.annual-plan" />
</template>

<script setup>
import { Head } from '@inertiajs/vue3'
import PlanWizard from '@/Components/Planning/PlanWizard.vue'

defineProps({ plan: Object })
</script>
