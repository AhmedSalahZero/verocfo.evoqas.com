<!--
══════════════════════════════════════════════════════════════════
  Components/SelectBox.vue

  The searchable, non-native replacement for `<select class="zv-select">`.

  WHY THIS EXISTS
  A native <select> renders with the operating system's own widget:
  its font, its row height, its scrollbar, its arrow. That is why the
  accounting forms looked "very native" no matter what the stylesheet
  said — a native select is the one control CSS genuinely cannot
  restyle past its border. It also cannot be typed into, which is a
  real problem here and not just a cosmetic one: the chart-of-accounts
  select, the product select and the partner select routinely carry
  hundreds of options, and picking one meant scrolling a list.

  ANATOMY — taken from cashvero's own dropdown
  Their MultiSelectDropdown renders a <button class="cvr-input …"> as
  the trigger and a .cvr-modal panel beneath it. So the trigger is
  literally styled as an input, which is what makes their controls
  line up with the text fields beside them. This component does the
  same with Vero's classes: the trigger IS a .zv-input, so a select
  and an input in the same row are the same object at the same height,
  automatically, forever — no parallel select styling to keep in sync.

  What theirs does NOT have is a search box; it has Select All /
  Deselect All instead, because it is a filter multi-select over short
  lists. Vero's selects are single-value pickers over long domain
  lists, so the panel gets a filter field (shown once the list is long
  enough to need one — see SEARCH_THRESHOLD).

  MENU IS TELEPORTED, deliberately — the same reason documented at
  length in SearchableSelect.vue: .form-section and .zv-acc .zv-card
  both set `overflow: hidden` for their rounded corners, and a menu
  nested inside either gets sliced off rather than overflowing. Fixed
  coordinates are computed from the trigger's own bounding rect.

  RELATIONSHIP TO SearchableSelect.vue
  Two different problems, deliberately kept as two components:
    - SearchableSelect — options live on the SERVER; it asks an
      endpoint per keystroke. For sets too large to send to the browser
      at all (every product in the catalogue).
    - SelectBox (this) — options are ALREADY on the page, passed as an
      Inertia prop, and it filters them in memory. This is the case for
      almost every select in Accounting.

  USAGE
    <SelectBox
      v-model="form.currency_id"
      :options="currencies"
      value-key="id" label-key="code"
      :placeholder="t('...selectCurrency')"
      :error="!!form.errors.currency_id"
    />

  For an options array that is already {value,label} the keys can be
  omitted. `sub-key` adds a dimmer second line to each row (an account
  name under its code, a supplier name under its code).

  @layout    — (plain component, no layout)
  @props     modelValue  — the selected value
             options     — array of objects, or of plain strings/numbers
             valueKey / labelKey / subKey — field names within options
             placeholder — shown when nothing is selected
             searchable  — force the filter box on/off (default: auto)
             clearable   — show an × to reset to null (default true when
                           a placeholder is given, i.e. "no selection"
                           is a legal state — a required field should
                           pass :clearable="false")
             disabled, error, size ('sm' inside a table cell)
  @emits     update:modelValue, change
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-sb" ref="wrapRef">
    <button
      type="button"
      class="zv-input zv-sb-trigger"
      :class="{ 'zv-input-error': error, 'zv-sb-sm': size === 'sm', 'zv-sb-open': open }"
      :disabled="disabled"
      :aria-expanded="open"
      aria-haspopup="listbox"
      @click="toggle"
      @keydown.down.prevent="openMenu()"
      @keydown.enter.prevent="toggle"
      @keydown.space.prevent="toggle"
    >
      <span class="zv-sb-value" :class="{ 'zv-sb-placeholder': !selected }">
        {{ selected ? selected.label : (placeholder || t('components.selectBox.select')) }}
      </span>

      <span
        v-if="showClear"
        class="zv-sb-clear"
        role="button"
        :title="t('components.selectBox.clear')"
        @click.stop="clear"
        @keydown.enter.stop.prevent="clear"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </span>

      <svg class="zv-sb-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/>
      </svg>
    </button>

    <Teleport to="body">
      <div v-if="open" class="zv-sb-menu" ref="menuRef" :style="menuStyle" role="listbox">
        <div v-if="useSearch" class="zv-sb-search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
          </svg>
          <input
            ref="searchRef"
            v-model="query"
            type="text"
            :placeholder="t('components.selectBox.typeToFilter')"
            autocomplete="off"
            @keydown.down.prevent="moveHighlight(1)"
            @keydown.up.prevent="moveHighlight(-1)"
            @keydown.enter.prevent="selectHighlighted"
            @keydown.esc.prevent="close"
            @keydown.tab="close"
          />
        </div>

        <div class="zv-sb-list" ref="listRef">
          <!-- The "no selection" row. A native <select> carries this as a
               real <option value=""> — which is why a Purchase Order
               dropdown with no POs still looked like it had one entry.
               Dropping it made the × the only way back to "none", which
               is a worse control than the one it replaced, so the row is
               back. It is only rendered when clearing is actually legal,
               and it is hidden while filtering, where it would be the one
               row that never matches what was typed. -->
          <div
            v-if="showClearRow"
            class="zv-sb-option zv-sb-option-none"
            :class="{ 'zv-sb-option-selected': modelValue === null || modelValue === '' }"
            role="option"
            :aria-selected="modelValue === null || modelValue === ''"
            @mousedown.prevent="clear"
          >
            <span class="zv-sb-option-text">
              <span class="zv-sb-option-label">{{ placeholder || t('components.selectBox.select') }}</span>
            </span>
            <svg v-if="modelValue === null || modelValue === ''" class="zv-sb-tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
          </div>

          <div
            v-for="(opt, idx) in filtered"
            :key="opt.value"
            class="zv-sb-option"
            :class="{
              'zv-sb-option-active': idx === highlighted,
              'zv-sb-option-selected': opt.value === modelValue,
              'zv-sb-option-disabled': opt.disabled,
            }"
            role="option"
            :aria-selected="opt.value === modelValue"
            @mousedown.prevent="opt.disabled || select(opt)"
            @mouseenter="highlighted = idx"
          >
            <span class="zv-sb-option-text">
              <span class="zv-sb-option-label">{{ opt.label }}</span>
              <span v-if="opt.sub" class="zv-sb-option-sub">{{ opt.sub }}</span>
            </span>
            <svg v-if="opt.value === modelValue" class="zv-sb-tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
          </div>

          <div v-if="filtered.length === 0" class="zv-sb-empty">
            {{ query ? t('components.selectBox.noMatches') : t('components.selectBox.noOptions') }}
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  modelValue: { type: [String, Number, Boolean, null], default: null },
  options:    { type: Array, default: () => [] },
  valueKey:   { type: String, default: 'value' },
  labelKey:   { type: String, default: 'label' },
  subKey:     { type: String, default: null },
  // For options whose label or value is not a single field. The
  // accounting forms are full of composite labels — "P-00004 — Dalydress
  // Egypt", "INV-88 — Outstanding: 4,180.75" — that a key name cannot
  // express. Taking a function keeps the exact string each <option> used
  // to render, instead of forcing every call site to pre-map its list
  // into a new array on every render.
  labelFn:    { type: Function, default: null },
  valueFn:    { type: Function, default: null },
  // Whether a given option is selectable. A `disabled` field ON the
  // option object covers the static case; this covers the case where
  // it depends on something outside the list — Stock Transfer greys
  // out the location already chosen in the other field, which is not a
  // property of the location, it is a property of the form.
  disabledFn: { type: Function, default: null },
  placeholder:{ type: String, default: '' },
  searchable: { type: Boolean, default: null },   // null = on
  clearable:  { type: Boolean, default: null },
  disabled:   { type: Boolean, default: false },
  error:      { type: Boolean, default: false },
  size:       { type: String, default: null },
})

