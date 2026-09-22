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

        return [
            'id'           => $this->id,
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
            'subject'      => $this->subject,
            'message'      => $this->message,
            'status'       => $this->status,
            'priority'     => $this->priority,
            'response'     => $this->response,
            'notes' => $this->notes,
            'responded_at' => $this->responded_at,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
