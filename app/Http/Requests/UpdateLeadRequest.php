<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateLeadRequest extends FormRequest
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
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_primary' => 'sometimes|required|string|max:50',
            'phone_secondary' => 'nullable|string|max:50',
            'have_whatsapp' => 'sometimes|boolean',
            'whatsapp_number' => 'nullable|string|max:50',
            'birthday' => 'nullable|date_format:Y-m-d',
            'id_type' => 'nullable|string|max:100',
            'id_number' => 'nullable|string|max:100',
            'preferred_language' => 'sometimes|in:english,sinhala,tamil',
            'company' => 'nullable|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'source' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
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
            : 'There is an issue with the input for ' . $fieldErrors->first()['field'] . '.';

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
