<?php

namespace App\Services\Accounting\Concerns;

// ══════════════════════════════════════════════════════════════════
//  SplitsChequeBatch   (audit M-31)
//
//  A collection can be paid with SEVERAL post-dated cheques. The form
//  sends the first cheque as before (`cheque`) plus an optional list
//  of further ones (`cheque.extra`, each with its own number, due date
//  and amount). The first cheque takes whatever is left of the total.
//  The cheque amounts therefore always add up to the collection total
//  by construction; the EGP booked value is shared out in proportion,
//  the last cheque taking any rounding cent.
//
//  @layer    Service (trait)
//  @depends  bcmath
// ══════════════════════════════════════════════════════════════════

trait SplitsChequeBatch
{
    /**
     * @return array<int,array{cheque_number:string,due_date:string,drawee_bank_id:int,receiving_safe_account_id:?int,amount:string,amount_functional:string}>
     */
    protected function splitChequeBatch(array $chequeData, string $total, string $totalFunctional): array
    {
        $extras = array_values(array_filter($chequeData['extra'] ?? [], fn ($e) => ! empty($e['cheque_number'])));
        $extraSum = '0';
        foreach ($extras as $e) {
            $amt = (string) ($e['amount'] ?? 0);
            if (! is_numeric($amt) || bccomp($amt, '0', 2) <= 0) {
                throw new \DomainException("Cheque {$e['cheque_number']} needs an amount greater than zero.");
            }
            $extraSum = bcadd($extraSum, $amt, 2);
        }
        $firstAmount = bcsub($total, $extraSum, 2);
        if (bccomp($firstAmount, '0', 2) <= 0) {
            throw new \DomainException('The extra cheques add up to the whole collection amount or more — the first cheque would have nothing left.');
        }

        $rows = [[
            'cheque_number'             => $chequeData['cheque_number'],
            'due_date'                  => $chequeData['due_date'],
            'drawee_bank_id'            => $chequeData['drawee_bank_id'],
            'receiving_safe_account_id' => $chequeData['receiving_safe_account_id'] ?? null,
            'amount'                    => $firstAmount,
        ]];
        foreach ($extras as $e) {
            $rows[] = [
                'cheque_number'             => $e['cheque_number'],
                'due_date'                  => $e['due_date'],
                'drawee_bank_id'            => $e['drawee_bank_id'] ?? $chequeData['drawee_bank_id'],
                'receiving_safe_account_id' => $e['receiving_safe_account_id'] ?? ($chequeData['receiving_safe_account_id'] ?? null),
                'amount'                    => (string) $e['amount'],
            ];
        }

        $allocated = '0';
        $last = count($rows) - 1;
        foreach ($rows as $i => &$r) {
            if ($i === $last) {
                $r['amount_functional'] = bcsub($totalFunctional, $allocated, 2);
            } else {
                $r['amount_functional'] = bccomp($total, '0', 2) > 0
                    ? bcdiv(bcmul($totalFunctional, $r['amount'], 6), $total, 2)
                    : '0.00';
                $allocated = bcadd($allocated, $r['amount_functional'], 2);
            }
        }
        unset($r);

        return $rows;
    }
}
