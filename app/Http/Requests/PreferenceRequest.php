<?php

namespace App\Http\Requests;

use App\Services\Settings\PreferenceService;
use Illuminate\Foundation\Http\FormRequest;

class PreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active ?? false;
    }

    public function rules(): array
    {
        return ['values' => ['required', 'array:theme,density,rows,reduced_motion,sound'], ...collect(app(PreferenceService::class)->rules())->mapWithKeys(fn ($rules, $key) => ['values.'.$key => $rules])->all()];
    }
}
