<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReplaceReaderFavoritesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'sport_ids' => ['present', 'array', 'list', 'max:30'],
            'sport_ids.*' => ['required', 'integer', 'distinct', 'exists:sports,id'],
            'team_ids' => ['present', 'array', 'list', 'max:100'],
            'team_ids.*' => ['required', 'integer', 'distinct', 'exists:teams,id'],
            'user_id' => ['missing'],
        ];
    }
}
