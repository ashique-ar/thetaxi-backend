<?php
// app/Http/Requests/NotificationTemplate/UpdateNotificationTemplateRequest.php

namespace App\Http\Requests\NotificationTemplate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationTemplateRequest extends FormRequest
{


    public function rules()
    {
        $id = $this->route('notification_template')->id;
        return [
            'code' => ["sometimes", "required", "string", "max:50", "unique:notification_templates,code,{$id}"],
            'channel' => ['sometimes', 'required', 'string', 'max:50'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
