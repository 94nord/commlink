<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Support\EventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ]),
        ]);
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        $corporation = $request->user()->corporation;

        $staff = $corporation->staff()->create($request->validated());

        EventRecorder::record($corporation, 'staff.created', subject: $staff);

        return to_route('staff.index');
    }

    public function update(UpdateStaffRequest $request, int $staff): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $staff = $corporation->staff()->findOrFail($staff);

        $staff->update($request->validated());

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

        EventRecorder::record($corporation, 'staff.deleted', subject: $staff);

        $staff->delete();

        return back();
    }
}
