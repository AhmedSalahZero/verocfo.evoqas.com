<?php

namespace App\Http\Requests;

// ══════════════════════════════════════════════════════════════════
//  UserRequest
//
//  Validates input for creating and updating a User.
//  Used by UserController@store and UserController@update.
//
//  Authorization:
//    - Super Admin (while impersonating) can create/edit users
//    - Company Admin can create/edit users in their own company
//    - Regular users cannot create or edit other users
//
//  Password rules:
//    - Required on create, optional on edit
//    - Minimum 8 characters
//    - Must contain uppercase, lowercase, number, special character
//    - Must be confirmed (password_confirmation field)
//
//  Role restriction:
//    - Only company_admin and user roles can be assigned here
//    - super_admin role can never be assigned via this form
//
//  @layer    FormRequest
//  @depends  App\Models\User
//  @used-by  App\Http\Controllers\UserController
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user?->isSuperAdmin() || $user?->isCompanyAdmin();
    }

    public function rules(): array
    {
        $isEdit = $this->route('user') !== null;

        return [
            'name'         => ['required', 'string', 'max:255'],
            'name_ar'      => ['nullable', 'string', 'max:255'],
            'email'        => [
                'required', 'email', 'max:255',
                $isEdit
                    ? 'unique:users,email,' . $this->route('user')->id
                    : 'unique:users,email',
            ],
            'password'     => [
                $isEdit ? 'nullable' : 'required',
                'confirmed',
                Password::defaults(), // Audit L1: the one shared rule, set in AppServiceProvider
            ],
            'role'         => ['required', 'string', 'in:company_admin,user'],
            'job_title_en' => ['nullable', 'string', 'max:255'],
            'job_title_ar' => ['nullable', 'string', 'max:255'],
            'job_level'    => ['nullable', 'string', 'in:shareholder,director,manager,senior,junior,staff'],
            'lang_pref'    => ['required', 'string', 'in:en,ar'],
            'timezone'     => ['nullable', 'string', 'max:50'],
            'is_active'    => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name'      => 'Full Name',
            'email'     => 'Email Address',
            'password'  => 'Password',
            'role'      => 'Role',
            'job_level' => 'Job Level',
            'lang_pref' => 'Language Preference',
        ];
    }

    public function messages(): array
    {
        return [
            'password.min'    => 'Password must be at least 8 characters.',
            'email.unique'    => 'This email address is already in use.',
        ];
    }
}