const emit = defineEmits(['update:modelValue', 'change'])

const open        = ref(false)
const query       = ref('')
const highlighted = ref(-1)
const wrapRef     = ref(null)
const menuRef     = ref(null)
const listRef     = ref(null)
const searchRef   = ref(null)
const menuStyle   = ref({})

// One internal shape regardless of what the page passed in, so the
// template never has to know whether it got {id, code} or {value,label}
// or a bare array of strings.
const normalized = computed(() => props.options.map((o) => {
  const isObj = o !== null && typeof o === 'object'
  const value = props.valueFn ? props.valueFn(o) : (isObj ? o[props.valueKey] : o)
  const label = props.labelFn ? props.labelFn(o) : (isObj ? o[props.labelKey] : o)
  return {
    value,
    label: String(label ?? ''),
    sub: props.subKey && isObj ? (o[props.subKey] ?? null) : null,
    disabled: props.disabledFn ? !!props.disabledFn(o) : (isObj && !!o.disabled),
  }
}))

const selected = computed(() => normalized.value.find(o => o.value === props.modelValue) ?? null)

// Always on. This first shipped behind an "only once the list is long
// enough to need it" threshold, on the reasoning that a filter field
// above three visible options is dead weight. That reasoning was wrong
// for two reasons. A control that sometimes has a search box and
// sometimes doesn't is a control you cannot build a habit around —
// you have to look before you type, every time, and the one list where
// you most want to type fast is the one you use most often. And option
// counts here are data, not design: a company with three suppliers
// today has forty next year, so the same field silently changes
// behaviour as the database fills up. Pages that genuinely want it off
// can still pass :searchable="false".
const useSearch = computed(() => props.searchable === null ? true : props.searchable)

