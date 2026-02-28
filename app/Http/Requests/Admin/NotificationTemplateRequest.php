<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $templateId = $this->route('notification_template');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('notification_templates', 'name')->ignore($templateId),
            ],
            'description' => ['nullable', 'string'],
            'subject' => ['nullable', 'string', 'max:255'],
            'email_body' => ['nullable', 'string'],
            'sms_body' => ['nullable', 'string', 'max:160'],
            'push_title' => ['nullable', 'string', 'max:255'],
            'push_body' => ['nullable', 'string', 'max:255'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['string'],
            'active' => ['boolean'],
        ];
    }
}
