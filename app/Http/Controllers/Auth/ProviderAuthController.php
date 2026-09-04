<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Users\Aggregates\UserAggregate;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProviderAuthController extends Controller
{
    public function apple(Request $request): JsonResponse
    {
        return $this->authenticateWithProvider($request, 'apple');
    }

    public function google(Request $request): JsonResponse
    {
        return $this->authenticateWithProvider($request, 'google');
    }

    private function authenticateWithProvider(Request $request, string $provider): JsonResponse
    {
        $request->validate([
            'identity_token' => 'required|string',
            'name'           => 'sometimes|string|max:255',
        ]);

        // PoC: decode JWT payload without verifying signature.
        // Production would fetch Apple/Google public keys and verify iss/aud/exp.
        $claims = $this->decodeJwtWithoutVerification($request->identity_token);

        $providerUserId = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;

        if (! $providerUserId || ! $email) {
            return response()->json([
                'message' => 'identity_token is missing required claims (sub, email).',
            ], 422);
        }

        $user = User::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if (! $user) {
            $uuid = (string) Str::uuid();

            UserAggregate::retrieve($uuid)
                ->registerViaProvider(
                    $request->input('name', $email),
                    $email,
                    $provider,
                    $providerUserId,
                )
                ->persist();

            $user = User::where('uuid', $uuid)->firstOrFail();
        }

        $token = $user->createToken('chilli-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $user,
        ], 201);
    }

    private function decodeJwtWithoutVerification(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
