<?php

namespace App\Http\Requests\Ranked;

use Illuminate\Foundation\Http\FormRequest;

class SubmitRankedResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'score_one' => ['required', 'integer', 'min:0', 'max:3'],
            'score_two' => ['required', 'integer', 'min:0', 'max:3'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
