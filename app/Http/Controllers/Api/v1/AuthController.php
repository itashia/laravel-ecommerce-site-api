<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Api\v1\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends ApiController
{
    private const TOKEN_NAME = 'authToken';
    
    /**
     * Register a new user
     *
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:users,name',
            'email' => 'required|email:rfc,dns|max:255|unique:users,email',
            'password' => [
                'required',
                'string',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
                'confirmed'
            ],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verification_token' => Str::random(60),
        ]);

        // Dispatch email verification job here if needed

        $token = $user->createToken(self::TOKEN_NAME)->plainTextToken;

        return $this->successResponse(
            Response::HTTP_CREATED,
            [
                'user' => $user->only(['id', 'name', 'email']),
                'token' => $token,
            ],
            'User registered successfully. Please verify your email.'
        );
    }

    /**
     * Authenticate user and return token
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'sometimes|string|max:255',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return $this->errorResponse(
                Response::HTTP_UNAUTHORIZED,
                'Invalid credentials',
                ['email' => ['The provided credentials are incorrect.']]
            );
        }

        // if (!$user->hasVerifiedEmail()) {
        //     return $this->errorResponse(
        //         Response::HTTP_FORBIDDEN,
        //         'Email not verified',
        //         ['email' => ['Please verify your email address before logging in.']]
        //     );
        // }

        $tokenName = $credentials['device_name'] ?? self::TOKEN_NAME;
        $token = $user->createToken($tokenName)->plainTextToken;

        return $this->successResponse(
            Response::HTTP_OK,
            [
                'user' => $user->only(['id', 'name', 'email']),
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('sanctum.expiration') * 60,
            ]
        );
    }

    /**
     * Logout user and revoke tokens
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(
            Response::HTTP_OK,
            null,
            'Successfully logged out'
        );
    }

    /**
     * Revoke all user tokens
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logoutAllDevices(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return $this->successResponse(
            Response::HTTP_OK,
            null,
            'Successfully logged out from all devices'
        );
    }
}
