<template>
    <div :dir="dir" :lang="currentLocale">
        <slot />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { usePage }  from '@inertiajs/vue3'

const page         = usePage()
const currentLocale = computed(() => page.props.locale ?? 'en')
const dir           = computed(() => currentLocale.value === 'ar' ? 'rtl' : 'ltr')
</script>
