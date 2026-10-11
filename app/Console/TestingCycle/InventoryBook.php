<?php

namespace App\Console\TestingCycle;

// ══════════════════════════════════════════════════════════════════
//  InventoryBook
//
//  The cycle's OWN running inventory, kept in parallel with the
//  application's and never read from it.
//
//  This is what makes the multi-year cycle a test rather than a
//  demonstration. Asking the application what the weighted average
//  is and then asserting the weighted average equals it proves
//  nothing. So the cycle keeps its own book: every receipt and issue
//  it performs is also applied here, using the rule as WRITTEN DOWN
//  rather than as implemented, and the two are compared after every
//  movement.
//
//  THE RULE, from StockLedgerService's own header, sourced there to
//  Application Scope §5.3:
//
//      New WAC = (current stock value + new receipt value)
//                ÷ (current stock qty + new receipt qty)
//
//  and its companion: only RECEIPTS move the average. An issue
//  relieves stock at whatever average already stands and leaves it
//  untouched.
//
//  ARITHMETIC. bcmath at the schema's own scales — value at 2
//  decimals, unit cost at 4, quantity at 4 — because "all arithmetic
//  in bcmath, never float on money" is itself one of this
//  repository's stated rules, and because a shadow book computed in
//  floating point would drift away from a correct application for
//  reasons that have nothing to do with the application.
//
//  Divergence between this book and the application is a FINDING.
//  It is never resolved by copying the application's answer back
//  into the book.
//
//  @layer    Console Support
// ══════════════════════════════════════════════════════════════════

class InventoryBook
{
    /** @var array<int, array{qty: string, value: string, wac: string, name: string}> */
    private array $products = [];

    /**
     * Where the stock physically is: [productId][locationId] => qty.
     *
     * Cost is pooled company-wide, but QUANTITY is not — a delivery
     * draws from one specific warehouse and will be refused if that
     * warehouse is short, even when the company as a whole holds
     * plenty. The cycle has to know this or it writes scenarios that
     * cannot happen.
     *
     * @var array<int, array<int, string>>
     */
    private array $locations = [];

    public function track(int $productId, string $name): void
    {
        $this->products[$productId] ??= [
            'qty'   => '0.0000',
            'value' => '0.00',
            'wac'   => '0.0000',
            'name'  => $name,
        ];
    }

    /**
     * A receipt — purchase, production output, adjustment in. The only
     * movement that changes the average.
     */
    public function receive(int $productId, string $quantity, string $unitCost, ?int $locationId = null): void
    {
        $this->track($productId, 'product #'.$productId);

        if ($locationId !== null) {
            $this->addAt($productId, $locationId, $quantity);
        }

        $receiptValue = $this->bcround(bcmul($quantity, $unitCost, 6), 2);

        $newQty   = bcadd($this->products[$productId]['qty'], $quantity, 4);
        $newValue = bcadd($this->products[$productId]['value'], $receiptValue, 2);

        $this->products[$productId]['qty']   = $newQty;
        $this->products[$productId]['value'] = $newValue;
        $this->products[$productId]['wac']   = bccomp($newQty, '0', 4) > 0
            ? bcdiv($newValue, $newQty, 4)
            : '0.0000';
    }

    /**
     * An issue — delivery, production consumption, adjustment out.
     * Relieves at the standing average and must NOT move it.
     *
     * Returns the value relieved, which is what the caller expects
     * the application to have posted to cost of sales or WIP.
     */
    public function issue(int $productId, string $quantity, ?int $locationId = null): string
    {
        $this->track($productId, 'product #'.$productId);

        if ($locationId !== null) {
            $this->addAt($productId, $locationId, bcmul($quantity, '-1', 4));
        }

        $wac         = $this->products[$productId]['wac'];
        $issueValue  = $this->bcround(bcmul($quantity, $wac, 6), 2);

        $newQty   = bcsub($this->products[$productId]['qty'], $quantity, 4);
        $newValue = bcsub($this->products[$productId]['value'], $issueValue, 2);

        // Mirrors the ledger's own guard: book value never goes
        // negative. If this clamp ever fires, the cycle's own book has
        // drifted and the comparison that follows will say so.
        if (bccomp($newValue, '0', 2) < 0) {
            $newValue = '0.00';
        }

        $this->products[$productId]['qty']   = $newQty;
        $this->products[$productId]['value'] = $newValue;
        // wac deliberately untouched — only receipts move it.

        return $issueValue;
    }

