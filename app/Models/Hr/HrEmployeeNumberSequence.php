<?php

namespace App\Models\Hr;

use App\Models\BaseModel;

/**
 * Per-company employee-number *allocation* counter: one row per company
 * holds the template/prefix/suffix/padding and the next numeric value to
 * hand out. {@see \App\Services\Hr\EmployeeNumberAllocator} locks this row
 * and increments `next_value` to mint a brand-new canonical employee number
 * when a Staff record has none yet.
 *
 * This is forward-looking allocation state only — it does not record which
 * numbers have already been issued to whom. For that, and for every
 * historical/manual employee number a Staff member has ever held, see
 * {@see HrEmployeeNumberAlias}, which is also what the allocator checks
 * against to avoid recycling a formerly-used number.
 */
class HrEmployeeNumberSequence extends BaseModel
{
    protected $fillable = ['company_id', 'template', 'prefix', 'suffix', 'start_value', 'next_value', 'padding', 'status', 'version', 'updated_user_id'];

    protected $casts = ['start_value' => 'integer', 'next_value' => 'integer', 'padding' => 'integer', 'version' => 'integer'];
}
