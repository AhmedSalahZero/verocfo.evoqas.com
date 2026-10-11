<!--
══════════════════════════════════════════════════════════════════
  Components/SearchableSelect.vue

  Type-to-search dropdown, backed by a server-side search endpoint —
  NOT a client-side filter over a list already loaded into the
  browser. Built July 2026 for the Inventory Statement's Product
  picker, but deliberately generic: this is the first reusable
  searchable-select in the app, and the same plain-`<select>`
  scalability problem exists on every Product/Partner picker across
  Purchase Orders, Sales Orders, and GRN — those weren't touched here
  (out of today's scope) but should move to this component rather
  than each hand-rolling their own version.

  WHY SERVER-SIDE: a company can carry thousands of SKUs. Loading
  every product into the page on first render (as every existing
  Product/Partner dropdown in the app currently does) doesn't scale —
  this component only ever asks the server for a small, matching
  page of results as the user types.

  USAGE:
    <SearchableSelect
      v-model="form.product_id"
      v-model:label="selectedProductLabel"
      :search-url="route('accounting.inventory-statement.products.search')"
      :placeholder="t('components.searchableSelect.searchByCodeOrName')"
    />

  The component keeps no opinion about what it's searching (products,
  partners, anything) — it just expects the endpoint to return
  `{ results: [{ id, label }] }`. The caller's controller/service
  decides what fields it matches against and how `label` is built.

  @layout    — (plain component, no layout)
  @route     — n/a
  @props     modelValue: Number|String|null — the selected id
              label:      String — the selected item's display text
                          (kept in sync via v-model:label so a page
                          reload / programmatic reset can show the
                          right text without a round-trip)
              searchUrl:  String — endpoint returning { results }
              placeholder: String
              minChars:   Number — default 1
  @emits     update:modelValue, update:label
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-ss-wrap" ref="wrapRef">
    <input
      type="text"
      class="zv-input zv-ss-input"
      v-model="query"
      :placeholder="placeholder"
      @focus="onFocus"
      @input="onInput"
      @keydown.down.prevent="moveHighlight(1)"
      @keydown.up.prevent="moveHighlight(-1)"
      @keydown.enter.prevent="selectHighlighted"
      @keydown.esc="close"
      autocomplete="off"
    />
    <svg v-if="loading" class="zv-ss-spinner zv-spinner w-3.5 h-3.5"></svg>
    <button v-else-if="modelValue" type="button" class="zv-ss-clear" @click="clear" :title="t('components.searchableSelect.clear')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
      </svg>
    </button>

    <!-- Teleported to <body> and positioned with fixed coordinates
         computed from the input's own bounding rect (see
         updateMenuPosition()) — NOT nested inside the field's normal DOM
         position. Several pages wrap this component in a card that sets
         `overflow: hidden` for its own rounded corners (e.g. .form-section
         in app.css); a dropdown menu nested inside that card gets sliced
         off the moment it needs to render below the card's own bottom
         edge — invisible, not just visually awkward. Teleporting escapes
         that clipping entirely, regardless of which page embeds this
         component or how short its container is. -->
    <Teleport to="body">
      <div v-if="open" class="zv-ss-menu" ref="menuRef" :style="menuStyle">
        <div v-if="results.length === 0 && !loading" class="zv-ss-empty">
          {{ query.length < minChars ? t('components.searchableSelect.keepTypingToSearch') : t('components.searchableSelect.noMatches') }}
        </div>
        <div
          v-for="(item, idx) in results"
          :key="item.id"
          class="zv-ss-item"
          :class="{ 'zv-ss-item-active': idx === highlighted }"
          @mousedown.prevent="select(item)"
          @mouseenter="highlighted = idx"
        >
          {{ item.label }}
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  modelValue: { type: [Number, String, null], default: null },
  label:      { type: String, default: '' },
  searchUrl:  { type: String, required: true },
  placeholder: { type: String, default: '' },
  minChars:   { type: Number, default: 1 },
})

const emit = defineEmits(['update:modelValue', 'update:label'])

const query       = ref(props.label || '')
const results     = ref([])
const open        = ref(false)
const loading     = ref(false)
const highlighted = ref(-1)
const wrapRef     = ref(null)
const menuRef     = ref(null) // the teleported dropdown itself — no longer a DOM descendant of wrapRef, so click-away/scroll checks must test both separately
const menuStyle   = ref({})

let debounceTimer = null
let requestToken   = 0

watch(() => props.label, (val) => {
  if (val !== query.value) query.value = val || ''
})

