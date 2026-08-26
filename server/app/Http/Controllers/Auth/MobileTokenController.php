<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MobileTokenRequest;
use App\Support\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Bearer-token auth for the React Native app. The web app keeps its Sanctum
 * cookie session (AuthenticatedSessionController); a phone has no cookie jar
 * worth trusting, so it carries a personal access token instead.
 */
class MobileTokenController extends Controller
{
    /**
     * Issue a token for one device.
     */
    public function store(MobileTokenRequest $request): JsonResponse
    {
        $user = $request->authenticateUser();

        $token = $user->createToken($request->string('device_name')->toString());

        EventRecorder::record($user->corporation, 'user.logged_in', subject: $user, actor: $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $user->loadMissing('staff'),
        ]);
    }

    /**
     * Revoke the token this request came in on, leaving the user's other
     * devices signed in.
     */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
