<!--
══════════════════════════════════════════════════════════════════
  Valuation/BusinessPlan/Show.vue

  Thin wrapper around the shared PlanWizard component (Decision 1 —
  one shared flow, two products). All the real UI lives in
  Components/Planning/PlanWizard.vue. The only difference from
  Annual Plan's Show.vue is plan-type="business_plan", which adds
  the Valuation tab inside PlanWizard.

  @layout    AppLayout (applied automatically)
  @route     valuation.business-plan.show
  @props     plan: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="plan.name" />
    <PlanWizard :plan="plan" plan-type="business_plan" route-prefix="valuation.business-plan" />
</template>

<script setup>
import { Head } from '@inertiajs/vue3'
import PlanWizard from '@/Components/Planning/PlanWizard.vue'

defineProps({ plan: Object })
</script>
