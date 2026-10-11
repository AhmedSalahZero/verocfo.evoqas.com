<!--
══════════════════════════════════════════════════════════════════
  ComingSoon.vue

  Shown when a menu item points at a screen that is not built yet
  (Audit M43). Tells the user plainly that the page is not available
  yet and gives them a way back, instead of silently showing the
  company Dashboard as the old placeholder routes did.

  @layout    AppLayout
  @route     any route served by ComingSoonController
  @props     feature: String — the route name with dots/dashes turned
                       into underscores (e.g. analytics_ratios_index);
                       used only to look up a translated page title
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

        <div class="zv-page-header">
            <div>
                <div class="zv-page-title">{{ title }}</div>
                <div class="zv-page-subtitle">{{ t('comingSoon.subtitle') }}</div>
            </div>
        </div>

        <div class="zv-card">
            <div class="zv-card-body">
                <div class="zv-empty">
                    <div class="zv-empty-icon">
                        <svg width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z"/>
                        </svg>
                    </div>
                    <div class="zv-empty-title">{{ t('comingSoon.heading') }}</div>
                    <div class="zv-empty-sub">{{ t('comingSoon.body') }}</div>
                    <Link :href="route('dashboard')" class="zv-btn zv-btn-primary cs-back">
                        {{ t('comingSoon.backToDashboard') }}
                    </Link>
                </div>
            </div>
        </div>

    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    feature: { type: String, default: '' },
})

const { t, te } = useI18n()

// A translated name for the menu item that was clicked; a generic
// wording if a new stub route has no entry yet.
const title = computed(() => {
    const key = `comingSoon.features.${props.feature}`
    return te(key) ? t(key) : t('comingSoon.thisPage')
})
</script>

<style scoped>
.cs-back { margin-top: 1.25rem; }
</style>
