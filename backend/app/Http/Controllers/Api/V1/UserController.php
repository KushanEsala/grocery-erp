<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends BaseController
{
    public function index()
    {
        return $this->paginatedResponse(
            User::with('role')
                ->where('BC', auth()->user()->BC)
                ->orderBy('username')
                ->paginate(50),
            'Users retrieved successfully.'
        );
    }

    public function show($id)
    {
        $user = $this->branchUser($id);

        return $this->successResponse(
            $user->load('role'),
            'User retrieved successfully.'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],
            'email' => ['required', 'email', 'max:100', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $validated['email'] = mb_strtolower(trim($validated['email']));
        $validated['BC'] = auth()->user()->BC;

        return $this->successResponse(
            User::create($validated)->load('role'),
            'User created successfully.',
            201
        );
    }

    public function update(Request $request, $id)
    {
        $user = $this->branchUser($id);
        $validated = $request->validate([
            'username' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'email' => [
                'sometimes',
                'email',
                'max:100',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role_id' => ['sometimes', 'exists:roles,id'],
        ]);

        if (isset($validated['email'])) {
            $validated['email'] = mb_strtolower(trim($validated['email']));
        }

        if (isset($validated['role_id']) && (int) $validated['role_id'] !== (int) $user->role_id) {
            if ($user->is(auth()->user())) {
                return $this->errorResponse('You cannot change your own role while signed in.', 422);
            }
            $newRole = Role::findOrFail($validated['role_id']);
            if ($user->isSuperAdmin() && $newRole->name !== Role::SUPER_ADMIN && $this->isLastSuperAdmin($user)) {
                return $this->errorResponse('Assign another Super Admin before changing this account role.', 422);
            }
        }

        $user->update($validated);

        return $this->successResponse(
            $user->load('role'),
            'User updated successfully.'
        );
    }

    public function destroy($id)
    {
        $user = $this->branchUser($id);

        if ($user->is(auth()->user())) {
            return $this->errorResponse('You cannot delete your own account.', 422);
        }

        if ($user->isSuperAdmin() && $this->isLastSuperAdmin($user)) {
            return $this->errorResponse('Assign another Super Admin before deleting this account.', 422);
        }

        if (DB::table('cashier_shifts')->where('cashier_id', $user->id)->where('status', 'open')->exists()) {
            return $this->errorResponse('Close this user’s open cashier shift before deleting the account.', 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return $this->successResponse(null, 'User account deleted. Historical transactions were preserved.');
    }

    private function branchUser($id): User
    {
        return User::whereKey($id)
            ->where('BC', auth()->user()->BC)
            ->firstOrFail();
    }

    private function isLastSuperAdmin(User $user): bool
    {
        return User::where('role_id', $user->role_id)->whereKeyNot($user->id)->doesntExist();
    }
}
