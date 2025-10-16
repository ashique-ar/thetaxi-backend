<?php
// app/Http/Requests/NotificationLog/CreateNotificationLogRequest.php

namespace App\Http\Requests\NotificationLog;

use Illuminate\Foundation\Http\FormRequest;

class CreateNotificationLogRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_id' => ['nullable', 'exists:users,id'],
            'template_id' => ['required', 'exists:notification_templates,id'],
            'content' => ['required', 'string'],
            'channel' => ['required', 'string', 'max:50'],
            'sent_at' => ['required', 'date'],
            'status' => ['required', 'string', 'max:50'],
        ];
    }
}
