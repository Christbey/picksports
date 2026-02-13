<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'subject',
        'email_body',
        'sms_body',
        'push_title',
        'push_body',
        'variables',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'active' => 'boolean',
        ];
    }

    public function renderSubject(array $data): string
    {
        return $this->replaceVariables($this->subject ?? '', $data);
    }

    public function renderEmailBody(array $data): string
    {
        return $this->replaceVariables($this->email_body ?? '', $data);
    }

    public function renderSmsBody(array $data): string
    {
        return $this->replaceVariables($this->sms_body ?? '', $data);
    }

    public function renderPushTitle(array $data): string
    {
        return $this->replaceVariables($this->push_title ?? '', $data);
    }

    public function renderPushBody(array $data): string
    {
        return $this->replaceVariables($this->push_body ?? '', $data);
    }

    protected function replaceVariables(string $template, array $data): string
    {
        // Flatten data if it's in nested format
        $flatData = \App\Services\NotificationVariableRegistry::flattenData($data);

        $result = $template;

        foreach ($flatData as $key => $value) {
            $result = str_replace('{'.$key.'}', (string) $value, $result);
        }

        return $result;
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByName($query, string $name)
    {
        return $query->where('name', $name);
    }
}
