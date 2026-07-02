<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $id = $this->route('user');
        $userObj = \App\Models\User::find($id);
        $employeeId = $userObj?->employee_id ?? 'NULL';

        return [
            'name' => 'sometimes|string|max:255',
            'username' => 'sometimes|string|max:255|unique:users,username,' . $id,
            'email' => 'nullable|email|max:255|unique:users,email,' . $id . '|unique:employees,email,' . $employeeId,
            'password' => 'sometimes|string|min:8',
            'user_type' => 'sometimes|in:admin,staff',
            'role' => 'sometimes|string|exists:roles,name',

            // Staff specific validation (embedded employee details)
            'f_name' => 'sometimes|string|max:255',
            'l_name' => 'sometimes|string|max:255',
            'employee_code' => 'sometimes|string|unique:employees,employee_code,' . $employeeId,
            'id_number' => 'sometimes|nullable|string|unique:employees,id_number,' . $employeeId,
            'phone' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'zonal_id' => 'nullable|exists:zonals,id',
            'region_id' => 'nullable|exists:regions,id',
            'province_id' => 'nullable|exists:provinces,id',
            'designation_id' => 'nullable|exists:designations,id',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'department_id' => 'nullable|exists:departments,id',
            'employee_type' => 'sometimes|in:permanent,contract,internship,probation',
            'id_type' => 'sometimes|in:nic,passport,driving_license,other',
            'date_of_birth' => 'sometimes|nullable|date',
            'address_line_1' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'country' => 'nullable|string',
            'postal_code' => 'nullable|string',
            'phone_primary' => 'sometimes|nullable|string',
            'phone_secondary' => 'nullable|string',
            'have_whatsapp' => 'sometimes|boolean',
            'whatsapp_number' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'name_with_initials' => 'nullable|string',

            'is_active' => 'sometimes|boolean',
            'can_login' => 'sometimes|boolean',
        ];
    }

    public function bodyParameters()
    {
        return [];
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
