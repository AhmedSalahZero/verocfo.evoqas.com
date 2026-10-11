<!--
══════════════════════════════════════════════════════════════════
  Components/RichNoteEditor.vue

  Minimal rich-text box for the Sales Dashboard's Analyst Notes
  section (bold / italic / bulleted list). Deliberately NOT built on
  a third-party editor library (Quill/TipTap/etc.) — none exists in
  this codebase yet, and Mahmoud approved "no upload step" for the
  dashboard as a whole, so no new build dependency for one small
  text box either. Plain contentEditable + document.execCommand,
  same technique CFO Tools' own notes box used.

  Parent reads the current content via a template ref's exposed
  getHtml() — no v-model, since contentEditable + v-model fights
  the browser's own cursor position on every keystroke.

  @layout    (component, no layout)
  @props     initialHtml: string — starting content (edit mode)
  @exposes   getHtml(): string
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="rne-box">
    <div class="rne-toolbar">
      <button type="button" class="rne-btn" @mousedown.prevent="exec('bold')"><b>B</b></button>
      <button type="button" class="rne-btn" @mousedown.prevent="exec('italic')"><i>I</i></button>
      <button type="button" class="rne-btn" @mousedown.prevent="exec('insertUnorderedList')">• {{ t('analytics.salesAnalysis.dashboard.bulletList') }}</button>
    </div>
    <div
      ref="editorEl"
      class="rne-editable"
      contenteditable="true"
      :data-placeholder="t('analytics.salesAnalysis.dashboard.writeNote')"
      v-html="props.initialHtml"
    ></div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  initialHtml: { type: String, default: '' },
})

const editorEl = ref(null)

function exec(command) {
  document.execCommand(command, false, null)
  editorEl.value?.focus()
}

function getHtml() {
  return editorEl.value?.innerHTML || ''
}

defineExpose({ getHtml })
</script>

<style scoped>
.rne-box { border: 1px solid var(--zv-border, #e5e7eb); border-radius: 8px; overflow: hidden; }
.rne-toolbar { display: flex; gap: 4px; padding: 6px; background: var(--zv-bg-surface-alt, #f9fafb); border-bottom: 1px solid var(--zv-border, #e5e7eb); }
.rne-btn { font-size: 12px; padding: 4px 10px; border-radius: 6px; color: var(--zv-text-secondary, #374151); background: #fff; border: 1px solid var(--zv-border, #e5e7eb); }
.rne-btn:hover { background: var(--zv-bg-surface-alt, #f3f4f6); }
.rne-editable { min-height: 70px; padding: 10px 12px; font-size: 13.5px; line-height: 1.6; outline: none; }
.rne-editable:empty::before { content: attr(data-placeholder); color: var(--zv-text-muted, #9ca3af); }
.rne-editable :deep(ul) { list-style: disc; padding-inline-start: 1.4em; }
</style>
