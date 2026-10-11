<!--
══════════════════════════════════════════════════════════════════
  Components/PartnerCell.vue

  Partner / customer / supplier cell: navy→blue initials avatar plus
  the display name. Used across Accounting list tables so a row is
  scannable by person, not only by document code.

  @props     name: string — partner display name (usually name_en)
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-partner">
    <span class="zv-avatar" aria-hidden="true">{{ letters }}</span>
    <span class="zv-partner-name">{{ name || '—' }}</span>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  name: { type: String, default: '' },
})

const letters = computed(() => {
  const parts = String(props.name || '').trim().split(/\s+/).filter(Boolean)
  if (!parts.length) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})
</script>
