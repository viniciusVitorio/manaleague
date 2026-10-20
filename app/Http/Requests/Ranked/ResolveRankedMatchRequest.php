<?php

namespace App\Http\Requests\Ranked;

use Illuminate\Foundation\Http\FormRequest;

class ResolveRankedMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('resolve', $this->route('match')) ?? false;
    }

    public function rules(): array
    {
        return [
            'score_one' => ['required', 'integer', 'min:0', 'max:3'],
            'score_two' => ['required', 'integer', 'min:0', 'max:3'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
