<?php
// app/Http/Resources/PhoneCallResource.php

namespace App\Http\Resources\PhoneCall;

use Illuminate\Support\Str;
use Illuminate\Http\Resources\Json\JsonResource;

class PhoneCallResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'customer_id' => $this->when($request->user()?->can('customers.view'), $this->customer_id),
            'booking_id'  => $this->when($request->user()?->can('bookings.view'), $this->booking_id),
            'inquiry_id'  => $this->when($request->user()?->can('inquiries.view'), $this->inquiry_id),
            'linked_opportunity_id' => $this->when(
                $request->user()?->can('sales.crm.view') && $this->relationLoaded('salesOpportunity'),
                $this->salesOpportunity?->id,
            ),
            'phone'       => $this->phone,
            'client_name' => $this->client_name,
            'summary'     => $this->summary,
            'call_time'   => $this->call_time,
            'external_provider' => $this->external_provider,
            'direction' => $this->direction,
            'call_status' => $this->call_status,
            'agent_extension' => $this->agent_extension,
            'agent_email' => $this->agent_email,
            'call_started_at' => $this->call_started_at,
            'call_ended_at' => $this->call_ended_at,
            'talk_duration_seconds' => $this->talk_duration_seconds,
            'follow_up_task_id' => $this->follow_up_task_id,
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_to' => $this->assignedTo?->full_name,
            'assignment_history' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn ($assignment) => [
                'from_user_id' => $assignment->from_user_id, 'from_name' => $assignment->fromUser?->full_name,
                'to_user_id' => $assignment->to_user_id, 'to_name' => $assignment->toUser?->full_name,
                'actor_user_id' => $assignment->actor_user_id, 'actor_name' => $assignment->actor?->full_name,
                'reason' => $assignment->reason, 'event_at' => $assignment->event_at,
            ])),
            'caller_name' => $this->client_name,
            'caller_phone' => $this->phone,
            'call_type' => Str::lower($this->direction ?? 'inbound'),
            'purpose' => 'inquiry',
            'duration_seconds' => $this->talk_duration_seconds ?? 0,
            'call_date' => $this->call_started_at ?? $this->call_time,
            'handled_by' => $this->agent_email ?? $this->agent_extension,
            'outcome' => in_array($this->call_status, ['Missed', 'Notanswered'], true) ? 'no_answer' : 'other',
            'follow_up_required' => in_array($this->call_status, ['Missed', 'Notanswered'], true) && ! $this->follow_up_task_id,
            'status' => in_array($this->call_status, ['Missed', 'Notanswered'], true) ? 'missed' : 'completed',
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
