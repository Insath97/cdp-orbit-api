<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateEmployeeRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->route('employee');
        return [
            'f_name' => 'sometimes|string|max:255',
            'l_name' => 'sometimes|string|max:255',
            'full_name' => 'sometimes|string|max:255',
            'name_with_initials' => 'sometimes|string|max:255',
            'employee_code' => 'sometimes|string|max:50|unique:employees,employee_code,' . $id,
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'province_id' => 'nullable|exists:provinces,id',
            'region_id' => 'nullable|exists:regions,id',
            'zonal_id' => 'nullable|exists:zonals,id',
            'branch_id' => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'sometimes|exists:designations,id',
            'employee_type' => 'sometimes|in:permanent,contract,internship,probation',
            'id_type' => 'sometimes|in:nic,passport,driving_license,other',
            'id_number' => 'sometimes|string|max:50|unique:employees,id_number,' . $id,
            'date_of_birth' => 'sometimes|date',
            'email' => 'sometimes|email|max:255|unique:employees,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'address_line_1' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'sometimes|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'phone_primary' => 'sometimes|string|max:20',
            'phone_secondary' => 'nullable|string|max:20',
            'have_whatsapp' => 'sometimes|boolean',
            'whatsapp_number' => 'nullable|string|max:20',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'joined_at' => 'sometimes|date',
            'left_at' => 'nullable|date',
            'termination_reason' => 'nullable|string',
            'permanent_at' => 'nullable|date',
            'employment_status' => 'sometimes|in:active,inactive,terminated',
            'basic_salary' => 'sometimes|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ];
    }

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