    /** What one issue WOULD cost, without performing it. */
    public function costOfIssuing(int $productId, string $quantity): string
    {
        return $this->bcround(bcmul($quantity, $this->wac($productId), 6), 2);
    }

    /**
     * Round half up at $scale, mirroring StockLedgerService::bcround().
     *
     * This book only means anything while it computes money the way
     * the engine does. bcmath truncates on its own, which is what the
     * engine used to do too (audit finding INV-005); since that was
     * fixed the engine rounds, so this must round with it or every
     * comparison drifts a cent per issue and reports the engine as
     * wrong for being right.
     */
    private function bcround(string $number, int $scale = 2): string
    {
        $halfUnit = '0.'.str_repeat('0', $scale).'5';

        return bccomp($number, '0', $scale + 10) >= 0
            ? bcadd($number, $halfUnit, $scale)
            : bcsub($number, $halfUnit, $scale);
    }

    public function quantity(int $productId): string
    {
        return $this->products[$productId]['qty'] ?? '0.0000';
    }

    public function value(int $productId): string
    {
        return $this->products[$productId]['value'] ?? '0.00';
    }

    public function wac(int $productId): string
    {
        return $this->products[$productId]['wac'] ?? '0.0000';
    }

    public function name(int $productId): string
    {
        return $this->products[$productId]['name'] ?? ('product #'.$productId);
    }

    public function rename(int $productId, string $name): void
    {
        $this->track($productId, $name);
        $this->products[$productId]['name'] = $name;
    }

    /**
     * Enough on hand to issue this much? Used to keep the story
     * honest — the cycle only writes scenarios a real company could
     * actually perform.
     *
     * With a location, it asks the harder and more realistic
     * question: is it there, in that warehouse?
     */
    public function canIssue(int $productId, string $quantity, ?int $locationId = null): bool
    {
        if ($locationId !== null) {
            return bccomp($this->quantityAt($productId, $locationId), $quantity, 4) >= 0;
        }

        return bccomp($this->quantity($productId), $quantity, 4) >= 0;
    }

    /** Moving between the company's own warehouses: quantity only. */
    public function transferBetween(int $productId, int $fromLocationId, int $toLocationId, string $quantity): void
    {
        $this->addAt($productId, $fromLocationId, bcmul($quantity, '-1', 4));
        $this->addAt($productId, $toLocationId, $quantity);
    }

    public function quantityAt(int $productId, int $locationId): string
    {
        return $this->locations[$productId][$locationId] ?? '0.0000';
    }

    private function addAt(int $productId, int $locationId, string $delta): void
    {
        $current = $this->locations[$productId][$locationId] ?? '0.0000';
        $this->locations[$productId][$locationId] = bcadd($current, $delta, 4);
    }

    /**
     * Force the book back into line with the application after a
     * divergence has already been RECORDED as a finding.
     *
     * This is not a way to make failures go away. Once the two ledgers
     * genuinely differ — because the application moved stock the cycle
     * did not, say — every subsequent comparison for that product
     * fails for the same historical reason, and one defect drowns out
     * everything the rest of the run might have found. Re-basing after
     * the finding is recorded keeps later months testing NEW movements
     * rather than re-reporting the same break.
     *
     * Callers must record the finding first and log the re-base.
     */
    public function resyncTo(int $productId, string $quantity, string $value, string $wac): void
    {
        $this->track($productId, 'product #'.$productId);

        $this->products[$productId]['qty']   = $quantity;
        $this->products[$productId]['value'] = $value;
        $this->products[$productId]['wac']   = $wac;
    }

    /** Force one location's quantity back into line, same reasoning. */
    public function resyncLocation(int $productId, int $locationId, string $quantity): void
    {
        $this->locations[$productId][$locationId] = $quantity;
    }

    /** @return array<int, int> every product this book is tracking */
    public function productIds(): array
    {
        return array_keys($this->products);
    }

    /** Total book value across everything — the reconciliation figure. */
    public function totalValue(): string
    {
        $total = '0.00';
        foreach ($this->products as $product) {
            $total = bcadd($total, $product['value'], 2);
        }

        return $total;
    }
}
