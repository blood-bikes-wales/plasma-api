<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edits the signed-in user's own profile settings. Any authenticated user may do so: the
 * target is always the caller, never an id from the request.
 */
class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'showCompletedJobs' => ['required', 'boolean'],
        ];
    }
}
