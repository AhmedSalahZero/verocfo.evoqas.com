<?php

// ══════════════════════════════════════════════════════════════════
//  excel.php
//
//  Overrides ONE setting of the Laravel Excel package (maatwebsite/excel);
//  every other setting keeps the package's own default, because Laravel
//  merges this file with the package's config key by key.
//
//  value_binder — Audit L4: all exports write amounts as real numbers
//  and neutralise text that Excel would treat as a formula. See
//  App\Exports\Support\SafeValueBinder.
//
//  @layer    Config
// ══════════════════════════════════════════════════════════════════

return [
    'value_binder' => [
        'default' => App\Exports\Support\SafeValueBinder::class,
    ],
];
