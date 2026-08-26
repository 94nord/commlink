<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The mobile app's login. Same credentials and the same throttle as the web
 * login it extends — only the outcome differs: a bearer token instead of a
 * session cookie.
 */
class MobileTokenRequest extends LoginRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Names the token, so a lost phone can be revoked on its own.
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Verify the credentials and return the user they belong to.
     *
     * Named apart from the parent's authenticate(), which returns void and so
     * cannot be overridden with a return value.
     *
     * Deliberately not Auth::attempt(): that logs into the session guard and
     * writes to a session this stateless request will never persist. Here we
     * only need to know the password is right.
     *
     * @throws ValidationException
     */
    public function authenticateUser(): User
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');

        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user || ! $provider->validateCredentials($user, $credentials)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }
}
