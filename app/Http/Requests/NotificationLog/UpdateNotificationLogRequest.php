<?php
// app/Http/Requests/NotificationLog/UpdateNotificationLogRequest.php

namespace App\Http\Requests\NotificationLog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationLogRequest extends FormRequest
{


    public function rules()
    {
        return [
            'user_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'template_id' => ['sometimes', 'required', 'exists:notification_templates,id'],
            'content' => ['sometimes', 'required', 'string'],
            'channel' => ['sometimes', 'required', 'string', 'max:50'],
            'sent_at' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'string', 'max:50'],
        ];
    }
}
