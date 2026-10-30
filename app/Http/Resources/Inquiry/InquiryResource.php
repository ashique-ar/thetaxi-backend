<?php
// app/Http/Resources/Inquiry/InquiryResource.php

namespace App\Http\Resources\Inquiry;

use App\Models\InquiryForm;
use Illuminate\Http\Resources\Json\JsonResource;

class InquiryResource extends JsonResource
{
    public function toArray($request)
    {
        $payload = $this->payload ?? [];
        $cms = $this->cmsContent;
        $legacy = $this->inquiryServicePage;
        $formId = $payload['form_id'] ?? null;
        $formName = $payload['form_name'] ?? ($formId ? InquiryForm::withInactive()->withTrashed()->find($formId)?->name : null);
        $serviceSlug = $cms?->slug ?? $legacy?->slug ?? ($payload['service_slug'] ?? null);
        $assignedStaff = $this->assignedUser?->staff;
        $companyId = $this->company_id ?: \App\Services\CompanyIdScope::defaultCompanyId();
        $assignedLabel = $this->assigned_to && $companyId && \App\Services\CompanyIdScope::sameCompanyId((string) ($assignedStaff?->company_id), (string) $companyId)
            ? trim(($this->assignedUser->first_name ?? '') . ' ' . ($this->assignedUser->last_name ?? ''))
            : null;
        $responseAuthor = $this->relationLoaded('firstResponseSavedBy') ? $this->firstResponseSavedBy : null;

        return [
            'id'           => $this->id,
            'company_id' => $companyId,
            $this->mergeWhen(array_key_exists('linked_opportunity_id', $this->resource->getAttributes()), [
                'linked_opportunity_id' => $this->linked_opportunity_id,
            ]),
            'inquiry_number' => $this->inquiry_number,
            'reference_number' => $this->inquiry_number,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'customer_name' => $this->name,
            'customer_email' => $this->email,
            'customer_phone' => $this->phone,
            'inquiry_type' => $this->inquiry_type,
            'source' => $this->source,
            'assigned_to' => $this->assigned_to,
            'assigned_label' => $this->assigned_to ? ($assignedLabel ?: 'Unavailable assignee') : 'Unassigned',
            'cms_content_id' => $this->cms_content_id,
            'inquiry_service_page_id' => $this->inquiry_service_page_id,
            'service_title' => $cms?->title ?? $legacy?->name ?? ($payload['service_title'] ?? null),
            'service_slug' => $serviceSlug,
            'service_url' => $serviceSlug ? route('inquiry-services.show', $serviceSlug) : null,
            'form_id' => $formId,
            'form_name' => $formName,
            'form_answers' => $payload['form'] ?? [],
            'attachments' => $payload['attachments'] ?? [],
            'source_url' => $payload['meta']['source_url'] ?? null,
            'referrer' => $payload['meta']['referrer'] ?? null,
            'utm' => $payload['meta']['utm'] ?? [],
            'notification_results' => $payload['meta']['notification_results'] ?? [],
            'customer_id'  => $this->customer_id,
            'booking_id' => $this->booking_id,
            'booking_number' => $this->whenLoaded('booking', fn () => $this->booking?->booking_number),
            'subject'      => $this->subject,
            'message'      => $this->message,
            'status'       => $this->status,
            'priority'     => $this->priority,
            'response'     => $this->response,
            'response_delivery_status' => $this->getAttribute('email_response_status')
                ?? ($this->response ? 'saved_not_sent' : 'not_started'),
            'response_sent_at' => $this->when(array_key_exists('email_response_sent_at', $this->resource->getAttributes()), $this->email_response_sent_at),
            'email_response_idempotency_key' => $this->when(array_key_exists('email_response_idempotency_key', $this->resource->getAttributes()), $this->email_response_idempotency_key),
            'respond_io_reply_status' => $this->when(array_key_exists('respond_io_reply_status', $this->resource->getAttributes()), $this->respond_io_reply_status),
            'notes' => $this->notes,
            'responded_at' => $this->responded_at,
            'first_response_sent_at' => $this->first_response_sent_at,
            'first_response_due_at' => $this->first_response_due_at,
            'first_response_saved_at' => $this->first_response_saved_at,
            'first_response_saved_by_user_id' => $this->first_response_saved_by_user_id,
            'first_response_saved_by' => $responseAuthor ? trim(($responseAuthor->first_name ?? '') . ' ' . ($responseAuthor->last_name ?? '')) : null,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
