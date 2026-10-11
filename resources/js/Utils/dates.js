// ══════════════════════════════════════════════════════════════════
//  dates.js
//
//  Date helpers for the browser.
//
//  Why this file exists: forms used  new Date().toISOString().slice(0, 10)
//  to pre-fill "today". toISOString() always answers in UTC, so between
//  midnight and about 3 am in Cairo (UTC+2 / UTC+3) it returns YESTERDAY.
//  A document opened just after midnight on the last day of a month would
//  then default to the previous month (or even a closed period).
//
//  todayLocal() builds the date from the computer's own calendar day, so
//  it is always the date the user sees on their wall clock.
//
//    todayLocal()              → '2026-10-10'   (YYYY-MM-DD, local day)
//    addMonthsClamped(iso, n)  → iso date n months later, never spilling
//                                into the next month (31 Jan + 1 → 28 Feb)
//
//  @layer    Utility (frontend)
// ══════════════════════════════════════════════════════════════════

const pad = (n) => String(n).padStart(2, '0')

/** Today's date on the user's own clock, as YYYY-MM-DD. */
export function todayLocal() {
    const d = new Date()
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/**
 * Add whole months to a YYYY-MM-DD date. If the target month is shorter
 * (31 Jan + 1 month), the result is clamped to that month's last day
 * instead of rolling over into the next month.
 * Returns a plain Date set to local noon on the result day (noon avoids
 * daylight-saving edge cases); callers format it themselves.
 */
export function addMonthsClamped(isoDate, months) {
    const [y, m, d] = String(isoDate).slice(0, 10).split('-').map(Number)
    const target = new Date(y, m - 1 + Number(months), 1, 12)   // 1st of target month, local noon
    const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate()
    target.setDate(Math.min(d, lastDay))
    return target
}

/**
 * isoDate ± n days, as YYYY-MM-DD. Works on the calendar date itself (UTC
 * arithmetic), so a daylight-saving change or the user's time zone can never
 * shift the answer by a day — which plain new Date(str).setDate() could.
 */
export function addDaysIso(isoDate, days) {
    const [y, m, d] = String(isoDate).slice(0, 10).split('-').map(Number)
    const t = new Date(Date.UTC(y, m - 1, d + Number(days)))
    return `${t.getUTCFullYear()}-${pad(t.getUTCMonth() + 1)}-${pad(t.getUTCDate())}`
}

/** Today minus one year on the user's own clock (Feb 29 -> Feb 28). */
export function oneYearAgoLocal() {
    const d = new Date()
    const y = d.getFullYear() - 1, m = d.getMonth()
    const last = new Date(y, m + 1, 0).getDate()
    return `${y}-${pad(m + 1)}-${pad(Math.min(d.getDate(), last))}`
}
