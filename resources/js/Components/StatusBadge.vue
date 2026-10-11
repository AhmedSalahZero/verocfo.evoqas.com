<!--
══════════════════════════════════════════════════════════════════
  Components/StatusBadge.vue

  Shared status badge — replaces the hand-copied STATUS_META /
  statusMeta() pattern found duplicated (with small inconsistencies)
  across ~33 files. Same visual result everywhere from now on;
  change a color once here instead of once per page.

  Comes with sensible defaults for the status words already used
  throughout Vero CFO (active, draft, on_hold, closed, cancelled,
  expired, pending, posted, etc.) — a page only needs to pass
  `:status="..."` and it works. A page with a status word not in
  the defaults, or that wants different wording/colors for a status
  it does have, can override via the `overrides` prop without
  needing its own copy of this whole mapping.

  @layout    — (plain component, no layout)
  @route     — n/a
  @props     status:    string — the raw status value (e.g. 'active')
              overrides: Object — optional, merges over/extends the
                         built-in status map for this instance only.
                         Shape: { [status]: { label, badge, dot } }
              label:     string — optional, overrides just the display
                         text without touching color (rare; e.g. a
                         page wants to show a translated/custom label
                         for an otherwise-standard status)
══════════════════════════════════════════════════════════════════
-->
<template>
  <span class="zv-badge" :class="meta.badge">
    <span class="zv-status-dot" :class="meta.dot"></span>
    {{ label ?? t(meta.label) }}
  </span>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  status: { type: String, required: true },
  overrides: { type: Object, default: () => ({}) },
  label: { type: String, default: null },
})

// The single source of truth for status → color + label, covering
// every status word already in use across Vero CFO today (facility
// statuses, project/SO statuses, LG issuance statuses, PO statuses).
// Add a new status word here once, and every page gets it —
// instead of every page inventing its own fallback.
const DEFAULT_STATUS_META = {
  // ── Generic lifecycle (facilities, projects, contracts) ────────
  active:    { label: 'Active',    badge: 'zv-badge-green',  dot: 'zv-status-dot-green'  },
  draft:     { label: 'Draft',     badge: 'zv-badge-gray',   dot: 'zv-status-dot-gray'   },
  on_hold:   { label: 'On Hold',   badge: 'zv-badge-amber',  dot: 'zv-status-dot-amber'  },
  closed:    { label: 'Closed',    badge: 'zv-badge-gray',   dot: 'zv-status-dot-gray'   },
  cancelled: { label: 'Cancelled', badge: 'zv-badge-danger', dot: 'zv-status-dot-danger' },
  expired:   { label: 'Expired',   badge: 'zv-badge-amber',  dot: 'zv-status-dot-amber'  },

  // ── Sales/Purchase Order specific ──────────────────────────────
  posted:    { label: 'Posted',    badge: 'zv-badge-green',  dot: 'zv-status-dot-green'  },

  // ── LG Issuance / facility collateral specific ─────────────────
  running:      { label: 'Running',      badge: 'zv-badge-green',  dot: 'zv-status-dot-green'  },
  renewed:      { label: 'Renewed',      badge: 'zv-badge-blue',   dot: 'zv-status-dot-blue'   },
  liquidated:   { label: 'Liquidated',   badge: 'zv-badge-danger', dot: 'zv-status-dot-danger' },
  valid:        { label: 'Valid',        badge: 'zv-badge-green',  dot: 'zv-status-dot-green'  },
  warning:      { label: 'Warning',      badge: 'zv-badge-amber',  dot: 'zv-status-dot-amber'  },

  // ── Bank Account / generic active-inactive toggle ──────────────
  inactive:  { label: 'Inactive',  badge: 'zv-badge-gray',   dot: 'zv-status-dot-gray'   },
}

const FALLBACK_META = { label: null, badge: 'zv-badge-gray', dot: 'zv-status-dot-gray' }

const meta = computed(() => {
  const merged = { ...DEFAULT_STATUS_META, ...props.overrides }
  const found = merged[props.status]
  if (found) return found
  // Unknown status word — degrade gracefully (gray badge, raw status
  // text as the label) rather than showing nothing or throwing.
  return { ...FALLBACK_META, label: props.status }
})
</script>
