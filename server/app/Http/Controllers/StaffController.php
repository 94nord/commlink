<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\Staff;
use App\Models\User;
use App\Support\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $staff = $request->user()->corporation->staff()
            ->orderBy('name')
            ->get();

        return response()->json([
            'staff' => $staff->map(fn ($member) => $this->present($member)),
        ]);
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $corporation = $request->user()->corporation;

        $staff = $corporation->staff()->create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'is_admin' => $request->boolean('is_admin'),
        ]);

        EventRecorder::record($corporation, 'staff.created', subject: $staff);

        return response()->json(['staff' => $this->present($staff)], 201);
    }

    public function update(UpdateStaffRequest $request, int $staff): JsonResponse
    {
        $corporation = $request->user()->corporation;
        $staff = $corporation->staff()->findOrFail($staff);

        // The founder is always an admin — an unconditional guard, checked
        // before the "last admin with a login" one below since it applies
        // even when other admins exist.
        if ($staff->is_founder && ! $request->boolean('is_admin')) {
            throw ValidationException::withMessages([
                'is_admin' => __('staff.founder_is_always_admin'),
            ]);
        }

        // Never leave the corporation without an admin who can log in.
        if ($staff->is_admin && ! $request->boolean('is_admin') && $this->isLastAdminWithLogin($staff)) {
            throw ValidationException::withMessages([
                'is_admin' => __('staff.last_admin'),
            ]);
        }

        $staff->update([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'is_admin' => $request->boolean('is_admin'),
        ]);

        EventRecorder::record($corporation, 'staff.updated', subject: $staff);

        return response()->json(['staff' => $this->present($staff)]);
    }

    public function destroy(Request $request, int $staff): Response|JsonResponse
    {
        $corporation = $request->user()->corporation;
        $staff = $corporation->staff()->findOrFail($staff);

        // The founder can never be removed — unconditional, unlike the
        // guards below, which only stop the corporation from reaching zero
        // staff or zero logged-in admins.
        if ($staff->is_founder) {
            throw ValidationException::withMessages([
                'staff' => __('staff.cannot_delete_founder'),
            ]);
        }

        // S2: a corporation can never have zero staff.
        if ($corporation->staff()->count() <= 1) {
            throw ValidationException::withMessages([
                'staff' => __('staff.last_staff_member'),
            ]);
        }

        if ($staff->is_admin && $this->isLastAdminWithLogin($staff)) {
            throw ValidationException::withMessages([
                'staff' => __('staff.last_admin'),
            ]);
        }

        EventRecorder::record($corporation, 'staff.deleted', subject: $staff);

        // Remove the linked login too, so no orphaned account survives.
        $staff->user?->delete();
        $staff->delete();

        return response()->noContent();
    }

    /**
     * Provision (or re-send) a login: create the user if needed, then email a
     * set-password link via the standard password-reset flow.
     */
    public function invite(Request $request, int $staff): JsonResponse
    {
        $corporation = $request->user()->corporation;
        $staff = $corporation->staff()->findOrFail($staff);

        if ($staff->email === null) {
            throw ValidationException::withMessages([
                'email' => __('staff.email_required_for_invite'),
            ]);
        }

        if ($staff->user_id === null) {
            if (User::where('email', $staff->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => __('staff.login_exists'),
                ]);
            }

            $user = $corporation->users()->forceCreate([
                'name' => $staff->name,
                'email' => $staff->email,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(40)),
            ]);

            $staff->update(['user_id' => $user->id]);
        }

        Password::sendResetLink(['email' => $staff->email]);

        EventRecorder::record($corporation, 'staff.login_invited', subject: $staff);

        return response()->json(['status' => 'invited']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Staff $staff): array
    {
        return [
            'id' => $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'isAdmin' => $staff->is_admin,
            'isFounder' => $staff->is_founder,
            'hasLogin' => $staff->user_id !== null,
        ];
    }

    /**
     * Whether $staff is the only admin in the corporation that has a login.
     */
    private function isLastAdminWithLogin(Staff $staff): bool
    {
        if ($staff->user_id === null) {
            return false;
        }

        return $staff->corporation->staff()
            ->where('is_admin', true)
            ->whereNotNull('user_id')
            ->whereKeyNot($staff->id)
            ->doesntExist();
    }
}