// Positions the teleported menu directly under the input, in viewport
// (fixed) coordinates — recomputed every time the menu opens, since
// the input's on-screen position depends on wherever this component
// happens to be embedded on the page.
function updateMenuPosition() {
  if (! wrapRef.value) return
  const rect = wrapRef.value.getBoundingClientRect()
  menuStyle.value = {
    position: 'fixed',
    top: `${rect.bottom + 4}px`,
    left: `${rect.left}px`,
    width: `${rect.width}px`,
  }
}

function onFocus() {
  open.value = true
  nextTick(updateMenuPosition)
  if (results.value.length === 0) search()
}

// Menu is anchored with `position: fixed`, so it doesn't move with page
// scroll the way an absolutely-positioned child would — close it on
// scroll instead of trying to track every possible scrolling ancestor.
// Registered with capture:true so it also catches scroll on any inner
// scrollable container on the page — EXCEPT the dropdown's own result
// list scrolling, which must not close the menu the user is actively
// scrolling through.
function onWindowScroll(e) {
  if (menuRef.value && e.target instanceof Node && menuRef.value.contains(e.target)) return
  if (open.value) close()
}
function onWindowResize() {
  if (open.value) updateMenuPosition()
}

function onInput() {
  // Typing changes the text away from the last confirmed selection —
  // clear the actual selected id so a half-typed query never gets
  // silently submitted as the old value.
  if (props.modelValue) {
    emit('update:modelValue', null)
    emit('update:label', query.value)
  }
  open.value = true
  nextTick(updateMenuPosition)
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(search, 250)
}

async function search() {
  if (query.value.length < props.minChars) {
    results.value = []
    return
  }
  loading.value = true
  const myToken = ++requestToken
  try {
    const res = await window.axios.get(props.searchUrl, { params: { q: query.value } })
    if (myToken !== requestToken) return // a newer keystroke already superseded this request
    results.value = res.data.results ?? []
    highlighted.value = results.value.length ? 0 : -1
    nextTick(updateMenuPosition)
  } finally {
    if (myToken === requestToken) loading.value = false
  }
}

function select(item) {
  query.value = item.label
  emit('update:modelValue', item.id)
  emit('update:label', item.label)
  close()
}

function selectHighlighted() {
  if (highlighted.value >= 0 && results.value[highlighted.value]) {
    select(results.value[highlighted.value])
  }
}

function moveHighlight(delta) {
  if (!open.value) { open.value = true; return }
  const max = results.value.length - 1
  if (max < 0) return
  highlighted.value = Math.min(max, Math.max(0, highlighted.value + delta))
}

function clear() {
  query.value = ''
  results.value = []
  emit('update:modelValue', null)
  emit('update:label', '')
}

function close() {
  open.value = false
}

function onClickAway(e) {
  const insideWrap = wrapRef.value && wrapRef.value.contains(e.target)
  const insideMenu = menuRef.value && menuRef.value.contains(e.target)
  if (! insideWrap && ! insideMenu) close()
}

onMounted(() => {
  document.addEventListener('mousedown', onClickAway)
  window.addEventListener('scroll', onWindowScroll, true) // capture: catches scroll on any inner scrollable ancestor too
  window.addEventListener('resize', onWindowResize)
})
onUnmounted(() => {
  document.removeEventListener('mousedown', onClickAway)
  window.removeEventListener('scroll', onWindowScroll, true)
  window.removeEventListener('resize', onWindowResize)
})
</script>

<style>
.zv-ss-wrap { position: relative; }
.zv-ss-input { padding-inline-end: 2rem; }
.zv-ss-spinner, .zv-ss-clear {
  position: absolute;
  inset-inline-end: 0.6rem;
  top: 50%;
  transform: translateY(-50%);
  background: transparent;
  border: none;
  color: var(--zv-text-muted);
  cursor: pointer;
  display: flex;
}
.zv-ss-menu {
  /* position/top/left/width are set inline via menuStyle (fixed,
     computed from the input's own bounding rect) — this component is
     teleported to <body>, so it no longer sits inside the normal
     document flow relative to .zv-ss-wrap. z-index raised well above
     any card/section/modal in the app (highest prior use was ~100 for
     the Apply/Cheque modals) since this can now be teleported out from
     underneath any of them. */
  z-index: 500;
  max-height: 260px;
  overflow-y: auto;
  background-color: var(--zv-bg-card);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius);
  box-shadow: var(--zv-shadow-md, 0 4px 16px rgba(0,0,0,0.12));
}
.zv-ss-item {
  padding: 0.5rem 0.75rem;
  font-size: 0.8125rem;
  cursor: pointer;
  color: var(--zv-text-primary);
}
.zv-ss-item-active { background-color: var(--zv-bg-surface); }
.zv-ss-empty {
  padding: 0.75rem;
  font-size: 0.8125rem;
  color: var(--zv-text-muted);
  text-align: center;
}
</style>
