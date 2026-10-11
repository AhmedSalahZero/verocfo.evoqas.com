<!--
══════════════════════════════════════════════════════════════════
  Valuation/BusinessPlan/Expenses.vue

  Thin wrapper around the shared ExpensesBody component (Decision 1 —
  one shared flow, two products). All real UI lives in
  Components/Planning/ExpensesBody.vue.

  @layout    AppLayout (applied automatically)
  @route     valuation.business-plan.expenses
  @props     plan: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <ExpensesBody :plan="plan" :wizard-steps="wizardSteps" route-prefix="valuation.business-plan" />
</template>

<script setup>
import ExpensesBody from '@/Components/Planning/ExpensesBody.vue'

defineProps({ plan: Object, wizardSteps: Object })
</script>
