<!--
══════════════════════════════════════════════════════════════════
  Components/ProgressBadge.vue

  Status pill with a 4px magnitude bar under it — cashvero's answer
  to "partial by how much". Pass `percent` directly, or `value` +
  `outstanding` and the collected/settled share is derived.

  @props     label: string
             badge: string — zv-badge-* class
             percent: number — 0–100; wins over value/outstanding
             value: number — document total
             outstanding: number — still unpaid / unreceived
             tone: blue | green | amber | danger | navy — bar fill
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-progress-cell">
    <span class="zv-badge" :class="badge">{{ label }}</span>
    <div class="zv-bar">
      <span class="zv-bar-fill" :class="fillClass" :style="{ width: clamped + '%' }"></span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  label: { type: String, required: true },
  badge: { type: String, default: 'zv-badge-gray' },
  percent: { type: [Number, String], default: null },
  value: { type: [Number, String], default: null },
  outstanding: { type: [Number, String], default: null },
  tone: { type: String, default: 'blue' },
})

const clamped = computed(() => {
  if (props.percent !== null && props.percent !== '') {
    return Math.max(0, Math.min(100, Number(props.percent) || 0))
  }
  const total = Number(props.value || 0)
  if (total <= 0) return 0
  const done = total - Number(props.outstanding || 0)
  return Math.max(0, Math.min(100, (done / total) * 100))
})

const fillClass = computed(() => ({
  green: 'zv-bar-fill-green',
  amber: 'zv-bar-fill-amber',
  danger: 'zv-bar-fill-danger',
  navy: 'zv-bar-fill-navy',
}[props.tone] || ''))
</script>
