<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  SalesChannelService
//
//  Business logic for Sales Channels — a small, deliberately
//  lightweight module (dropdown + quick-add only, no full CRUD;
//  see SalesChannelController for why). Split out of the controller
//  (July 2026 audit) — the duplicate-name check and the create call
//  were both happening directly in the controller, over both the
//  "no DB access in controllers" and 15-line-method rules.
//
//  @layer    Service
//  @depends  App\Models\SalesChannel
// ══════════════════════════════════════════════════════════════════

use App\Models\SalesChannel;
use Illuminate\Support\Collection;

class SalesChannelService
{
    public function forDropdown(int $companyId): Collection
    {
        return SalesChannel::forCompany($companyId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'is_system']);
    }

    /**
     * Quick-create a custom channel from the inline modal.
     * Throws \DomainException on a duplicate name within the company —
     * the controller turns that into the 422 JSON response.
     */
    public function quickCreate(int $companyId, array $data): SalesChannel
    {
        $duplicate = SalesChannel::forCompany($companyId)
            ->whereRaw('LOWER(name_en) = ?', [strtolower($data['name_en'])])
            ->exists();

        if ($duplicate) {
            throw new \DomainException('A sales channel with this name already exists.');
        }

        return SalesChannel::create([
            'company_id' => $companyId,
            'name_en'    => $data['name_en'],
            'name_ar'    => $data['name_ar'] ?? null,
            'is_system'  => false,
            'is_active'  => true,
            'sort_order' => 99,
        ]);
    }
}
