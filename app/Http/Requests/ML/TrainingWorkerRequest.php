<?php

namespace App\Http\Requests\ML;

use Illuminate\Foundation\Http\FormRequest;

class TrainingWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return [
            'owner' => $this->header('X-Training-Owner'),
            'path' => $this->header('X-Artifact-Path'),
            'sha256' => $this->header('X-Content-SHA256'),
            'length' => $this->header('Content-Length'),
            'result_hash' => $this->header('X-Result-SHA256'),
        ];
    }

    public function rules(): array
    {
        $rules = ['owner' => ['required', 'uuid']];
        if ($this->routeIs('ml-worker.upload')) {
            $rules += [
                'path' => ['required', 'string', 'max:191', 'regex:~\A(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*|(?:models|calibrators|explanations|tuning)/[A-Za-z0-9_-][A-Za-z0-9_.-]*)\z~'],
                'sha256' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
                'length' => ['required', 'integer', 'min:0', 'max:'.config('ml.remote.maximum_file_bytes')],
            ];
        }
        if ($this->routeIs('ml-worker.complete')) {
            $rules['result_hash'] = ['required', 'regex:/\A[a-f0-9]{64}\z/'];
        }

        return $rules;
    }
}
