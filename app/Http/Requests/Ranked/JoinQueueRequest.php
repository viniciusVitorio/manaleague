<?php

namespace App\Http\Requests\Ranked;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JoinQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(config('ranked.formats'))],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'session_note' => ['nullable', 'string', 'max:120'],
            'available' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return ['available.accepted' => 'Confirme que pode jogar presencialmente agora.'];
    }
}
