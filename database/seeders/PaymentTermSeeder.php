<?php

namespace Database\Seeders;

// ══════════════════════════════════════════════════════════════════
//  PaymentTermSeeder
//
//  Seeds 7 system-level payment terms available to all companies.
//  These terms have company_id = NULL and is_system = true.
//  They cannot be deleted by any user.
//
//  Terms seeded:
//    1. Cash          — 100% due immediately from Invoice Date
//    2. Net 30 Days   — 100% due 30 days from Invoice Date
//    3. Net 45 Days   — 100% due 45 days from Invoice Date
//    4. Net 60 Days   — 100% due 60 days from Invoice Date
//    5. Net 75 Days   — 100% due 75 days from Invoice Date
//    6. Net 90 Days   — 100% due 90 days from Invoice Date
//    7. Net 120 Days  — 100% due 120 days from Invoice Date
//
//  Seeder is idempotent — uses updateOrCreate on code
//  so it can be re-run safely without duplicating records.
//
//  @layer    Seeder
//  @depends  PaymentTerm, PaymentTermLine
// ══════════════════════════════════════════════════════════════════

use App\Models\PaymentTerm;
use App\Models\PaymentTermLine;
use Illuminate\Database\Seeder;

class PaymentTermSeeder extends Seeder
{
    public function run(): void
    {
        $terms = [
            [
                'code'    => 'CASH',
                'name_en' => 'Cash',
                'name_ar' => 'نقداً',
                'lines'   => [
                    ['percentage' => '100.00', 'due_days' => 0, 'reference_point' => 'invoice_date', 'label' => null, 'sort_order' => 1],
                ],
            ],
            [
                'code'    => 'NET30',
                'name_en' => 'Net 30 Days',
                'name_ar' => 'صافي 30 يوم',
                'lines'   => [
                    ['percentage' => '100.00', 'due_days' => 30, 'reference_point' => 'invoice_date', 'label' => null, 'sort_order' => 1],
                ],
            ],
            [
                'code'    => 'NET45',
                'name_en' => 'Net 45 Days',
                'name_ar' => 'صافي 45 يوم',
                'lines'   => [
                    ['percentage' => '100.00', 'due_days' => 45, 'reference_point' => 'invoice_date', 'label' => null, 'sort_order' => 1],
                ],
            ],
            [
                'code'    => 'NET60',
                'name_en' => 'Net 60 Days',
                'name_ar' => 'صافي 60 يوم',
                'lines'   => [
                    ['percentage' => '100.00', 'due_days' => 60, 'reference_point' => 'invoice_date', 'label' => null, 'sort_order' => 1],
                ],
            ],
            [
                'code'    => 'NET75',
                'name_en' => 'Net 75 Days',
                'name_ar' => 'صافي 75 يوم',
                'lines'   => [
                    ['percentage' => '100.00', 'due_days' => 75, 'reference_point' => 'invoice_date', 'label' => null, 'sort_order' => 1],
                ],
            ],
            [
                'code'    => 'NET90',
                'name_en' => 'Net 90 Days',
                'name_ar' => 'صافي 90 يوم',
                'lines'   => [
                    ['percentage' => '100.00', 'due_days' => 90, 'reference_point' => 'invoice_date', 'label' => null, 'sort_order' => 1],
                ],
            ],
            [
                'code'    => 'NET120',
                'name_en' => 'Net 120 Days',
                'name_ar' => 'صافي 120 يوم',
                'lines'   => [
                    ['percentage' => '100.00', 'due_days' => 120, 'reference_point' => 'invoice_date', 'label' => null, 'sort_order' => 1],
                ],
            ],
        ];

        foreach ($terms as $termData) {
            $lines = $termData['lines'];
            unset($termData['lines']);

            $term = PaymentTerm::updateOrCreate(
                ['code' => $termData['code'], 'company_id' => null],
                array_merge($termData, ['is_system' => true, 'is_active' => true])
            );

            // Re-sync lines — delete existing and re-insert
            // Safe because system terms have no user modifications
            $term->lines()->delete();

            foreach ($lines as $line) {
                PaymentTermLine::create(array_merge(
                    ['payment_term_id' => $term->id],
                    $line
                ));
            }
        }

        $this->command->info('PaymentTermSeeder: 7 system terms seeded.');
    }
}
