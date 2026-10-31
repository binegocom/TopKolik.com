<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateReaderPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'preferred_locale' => ['sometimes', 'nullable', Rule::in(['de', 'fr', 'it', 'en'])],
            'personalized_news_enabled' => ['sometimes', 'required', 'boolean'],
            'analytics_consent' => ['sometimes', 'required', 'boolean'],
            // No reading history collector exists: do not imply that opting in enables it.
            'reading_history_enabled' => ['missing'],
            'user_id' => ['missing'],
            'email' => ['missing'],
        ];
    }
}
