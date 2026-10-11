// ══════════════════════════════════════════════════════════════════
//  money.js
//
//  Exact decimal arithmetic for the browser (Audit L6).
//
//  The totals shown on invoice / bill / order forms while the user is
//  typing used to be added up with ordinary JavaScript numbers, which
//  can be a cent off (0.1 + 0.2 = 0.30000000000000004, and a line
//  rounded differently from the server). The server always recomputes
//  with exact maths, so the books were never at risk — but a preview that
//  disagrees with the saved document looks wrong and wastes time.
//
//  These helpers do the maths on whole numbers (BigInt), so they are
//  exact, and they follow the SERVER'S rounding rules to the letter
//  (PHP bcmath: multiplication and division CUT OFF extra decimals, they
//  do not round). So the preview equals the saved document.
//
//  All functions take numbers or numeric strings and return a decimal
//  STRING (e.g. '1234.50'); blank / invalid input counts as 0.
//
//    lineSubtotal(qty, price)         → bcmul(qty, price, 2)
//    lineVat(subtotal, ratePercent)   → bcdiv(bcmul(subtotal, rate, 4), 100, 2)
//    sum([a, b, c])                   → exact total, 2 decimals
//    discountedLineTotal(qty, price, discountPct)  → PO / SO line total
//    add(a, b)  mul(a, b, scale)  div(a, b, scale)  round(a, scale)
//
//  Mirrors CustomerInvoiceService (lines 798-801).
// ══════════════════════════════════════════════════════════════════

const TEN = 10n

function pow10(n) {
    return TEN ** BigInt(n)
}

// '12.345' → { n: 12345n, s: 3 }   (value = n / 10^s)
function parse(value) {
    if (value === null || value === undefined || value === '') return { n: 0n, s: 0 }

    let text = typeof value === 'number'
        ? (Number.isFinite(value) ? value.toFixed(10) : '0')
        : String(value).trim().replace(/,/g, '')

    if (!/^[+-]?(\d+(\.\d*)?|\.\d+)$/.test(text)) return { n: 0n, s: 0 }

    const negative = text.startsWith('-')
    text = text.replace(/^[+-]/, '')
    const [whole = '0', fraction = ''] = text.split('.')
    const n = BigInt((whole || '0') + fraction)

    return { n: negative ? -n : n, s: fraction.length }
}

// Re-express n/10^s with exactly `scale` decimals, CUTTING OFF extra digits (like bcmath).
function truncateTo({ n, s }, scale) {
    return s > scale ? n / pow10(s - scale) : n * pow10(scale - s)
}

function format(scaled, scale) {
    const negative = scaled < 0n
    const digits = (negative ? -scaled : scaled).toString().padStart(scale + 1, '0')
    const whole = scale > 0 ? digits.slice(0, -scale) : digits
    const fraction = scale > 0 ? '.' + digits.slice(-scale) : ''
    const text = whole + fraction

    return negative && scaled !== 0n ? '-' + text : text
}

export function add(a, b, scale = 2) {
    return sum([a, b], scale)
}

export function sum(values, scale = 2) {
    let total = 0n
    for (const v of values) total += truncateTo(parse(v), scale + 8)

    return format(truncateTo({ n: total, s: scale + 8 }, scale), scale)
}

export function mul(a, b, scale = 2) {
    const x = parse(a), y = parse(b)

    return format(truncateTo({ n: x.n * y.n, s: x.s + y.s }, scale), scale)
}

export function div(a, b, scale = 2) {
    const x = parse(a), y = parse(b)
    if (y.n === 0n) return format(0n, scale)

    const scaled = (x.n * pow10(y.s) * pow10(scale)) / (y.n * pow10(x.s))

    return format(scaled, scale)
}

// Half away from zero, like the server's Money::round().
export function round(value, scale = 2) {
    const x = parse(value)
    const negative = x.n < 0n
    const abs = negative ? -x.n : x.n
    const shifted = x.s > scale
        ? (abs + 5n * pow10(x.s - scale - 1)) / pow10(x.s - scale)
        : abs * pow10(scale - x.s)

    return format(negative ? -shifted : shifted, scale)
}

export function sub(a, b, scale = 2) {
    const x = truncateTo(parse(a), scale + 8), y = truncateTo(parse(b), scale + 8)

    return format(truncateTo({ n: x - y, s: scale + 8 }, scale), scale)
}

// Cut off (not round) to `scale` decimals — what bcadd($x, '0', $scale) does on the server.
export function truncate(value, scale = 2) {
    return format(truncateTo(parse(value), scale), scale)
}

// Mirrors PurchaseOrderService / SalesOrderService::calcLineTotal():
//   gross = qty × price (6 dp); discount = gross × disc% / 100 (6 dp); total = gross − discount, cut to 2 dp.
export function discountedLineTotal(quantity, unitPrice, discountPct) {
    const gross = mul(quantity, unitPrice, 6)
    const discount = div(mul(gross, discountPct, 6), '100', 6)

    return truncate(sub(gross, discount, 6), 2)
}

export function lineSubtotal(quantity, unitPrice) {
    return mul(quantity, unitPrice, 2)
}

export function lineVat(subtotal, ratePercent) {
    return div(mul(subtotal, ratePercent, 4), '100', 2)
}
