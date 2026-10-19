<?php

namespace App\Http\Requests;

use App\Services\Settings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the Settings form. Rules come from the Settings service so the
 * field definitions stay in one place.
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Single-user app behind the auth middleware.
        return true;
    }

    public function rules(): array
    {
        $rules = [];

        foreach (Settings::fields() as $key => $field) {
            // Keyed by the form field name, not the dotted config path —
            // see Settings::formKey().
            $rules[Settings::formKey($key)] = $field['rules'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return collect(Settings::fields())
            ->mapWithKeys(fn ($field, $key) => [Settings::formKey($key) => strtolower($field['label'])])
            ->all();
    }
}
