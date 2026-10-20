<?php

namespace App\Http\Requests\Ranked;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'formats' => ['required', 'array', 'min:1'],
            'formats.*' => [Rule::in(config('ranked.formats'))],
            'scope' => ['required', Rule::in(['global', 'club'])],
            'club_id' => ['nullable', 'required_if:scope,club', 'integer', 'exists:clubs,id'],
            'k_factor' => ['required', 'integer', 'min:8', 'max:64'],
            'allow_draws' => ['sometimes', 'boolean'],
            'pair_limit_24h' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }
}
