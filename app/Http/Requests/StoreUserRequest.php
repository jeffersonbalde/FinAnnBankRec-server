<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
        $userId = $this->route('user')?->id;

        // Administrator is a single system account — not creatable or assignable here.
        $assignableRoles = array_values(array_filter(
            UserRole::values(),
            fn (string $role): bool => $role !== UserRole::Admin->value,
        ));

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role' => ['required', Rule::in($assignableRoles)],
            'designation' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'password' => [$userId ? 'nullable' : 'required', 'confirmed', Password::min(8)],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }
}
