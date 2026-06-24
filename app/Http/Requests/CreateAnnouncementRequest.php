<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class CreateAnnouncementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'target_type' => 'required|in:all,users,customers,country,province,zonal,region,branch,department,group,user',
            'target_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    $targetType = $this->input('target_type');
                    if ($targetType && ! in_array($targetType, ['all', 'users', 'customers'])) {
                        if (is_null($value)) {
                            $fail('The target_id field is required when target_type is not "all", "users" or "customers".');

                            return;
                        }
                        $table = match ($targetType) {
                            'department' => 'departments',
                            'branch' => 'branches',
                            'group' => 'groups',
                            'user' => 'users',
                            'country' => 'countries',
                            'province' => 'provinces',
                            'zonal' => 'zonals',
                            'region' => 'regions',
                            default => null,
                        };
                        if ($table && ! DB::table($table)->where('id', $value)->exists()) {
                            $fail('The selected target_id is invalid for the target_type "'.$targetType.'".');
                        }
                    }
                },
            ],
            'is_active' => 'sometimes|boolean',
            'sms' => 'sometimes|boolean',
            'sms_message' => 'nullable|string',
            'sms_template_id' => 'nullable|integer|exists:sms_templates,id',
        ];
    }

    /**
     * Handle failed validation and return a JSON error response.
     */
    protected function failedValidation(Validator $validator)
    {
        $errorMessages = $validator->errors();

        $fieldErrors = collect($errorMessages->getMessages())->map(function ($messages, $field) {
            return [
                'field' => $field,
                'messages' => $messages,
            ];
        })->values();

        $message = $fieldErrors->count() > 1
            ? 'There are multiple validation errors. Please review the form and correct the issues.'
            : 'There is an issue with the input for '.$fieldErrors->first()['field'].'.';

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
