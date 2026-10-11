{{--
    resources/views/pdf/quotation.blade.php

    Quotation PDF — ZAVERO ERP
    Rendered by GenerateQuotationPdfJob via mPDF.

    Variables injected:
      $quotation   — CrmQuotation model (with lines, currency, deal, deal.account, deal.owner, company)
      $company     — Company model
      $locale      — 'ar' | 'en'  (from quotation owner's lang_pref)
      $isRtl       — bool
      $dir         — 'rtl' | 'ltr'
      $align       — 'right' | 'left'
      $alignOpp    — 'left'  | 'right'

    mPDF notes:
      - Use inline styles only — mPDF ignores most external CSS
      - Use <htmlpageheader> / <htmlpagefooter> for repeating headers/footers
      - Arabic text is rendered correctly by mPDF's bundled FreeFont Arabic glyphs
      - RTL paragraphs need dir="rtl" on the containing element
--}}
<!DOCTYPE html>
<html dir="{{ $dir }}" lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <style>
        /* ── Reset ───────────────────────────────────────────────── */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: {{ $isRtl ? "'XB Roya', 'FreeSerif', serif" : "'DejaVu Sans', sans-serif" }};
            font-size: 9pt;
            color: #1a1a2e;
            direction: {{ $dir }};
        }

        /* ── Page header / footer ────────────────────────────────── */
        .page-header { padding: 0 0 8px 0; border-bottom: 2px solid #1a1a2e; margin-bottom: 16px; }
        .page-footer { font-size: 7.5pt; color: #666; border-top: 1px solid #ddd; padding-top: 6px; text-align: center; }

        /* ── Layout helpers ──────────────────────────────────────── */
        .flex { display: table; width: 100%; }
        .col-left  { display: table-cell; width: 50%; vertical-align: top; }
        .col-right { display: table-cell; width: 50%; vertical-align: top; text-align: {{ $alignOpp }}; }

        /* ── Company block ───────────────────────────────────────── */
        .company-name { font-size: 14pt; font-weight: bold; color: #1a1a2e; }
        .company-meta { font-size: 8pt; color: #555; line-height: 1.6; }

        /* ── Quotation title block ───────────────────────────────── */
        .quotation-label {
            font-size: 20pt;
            font-weight: bold;
            color: #1a1a2e;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .quotation-number { font-size: 9pt; color: #555; margin-top: 2px; }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-accepted  { background: #d1fae5; color: #065f46; }
        .badge-sent      { background: #dbeafe; color: #1e40af; }
        .badge-draft     { background: #f3f4f6; color: #374151; }
        .badge-rejected  { background: #fee2e2; color: #991b1b; }
        .badge-cancelled { background: #f3f4f6; color: #6b7280; }
        .badge-revised   { background: #fef9c3; color: #92400e; }

        /* ── Info grid ───────────────────────────────────────────── */
        .info-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        .info-table td { padding: 4px 6px; font-size: 8.5pt; vertical-align: top; }
        .info-table .label { color: #6b7280; width: 38%; font-weight: bold; }
        .info-table .value { color: #1a1a2e; }

        /* ── Section headings ────────────────────────────────────── */
        .section-heading {
            font-size: 9pt;
            font-weight: bold;
            color: #fff;
            background: #1a1a2e;
            padding: 5px 8px;
            margin: 20px 0 0 0;
        }

        /* ── Line items table ────────────────────────────────────── */
        .lines-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
        }
        .lines-table th {
            background: #f3f4f6;
            color: #374151;
            font-size: 8pt;
            font-weight: bold;
            padding: 6px 8px;
            border-bottom: 1px solid #d1d5db;
            text-align: {{ $align }};
        }
        .lines-table td {
            padding: 6px 8px;
            font-size: 8.5pt;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        .lines-table tr:last-child td { border-bottom: none; }
        .lines-table .num { text-align: {{ $alignOpp }}; }
        .lines-table .desc { width: 38%; }

        /* ── Totals block ────────────────────────────────────────── */
        .totals-table { width: 45%; border-collapse: collapse; margin-{{ $align }}: auto; margin-top: 16px; }
        .totals-table td { padding: 4px 8px; font-size: 9pt; }
        .totals-table .t-label { color: #6b7280; text-align: {{ $align }}; }
        .totals-table .t-value { text-align: {{ $alignOpp }}; font-weight: bold; }
        .totals-grand { font-size: 11pt; color: #1a1a2e; border-top: 2px solid #1a1a2e; }

        /* ── Notes ───────────────────────────────────────────────── */
        .notes-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 10px 12px;
            font-size: 8.5pt;
            color: #374151;
            line-height: 1.6;
            margin-top: 8px;
            white-space: pre-wrap;
        }

        /* ── Signature block ─────────────────────────────────────── */
        .signature-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 14px 16px;
            margin-top: 20px;
        }
        .signature-title { font-size: 9pt; font-weight: bold; color: #1a1a2e; margin-bottom: 8px; }
        .signature-line {
            border-top: 1px solid #374151;
            margin-top: 40px;
            padding-top: 4px;
            font-size: 8pt;
            color: #555;
        }
        .signature-accepted {
            background: #d1fae5;
            border: 1px solid #6ee7b7;
            border-radius: 4px;
            padding: 10px 14px;
            margin-top: 20px;
        }
        .signature-accepted-title { font-size: 9pt; font-weight: bold; color: #065f46; }
        .signature-accepted-meta  { font-size: 8pt;  color: #064e3b; margin-top: 4px; line-height: 1.7; }

        /* ── Divider ─────────────────────────────────────────────── */
        .divider { border: none; border-top: 1px solid #e5e7eb; margin: 16px 0; }

        /* ── Watermark (draft/cancelled) ─────────────────────────── */
        @if(in_array($quotation->status, ['draft', 'cancelled']))
        .watermark {
            position: fixed;
            top: 38%;
            left: 50%;
            transform: translateX(-50%) rotate(-35deg);
            font-size: 60pt;
            font-weight: bold;
            color: rgba(200,200,200,0.18);
            z-index: -1;
            white-space: nowrap;
        }
        @endif
    </style>
</head>
<body>

    {{-- ── Watermark ──────────────────────────────────────────────── --}}
    @if(in_array($quotation->status, ['draft', 'cancelled']))
        <div class="watermark">
            {{ strtoupper($quotation->status === 'draft'
                ? ($locale === 'ar' ? 'مسودة' : 'DRAFT')
                : ($locale === 'ar' ? 'ملغي'  : 'CANCELLED')
            ) }}
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════ --}}
    {{-- HEADER                                                       --}}
    {{-- ════════════════════════════════════════════════════════════ --}}
    <div class="page-header">
        <div class="flex">
            {{-- Company info --}}
            <div class="col-left">
                @if($company->logo_path)
                    <img src="{{ storage_path('app/public/' . $company->logo_path) }}"
                         style="max-height:48px; max-width:160px; margin-bottom:6px;" alt="">
                @endif
                <div class="company-name">
                    {{ $locale === 'ar' ? ($company->name_ar ?: $company->name_en) : $company->name_en }}
                </div>
                <div class="company-meta">
                    @if($company->address)    {{ $company->address }}<br>@endif
                    @if($company->phone)      {{ $company->phone }}<br>@endif
                    @if($company->email)      {{ $company->email }}<br>@endif
                    @if($company->tax_number) {{ $locale === 'ar' ? 'الرقم الضريبي' : 'Tax No.' }}: {{ $company->tax_number }}@endif
                </div>
            </div>

            {{-- Quotation title --}}
            <div class="col-right">
                <div class="quotation-label">
                    {{ $locale === 'ar' ? 'عرض سعر' : 'Quotation' }}
                </div>
                <div class="quotation-number">
                    # {{ str_pad($quotation->id, 5, '0', STR_PAD_LEFT) }}
                    @if($quotation->revision_number > 1)
                        &nbsp;— {{ $locale === 'ar' ? 'مراجعة' : 'Rev.' }} {{ $quotation->revision_number }}
                    @endif
                </div>
                <div style="margin-top:6px;">
                    <span class="badge badge-{{ $quotation->status }}">
                        {{ match($quotation->status) {
                            'draft'     => $locale === 'ar' ? 'مسودة'    : 'Draft',
                            'sent'      => $locale === 'ar' ? 'مُرسل'    : 'Sent',
                            'accepted'  => $locale === 'ar' ? 'مقبول'    : 'Accepted',
                            'rejected'  => $locale === 'ar' ? 'مرفوض'    : 'Rejected',
                            'cancelled' => $locale === 'ar' ? 'ملغي'     : 'Cancelled',
                            'revised'   => $locale === 'ar' ? 'منقّح'    : 'Revised',
                            default     => $quotation->status,
                        } }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════ --}}
    {{-- INFO GRID — Issued to / From / Dates                        --}}
    {{-- ════════════════════════════════════════════════════════════ --}}
    <div class="flex">
        {{-- Left: Client info --}}
        <div class="col-left">
            <table class="info-table">
                <tr>
                    <td class="label">{{ $locale === 'ar' ? 'مُقدَّم إلى' : 'Issued To' }}</td>
                    <td class="value">
                        @php
                            $account = $quotation->deal->account ?? null;
                        @endphp
                        @if($account)
                            <strong>{{ $locale === 'ar' ? ($account->name_ar ?: $account->name_en) : $account->name_en }}</strong>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                @if($quotation->deal->primaryContact)
                <tr>
                    <td class="label">{{ $locale === 'ar' ? 'جهة الاتصال' : 'Contact' }}</td>
                    <td class="value">{{ $quotation->deal->primaryContact->displayName($locale) }}</td>
                </tr>
                @endif
                @if($account?->tax_number)
                <tr>
                    <td class="label">{{ $locale === 'ar' ? 'الرقم الضريبي' : 'Tax No.' }}</td>
                    <td class="value">{{ $account->tax_number }}</td>
                </tr>
                @endif
            </table>
        </div>

        {{-- Right: Quotation meta --}}
        <div class="col-right">
            <table class="info-table" style="text-align:{{ $alignOpp }};">
                <tr>
                    <td class="label">{{ $locale === 'ar' ? 'تاريخ الإصدار' : 'Issue Date' }}</td>
                    <td class="value">{{ $quotation->created_at->format('d M Y') }}</td>
                </tr>
                @if($quotation->valid_until)
                <tr>
                    <td class="label">{{ $locale === 'ar' ? 'صالح حتى' : 'Valid Until' }}</td>
                    <td class="value">{{ $quotation->valid_until->format('d M Y') }}</td>
                </tr>
                @endif
                <tr>
                    <td class="label">{{ $locale === 'ar' ? 'العملة' : 'Currency' }}</td>
                    <td class="value">{{ $quotation->currency->code ?? 'EGP' }}</td>
                </tr>
                <tr>
                    <td class="label">{{ $locale === 'ar' ? 'مندوب المبيعات' : 'Sales Rep' }}</td>
                    <td class="value">{{ $quotation->owner->name ?? '—' }}</td>
                </tr>
                @if($quotation->deal->title)
                <tr>
                    <td class="label">{{ $locale === 'ar' ? 'المشروع / الصفقة' : 'Deal / Project' }}</td>
                    <td class="value">{{ $quotation->deal->title }}</td>
                </tr>
                @endif
            </table>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════ --}}
    {{-- LINE ITEMS                                                   --}}
    {{-- ════════════════════════════════════════════════════════════ --}}
    <div class="section-heading">
        {{ $locale === 'ar' ? 'بنود عرض السعر' : 'Line Items' }}
    </div>

    <table class="lines-table">
        <thead>
            <tr>
                <th style="width:4%;">#</th>
                <th class="desc">{{ $locale === 'ar' ? 'الوصف' : 'Description' }}</th>
                <th style="width:10%;" class="num">{{ $locale === 'ar' ? 'الوحدة' : 'Unit' }}</th>
                <th style="width:9%;"  class="num">{{ $locale === 'ar' ? 'الكمية' : 'Qty' }}</th>
                <th style="width:13%;" class="num">{{ $locale === 'ar' ? 'سعر الوحدة' : 'Unit Price' }}</th>
                <th style="width:9%;"  class="num">{{ $locale === 'ar' ? 'خصم %' : 'Disc %' }}</th>
                <th style="width:9%;"  class="num">{{ $locale === 'ar' ? 'ضريبة %' : 'VAT %' }}</th>
                <th style="width:13%;" class="num">{{ $locale === 'ar' ? 'الإجمالي' : 'Total' }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->lines->sortBy('sort_order') as $i => $line)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>
                    @if($isRtl && $line->description_ar)
                        {{ $line->description_ar }}
                    @else
                        {{ $line->description }}
                    @endif
                </td>
                <td class="num">
                    {{ match($line->unit) {
                        'hour'  => $locale === 'ar' ? 'ساعة'  : 'Hour',
                        'day'   => $locale === 'ar' ? 'يوم'   : 'Day',
                        'unit'  => $locale === 'ar' ? 'وحدة'  : 'Unit',
                        'month' => $locale === 'ar' ? 'شهر'   : 'Month',
                        'fixed' => $locale === 'ar' ? 'مقطوع' : 'Fixed',
                        default => $line->unit,
                    } }}
                </td>
                <td class="num">{{ number_format($line->quantity, 2) }}</td>
                <td class="num">{{ number_format($line->unit_price, 2) }}</td>
                <td class="num">{{ $line->discount_percent ? number_format($line->discount_percent, 1).'%' : '—' }}</td>
                <td class="num">{{ $line->vat_rate ? number_format($line->vat_rate, 1).'%' : '—' }}</td>
                <td class="num" style="font-weight:bold;">{{ number_format($line->line_total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ════════════════════════════════════════════════════════════ --}}
    {{-- TOTALS                                                       --}}
    {{-- ════════════════════════════════════════════════════════════ --}}
    <table class="totals-table">
        <tr>
            <td class="t-label">{{ $locale === 'ar' ? 'المجموع قبل الضريبة' : 'Subtotal' }}</td>
            <td class="t-value">{{ number_format($quotation->subtotal, 2) }}</td>
        </tr>
        @if($quotation->total_discount > 0)
        <tr>
            <td class="t-label">{{ $locale === 'ar' ? 'إجمالي الخصم' : 'Total Discount' }}</td>
            <td class="t-value" style="color:#dc2626;">- {{ number_format($quotation->total_discount, 2) }}</td>
        </tr>
        @endif
        @if($quotation->total_vat > 0)
        <tr>
            <td class="t-label">{{ $locale === 'ar' ? 'ضريبة القيمة المضافة' : 'VAT' }}</td>
            <td class="t-value">{{ number_format($quotation->total_vat, 2) }}</td>
        </tr>
        @endif
        <tr class="totals-grand">
            <td class="t-label" style="font-size:11pt; font-weight:bold; padding-top:6px;">
                {{ $locale === 'ar' ? 'الإجمالي الكلي' : 'Grand Total' }}
            </td>
            <td class="t-value" style="font-size:11pt; padding-top:6px;">
                {{ $quotation->currency->symbol ?? 'EGP' }} {{ number_format($quotation->grand_total, 2) }}
            </td>
        </tr>
    </table>

    {{-- ════════════════════════════════════════════════════════════ --}}
    {{-- NOTES                                                        --}}
    {{-- ════════════════════════════════════════════════════════════ --}}
    @if($quotation->notes)
        <div class="section-heading" style="margin-top:24px;">
            {{ $locale === 'ar' ? 'ملاحظات' : 'Notes' }}
        </div>
        <div class="notes-box">{{ $quotation->notes }}</div>
    @endif

    {{-- ════════════════════════════════════════════════════════════ --}}
    {{-- SIGNATURE BLOCK                                              --}}
    {{-- ════════════════════════════════════════════════════════════ --}}
    @if($quotation->status === 'accepted' && $quotation->signed_at)
        {{-- Already signed — show confirmation block --}}
        <div class="signature-accepted">
            <div class="signature-accepted-title">
                ✓ {{ $locale === 'ar' ? 'تم القبول والتوقيع الإلكتروني' : 'Electronically Accepted & Signed' }}
            </div>
            <div class="signature-accepted-meta">
                {{ $locale === 'ar' ? 'بواسطة' : 'Signed by' }}: <strong>{{ $quotation->signed_by_name ?? '—' }}</strong><br>
                {{ $locale === 'ar' ? 'التاريخ' : 'Date' }}: {{ $quotation->signed_at->format('d M Y, H:i') }}<br>
                @if($quotation->signed_by_ip)
                    {{ $locale === 'ar' ? 'عنوان IP' : 'IP Address' }}: {{ $quotation->signed_by_ip }}
                @endif
            </div>
        </div>
    @else
        {{-- Pending signature --}}
        <div class="signature-box">
            <div class="signature-title">
                {{ $locale === 'ar' ? 'قبول واعتماد عرض السعر' : 'Acceptance & Authorisation' }}
            </div>
            <div class="flex" style="margin-top:8px;">
                <div class="col-left">
                    <div class="signature-line">
                        {{ $locale === 'ar' ? 'الاسم والتوقيع — العميل' : 'Name & Signature — Client' }}
                    </div>
                </div>
                <div class="col-right">
                    <div class="signature-line">
                        {{ $locale === 'ar' ? 'الاسم والتوقيع — ZAVERO' : 'Name & Signature — ZAVERO' }}
                    </div>
                </div>
            </div>
            <div style="margin-top:12px; font-size:8pt; color:#6b7280;">
                {{ $locale === 'ar'
                    ? 'بالتوقيع على هذا المستند، يوافق العميل على الشروط والأسعار المذكورة أعلاه.'
                    : 'By signing this document, the client agrees to the terms and pricing stated above.' }}
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════════════════════════════ --}}
    {{-- PAGE FOOTER                                                  --}}
    {{-- ════════════════════════════════════════════════════════════ --}}
    <div style="margin-top:30px;">
        <hr class="divider">
        <div class="page-footer">
            {{ $locale === 'ar' ? ($company->name_ar ?: $company->name_en) : $company->name_en }}
            @if($company->tax_number)
                &nbsp;|&nbsp; {{ $locale === 'ar' ? 'الرقم الضريبي' : 'Tax No.' }}: {{ $company->tax_number }}
            @endif
            &nbsp;|&nbsp; {{ $locale === 'ar' ? 'عرض السعر رقم' : 'Quotation' }} #{{ str_pad($quotation->id, 5, '0', STR_PAD_LEFT) }}
            &nbsp;|&nbsp; {{ $locale === 'ar' ? 'تم الإنشاء بواسطة ZAVERO ERP' : 'Generated by ZAVERO ERP' }}
        </div>
    </div>

</body>
</html>
