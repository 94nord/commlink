<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\Staff;
use App\Support\EventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        $staff = $request->user()->corporation->staff()
            ->orderBy('name')
            ->get();

        return Inertia::render('staff/index', [
            'staff' => $staff->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'isAdmin' => $member->is_admin,
                'hasLogin' => $member->user_id !== null,
            ]),
        ]);
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        $corporation = $request->user()->corporation;

        $staff = $corporation->staff()->create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'is_admin' => $request->boolean('is_admin'),
        ]);

        EventRecorder::record($corporation, 'staff.created', subject: $staff);

        return to_route('staff.index');
    }

    public function update(UpdateStaffRequest $request, int $staff): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $staff = $corporation->staff()->findOrFail($staff);

        // Never leave the corporation without an admin who can log in.
        if ($staff->is_admin && ! $request->boolean('is_admin') && $this->isLastAdminWithLogin($staff)) {
            throw ValidationException::withMessages([
                'is_admin' => 'This is the last admin who can log in — promote someone else first.',
            ]);
        }

        $staff->update([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'is_admin' => $request->boolean('is_admin'),
        ]);

        EventRecorder::record($corporation, 'staff.updated', subject: $staff);

        return back();
    }

    public function destroy(Request $request, int $staff): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $staff = $corporation->staff()->findOrFail($staff);

        // S2: a corporation can never have zero staff.
        if ($corporation->staff()->count() <= 1) {
            return back()->withErrors([
                'staff' => 'A corporation must have at least one staff member.',
            ]);
        }

        if ($staff->is_admin && $this->isLastAdminWithLogin($staff)) {
            return back()->withErrors([
                'staff' => 'This is the last admin who can log in — promote someone else first.',
            ]);
        }

        EventRecorder::record($corporation, 'staff.deleted', subject: $staff);

        // Remove the linked login too, so no orphaned account survives.
        $staff->user?->delete();
        $staff->delete();

        return back();
    }

    /**
     * Provision (or re-send) a login: create the user if needed, then email a
     * set-password link via the standard password-reset flow.
     */
    public function invite(Request $request, int $staff): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $staff = $corporation->staff()->findOrFail($staff);

        if ($staff->email === null) {
            throw ValidationException::withMessages([
                'email' => 'Add an email address before inviting this staff member.',
            ]);
        }

        if ($staff->user_id === null) {
            if (\App\Models\User::where('email', $staff->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'That email already has a login.',
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

        return back();
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
