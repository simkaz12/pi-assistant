<?php

namespace App\Modules\Oura\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class OuraOAuthCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['required', 'string', 'size:64'],
            'code' => ['required_without:error', 'string', 'max:4096'],
            'error' => ['sometimes', 'in:access_denied'],
            'scope' => ['sometimes', 'string', 'max:1024'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response('Invalid Oura authorization response.', 400));
    }
}
