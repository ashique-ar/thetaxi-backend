<?php

namespace App\Services\Hr\Talent;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PerformanceManagementService
{
    public function approveCycle(string $id, string $actor): object
    {
        return DB::transaction(function () use ($id, $actor) {
            $cycle = DB::table('hr_review_cycles')->where('id', $id)->lockForUpdate()->first();
            abort_unless($cycle, 404);
            abort_unless($cycle->status === 'draft', 409, 'Only a draft cycle can be approved.');
            abort_if($cycle->created_by === $actor, 409, 'The cycle creator cannot approve the same cycle.');
            DB::table('hr_review_cycles')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(), 'updated_at' => now()]);
            return DB::table('hr_review_cycles')->find($id);
        });
    }

    public function approveTemplate(string $id, string $actor): object
    {
        return DB::transaction(function () use ($id, $actor) {
            $template = DB::table('hr_review_templates')->where('id', $id)->lockForUpdate()->first();
            abort_unless($template, 404);
            abort_unless($template->status === 'pending_approval', 409, 'Only a pending template can be approved.');
            abort_if($template->created_by === $actor, 409, 'The template creator cannot approve the same version.');
            DB::table('hr_review_templates')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(), 'updated_at' => now()]);
            return DB::table('hr_review_templates')->find($id);
        });
    }

    public function assignReview(array $data, string $actor): object
    {
        return DB::transaction(function () use ($data, $actor) {
            $cycle = DB::table('hr_review_cycles')->where('id', $data['cycle_id'])->where('company_id', $data['company_id'])->where('status', 'approved')->first();
            $template = DB::table('hr_review_templates')->where('id', $data['template_id'])->where('company_id', $data['company_id'])->where('status', 'approved')->first();
            $staff = Staff::query()->whereKey($data['staff_id'])->where('company_id', $data['company_id'])->first();
            abort_unless($cycle && $template && $staff, 422, 'Approved cycle, template, and in-entity staff are required.');
            $assignment = DB::table('hr_employment_assignments')->where('staff_id', $staff->id)->whereDate('effective_from', '<=', $cycle->period_end)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $cycle->period_start))->latest('effective_from')->first();
            abort_unless($assignment, 422, 'No effective employment assignment covers this review cycle.');
            $managerId = $data['manager_staff_id'] ?? $assignment->manager_staff_id;
            if ($managerId) abort_unless(Staff::query()->whereKey($managerId)->where('company_id', $data['company_id'])->exists(), 422, 'Manager is outside the legal entity.');
            $id = (string) Str::uuid();
            DB::table('hr_performance_reviews')->insert(['id' => $id, 'company_id' => $data['company_id'], 'cycle_id' => $cycle->id, 'template_id' => $template->id, 'staff_id' => $staff->id, 'manager_staff_id' => $managerId, 'status' => 'self_review', 'assignment_snapshot' => json_encode($assignment, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $this->event($id, 'assigned', null, 'self_review', ['cycle_id' => $cycle->id, 'template_id' => $template->id], $actor);
            return DB::table('hr_performance_reviews')->find($id);
        });
    }

    public function transition(string $reviewId, string $to, array $data, string $actor, ?string $actorStaffId): object
    {
        return DB::transaction(function () use ($reviewId, $to, $data, $actor, $actorStaffId) {
            $review = DB::table('hr_performance_reviews')->where('id', $reviewId)->lockForUpdate()->first();
            abort_unless($review, 404);
            $allowed = ['self_review' => ['manager_review'], 'manager_review' => ['calibration'], 'calibration' => ['finalized'], 'finalized' => ['acknowledged']];
            abort_unless(in_array($to, $allowed[$review->status] ?? [], true), 409, 'Invalid review transition.');
            if ($to === 'manager_review') abort_unless($actorStaffId === $review->staff_id, 403, 'Only the reviewed employee can submit the self-review stage.');
            if ($to === 'calibration') abort_unless($actorStaffId === $review->manager_staff_id, 403, 'Only the assigned manager can submit the manager-review stage.');
            if ($to === 'acknowledged') abort_unless($actorStaffId === $review->staff_id, 403, 'Only the reviewed employee can acknowledge the result.');
            if ($to === 'manager_review') abort_unless(DB::table('hr_review_responses')->where('review_id', $reviewId)->where('respondent_role', 'self')->where('status', 'submitted')->exists(), 422, 'Self review must be submitted first.');
            if ($to === 'calibration') abort_unless(DB::table('hr_review_responses')->where('review_id', $reviewId)->where('respondent_role', 'manager')->where('status', 'submitted')->exists(), 422, 'Manager review must be submitted first.');
            $updates = ['status' => $to, 'updated_at' => now()];
            if ($to === 'finalized') { abort_unless(isset($data['final_rating'], $data['calibration_reason']), 422, 'Final rating and calibration reason are required.'); $updates += ['final_rating' => $data['final_rating'], 'calibration_reason' => $data['calibration_reason']]; }
            if ($to === 'acknowledged') $updates['acknowledged_at'] = now();
            DB::table('hr_performance_reviews')->where('id', $reviewId)->update($updates);
            $this->event($reviewId, 'status_changed', $review->status, $to, $data, $actor);
            return DB::table('hr_performance_reviews')->find($reviewId);
        });
    }

    private function event(string $reviewId, string $type, ?string $from, ?string $to, array $evidence, string $actor): void
    {
        DB::table('hr_performance_review_events')->insert(['id' => (string) Str::uuid(), 'review_id' => $reviewId, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to, 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'actor_user_id' => $actor, 'occurred_at' => now()]);
    }
}