// A field is clearable when "nothing selected" is a state the form
// actually allows — which a placeholder is the page's way of saying.
const clearAllowed = computed(() =>
  (props.clearable === null ? !!props.placeholder : props.clearable) && !props.disabled
)
// The × on the trigger: only once there is something to clear.
const showClear = computed(() =>
  clearAllowed.value && props.modelValue !== null && props.modelValue !== ''
)
// The "none" row inside the panel: shown whenever clearing is legal, so
// the list always offers the same choices the old <select> did.
const showClearRow = computed(() => clearAllowed.value && !query.value)

// Match on label AND sub, so typing an account name finds it even when
// the label shown is the account code.
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return normalized.value
  return normalized.value.filter(o =>
    o.label.toLowerCase().includes(q) || (o.sub && String(o.sub).toLowerCase().includes(q))
  )
})

watch(filtered, () => {
  highlighted.value = filtered.value.length ? 0 : -1
})

function updateMenuPosition() {
  if (!wrapRef.value) return
  const rect = wrapRef.value.getBoundingClientRect()
  const below = window.innerHeight - rect.bottom
  // Flip above the trigger when there is more room up there — a select
  // near the bottom of a long form otherwise opens into 40px of space.
  const flip = below < 240 && rect.top > below
  menuStyle.value = {
    position: 'fixed',
    left: `${rect.left}px`,
    width: `${rect.width}px`,
    ...(flip
      ? { bottom: `${window.innerHeight - rect.top + 4}px`, maxHeight: `${rect.top - 12}px` }
      : { top: `${rect.bottom + 4}px`, maxHeight: `${below - 12}px` }),
  }
}

function openMenu() {
  if (props.disabled || open.value) return
  query.value = ''
  open.value = true
  const idx = normalized.value.findIndex(o => o.value === props.modelValue)
  highlighted.value = idx >= 0 ? idx : (normalized.value.length ? 0 : -1)
  nextTick(() => {
    updateMenuPosition()
    // preventScroll: focusing an element the browser considers off-screen
    // otherwise scrolls the page to it — and a page scroll is something
    // this component reacts to (see onScroll).
    if (useSearch.value) searchRef.value?.focus({ preventScroll: true })
    scrollHighlightedIntoView()
  })
}

