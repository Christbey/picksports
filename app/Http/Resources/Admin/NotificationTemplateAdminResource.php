<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\NotificationTemplate */
class NotificationTemplateAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'subject' => $this->subject,
            'email_body' => $this->email_body,
            'sms_body' => $this->sms_body,
            'push_title' => $this->push_title,
            'push_body' => $this->push_body,
            'variables' => $this->variables,
            'active' => $this->active,
        ];
    }
}
