<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CompanyHoliday   (audit M-35)
//
//  One public / company holiday date. Leave types that skip non-working
//  days do not count these dates (see LeaveRequestService::calculateDays).
//
//  @layer    Model
//  @depends  BelongsToCompany
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;

class CompanyHoliday extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = ['company_id', 'holiday_date', 'name'];

    protected $casts = ['holiday_date' => 'date'];
}
