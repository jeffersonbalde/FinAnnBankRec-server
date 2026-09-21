<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $assignableRoles = array_values(array_filter(
            UserRole::values(),
            fn (string $role): bool => $role !== UserRole::Admin->value,
        ));

        $request->validate([
            'role' => ['sometimes', 'nullable', 'string', Rule::in($assignableRoles)],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->where('role', '!=', UserRole::Admin->value)
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->paginate(min($request->integer('per_page', 10), 100));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['avatar', 'remove_avatar', 'password_confirmation']);
        $data['avatar_path'] = $this->storeAvatar($request->file('avatar'));

        $user = User::create($data);

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        $this->guardAdminAccount($user);

        return UserResource::make($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->guardAdminAccount($user);

        $data = $request->safe()->except(['avatar', 'remove_avatar', 'password_confirmation']);

        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }

        $this->guardSelfLockout(
            $request,
            $user,
            $data['is_active'] ?? $user->is_active,
            $data['role'] ?? $user->role->value,
        );

        if ($request->boolean('remove_avatar') && ! $request->hasFile('avatar')) {
            $this->deleteAvatar($user->avatar_path);
            $data['avatar_path'] = null;
        }

        if ($request->hasFile('avatar')) {
            $this->deleteAvatar($user->avatar_path);
            $data['avatar_path'] = $this->storeAvatar($request->file('avatar'));
        }

        $user->update($data);

        return UserResource::make($user->fresh());
    }

    public function toggleActive(Request $request, User $user): UserResource
    {
        $this->guardAdminAccount($user);
        $this->guardSelfLockout($request, $user, ! $user->is_active, $user->role->value);

        $user->update(['is_active' => ! $user->is_active]);

        return UserResource::make($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->guardAdminAccount($user);

        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => ['You cannot delete your own account.']]);
        }

        $this->deleteAvatar($user->avatar_path);
        $user->delete();

        return response()->json(null, 204);
    }

    private function storeAvatar(?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        return $file->store('avatars', 'public');
    }

    private function deleteAvatar(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * The single system administrator is not listed or managed on the Users page.
     * Password changes go through POST /me/password instead.
     */
    private function guardAdminAccount(User $user): void
    {
        if ($user->role === UserRole::Admin) {
            throw ValidationException::withMessages([
                'user' => ['The system administrator account cannot be managed from Users. Change the password from your profile menu.'],
            ]);
        }
    }

    /**
     * Stop an admin from removing their own access (deactivating or demoting themselves).
     */
    private function guardSelfLockout(Request $request, User $user, bool $willBeActive, string $willBeRole): void
    {
        if (! $request->user()->is($user)) {
            return;
        }

        if (! $willBeActive) {
            throw ValidationException::withMessages(['is_active' => ['You cannot deactivate your own account.']]);
        }

        if ($user->role === UserRole::Admin && $willBeRole !== UserRole::Admin->value) {
            throw ValidationException::withMessages(['role' => ['You cannot remove the administrator role from your own account.']]);
        }
    }
}