function close() {
  open.value = false
  query.value = ''
}

function toggle() {
  open.value ? close() : openMenu()
}

function select(opt) {
  emit('update:modelValue', opt.value)
  emit('change', opt.value)
  close()
}

function clear() {
  emit('update:modelValue', null)
  emit('change', null)
  close()
}

function moveHighlight(step) {
  if (!filtered.value.length) return
  let i = highlighted.value
  // Skip over disabled rows rather than parking the highlight on one.
  for (let n = 0; n < filtered.value.length; n++) {
    i = (i + step + filtered.value.length) % filtered.value.length
    if (!filtered.value[i].disabled) break
  }
  highlighted.value = i
  nextTick(scrollHighlightedIntoView)
}

function selectHighlighted() {
  const opt = filtered.value[highlighted.value]
  if (opt && !opt.disabled) select(opt)
}

// Scrolls the option list only. Element.scrollIntoView() is the obvious
// way to write this and is what shipped first — but it scrolls every
// scrollable ancestor it needs to, including the document, and this
// menu is position:fixed inside <body>. So on the first open the
// browser scrolled the page to "reach" the menu, that page scroll fired
// a scroll event, and onScroll closed the menu ~2ms after it opened.
// The menu then appeared to need two clicks: the second click worked
// only because the page was already scrolled and no scroll event fired.
// Doing the arithmetic against the list's own scrollTop cannot move
// anything except the list.
function scrollHighlightedIntoView() {
  const list = listRef.value
  // Not list.children[i] — the "no selection" row is also a child, so
  // the option at filtered[i] is not necessarily the i-th element.
  const el = list?.querySelectorAll('.zv-sb-option:not(.zv-sb-option-none)')[highlighted.value]
  if (!list || !el) return
  const top = el.offsetTop
  const bottom = top + el.offsetHeight
  if (top < list.scrollTop) list.scrollTop = top
  else if (bottom > list.scrollTop + list.clientHeight) list.scrollTop = bottom - list.clientHeight
}

// The menu is teleported, so it is NOT a DOM descendant of wrapRef —
// a click-away test has to check both roots separately.
function onDocPointerDown(e) {
  if (!open.value) return
  const inTrigger = wrapRef.value?.contains(e.target)
  const inMenu = menuRef.value?.contains(e.target)
  if (!inTrigger && !inMenu) close()
}

// The menu is position:fixed, so it does not travel with the page the
// way an absolutely-positioned child would. Rather than dismiss on any
// scroll — which is what made an incidental scroll able to kill a menu
// that had just opened — recompute its coordinates so it stays glued to
// its trigger, and only dismiss once the trigger itself has left the
// viewport, where there is nothing left to anchor to.
function onScroll(e) {
  if (!open.value) return
  if (menuRef.value && e.target instanceof Node && menuRef.value.contains(e.target)) return
  const rect = wrapRef.value?.getBoundingClientRect()
  if (!rect || rect.bottom < 0 || rect.top > window.innerHeight) { close(); return }
  updateMenuPosition()
}
function onResize() { if (open.value) updateMenuPosition() }
function onKeydown(e) { if (e.key === 'Escape' && open.value) close() }

onMounted(() => {
  document.addEventListener('pointerdown', onDocPointerDown, true)
  window.addEventListener('scroll', onScroll, true)
  window.addEventListener('resize', onResize)
  document.addEventListener('keydown', onKeydown)
})
onUnmounted(() => {
  document.removeEventListener('pointerdown', onDocPointerDown, true)
  window.removeEventListener('scroll', onScroll, true)
  window.removeEventListener('resize', onResize)
  document.removeEventListener('keydown', onKeydown)
})
</script>
