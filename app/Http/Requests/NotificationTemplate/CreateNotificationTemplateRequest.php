<?php
// app/Http/Requests/NotificationTemplate/CreateNotificationTemplateRequest.php

namespace App\Http\Requests\NotificationTemplate;

use Illuminate\Foundation\Http\FormRequest;

class CreateNotificationTemplateRequest extends FormRequest
{


    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:notification_templates,code'],
            'channel' => ['required', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
