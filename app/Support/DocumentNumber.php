<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  DocumentNumber
//
//  Hands out the next document code, e.g. CI/2026/10/00001.
//
//  Old way: count the documents typed this month + 1. Problems:
//   - two people saving at the same moment got the same number;
//   - deleting a document made the next number repeat;
//   - the month followed the day the record was typed, not the
//     document's own date.
//  New way: a counter row per company + document type + month in
//  document_sequences. The counter is raised in one atomic database
//  step, so two callers can never receive the same number. The month
//  comes from the document date. The first time a month is used, the
//  counter starts from the highest number already stored, so old
//  documents are never clashed with. As a last safety net the final
//  code is checked against the table and skipped if somehow taken.
//
//  Call it inside the same DB transaction that saves the document.
//
//  @layer    Support
//  @depends  document_sequences
// ══════════════════════════════════════════════════════════════════
class DocumentNumber
{
    /**
     * Standard code: PREFIX/YYYY/MM/NNNNN (width digits).
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    public static function code(
        int $companyId,
        string $model,
        string $column,
        string $prefix,
        Carbon|string|null $date = null,
        int $width = 5,
    ): string {
        $d     = $date ? Carbon::parse($date) : now();
        $year  = $d->format('Y');
        $month = $d->format('m');
        $like  = $prefix.'/'.$year.'/'.$month.'/%';

        $build = fn (int $n) => $prefix.'/'.$year.'/'.$month.'/'.str_pad((string) $n, $width, '0', STR_PAD_LEFT);
        $n     = self::nextNumber($companyId, $model, $column, $prefix, $year.$month, $like, $build);

        return $build($n);
    }

    /**
     * Next number only, for codes with a custom shape.
     * $likePattern finds the existing codes of this month (for seeding).
     * $format (optional) turns a number into the full code so the
     * safety check can confirm the code is unused. $separator is the
     * character before the final number ('/' or '-').
     */
    public static function nextNumber(
        int $companyId,
        string $model,
        string $column,
        string $docKey,
        string $period,
        string $likePattern,
        ?callable $format = null,
        string $separator = '/',
    ): int {
        $table = (new $model)->getTable();

        // Make sure the counter row exists, starting at the highest stored number.
        $max = (int) DB::table($table)
            ->where('company_id', $companyId)
            ->where($column, 'like', $likePattern)
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX($column, '$separator', -1) AS UNSIGNED)) AS m")
            ->value('m');

        DB::table('document_sequences')->insertOrIgnore([
            'company_id'  => $companyId,
            'doc_key'     => $docKey,
            'period'      => $period,
            'last_number' => $max,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        for ($i = 0; $i < 50; $i++) {
            DB::update(
                'UPDATE document_sequences SET last_number = LAST_INSERT_ID(last_number + 1), updated_at = ?
                 WHERE company_id = ? AND doc_key = ? AND period = ?',
                [now(), $companyId, $docKey, $period]
            );
            $n = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

            if ($format === null) {
                return $n;
            }
            $taken = DB::table($table)->where('company_id', $companyId)->where($column, $format($n))->exists();
            if (! $taken) {
                return $n;
            }
        }

        throw new \RuntimeException('Could not allocate a document number — please try again.');
    }
}
