<!--
══════════════════════════════════════════════════════════════════
  Components/Pagination.vue

  Shared pagination — replaces two different hand-built versions
  found doing the same job differently (one used icon arrows +
  a `disabled` class; the other used raw v-html on Laravel's
  pagination labels with a different disabled-state approach).
  This version follows the icon-arrow approach, since it doesn't
  rely on v-html for anything — safer by default and no less clear.

  Expects a standard Laravel paginator object passed straight from
  Inertia props — nothing to reshape on the page's side.

  @layout    — (plain component, no layout)
  @route     — n/a
  @props     paginator: Object — the Laravel paginator prop as-is:
                { data, links, from, to, total, last_page }
              itemLabel: string — optional, e.g. 'facility' /
                'partner' — only used for the singular/plural word
                in "X of Y {itemLabel}(s)" if a page wants that
                instead of the plain "Showing X–Y of Z"
══════════════════════════════════════════════════════════════════
-->
<template>
  <div v-if="paginator.last_page > 1" class="zv-pagination">
    <span>{{ t('common.showing') }} {{ paginator.from }}–{{ paginator.to }} {{ t('common.of') }} {{ paginator.total }}</span>

    <div class="zv-pagination-pages">
      <template v-for="(link, idx) in paginator.links" :key="idx">
        <component
          :is="link.url ? Link : 'span'"
          :href="link.url || undefined"
          class="zv-page-btn"
          :class="{ active: link.active, 'zv-page-btn-disabled': !link.url }"
        >
          <svg v-if="isPrevLink(link.label)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5 zv-page-icon">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
          </svg>
          <svg v-else-if="isNextLink(link.label)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5 zv-page-icon">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
          </svg>
          <span v-else>{{ link.label }}</span>
        </component>
      </template>
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
  paginator: { type: Object, required: true },
  itemLabel: { type: String, default: null },
})

// Laravel's default pagination labels are '&laquo; Previous' / 'Next &raquo;'
// — matching on the plain word keeps this working regardless of locale
// or exact wording Laravel generates.
function isPrevLink(label) { return label.includes('Previous') }
function isNextLink(label) { return label.includes('Next') }
</script>

<style>
/* These two classes (zv-page-btn-disabled, zv-page-icon) previously
   lived per-page with a different name in different files
   (e.g. cod-page-btn-disabled). Centralized here now — RTL flip for
   the icon and the disabled visual are both defined once. */
.zv-page-btn-disabled { opacity: 0.35; cursor: not-allowed; pointer-events: none; }
.zv-page-icon { flex-shrink: 0; }
[dir="rtl"] .zv-page-icon { transform: scaleX(-1); }
</style>
