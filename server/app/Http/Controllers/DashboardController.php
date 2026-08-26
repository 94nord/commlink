<?php

namespace App\Http\Controllers;

use App\Support\SchedulePayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $corporation = $request->user()->corporation;

        return Inertia::render('dashboard', [
            'corporationName' => $corporation->name,
            'visits' => SchedulePayload::visits($corporation),
            'cases' => SchedulePayload::caseOptions($corporation),
            'staffOptions' => SchedulePayload::staffOptions($corporation),
        ]);
    }
}
