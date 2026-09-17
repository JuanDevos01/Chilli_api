<?php

namespace App\Http\Controllers;

use App\Domain\Couples\Aggregates\CoupleAggregate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CoupleController extends Controller
{
    public function createInvitation(Request $request): JsonResponse
    {
        $userUuid = $request->user()->uuid;

        if ($this->userAlreadyInCouple($userUuid)) {
            throw ValidationException::withMessages([
                'user' => ['El usuario ya forma parte de una pareja activa.'],
            ]);
        }

        $coupleUuid = (string) Str::uuid();
        $code = $this->generateUniqueCode();

        CoupleAggregate::retrieve($coupleUuid)
            ->sendInvitation($userUuid, $code)
            ->persist();

        return response()->json([
            'couple_uuid' => $coupleUuid,
            'code' => $code,
        ], 201);
    }

    public function acceptInvitation(Request $request, string $code): JsonResponse
    {
        $userUuid = $request->user()->uuid;

        if ($this->userAlreadyInCouple($userUuid)) {
            throw ValidationException::withMessages([
                'user' => ['El usuario ya forma parte de una pareja activa.'],
            ]);
        }

        $invitation = DB::table('couple_invitations')
            ->where('code', $code)
            ->whereNull('accepted_at')
            ->first();

        if (! $invitation) {
            throw ValidationException::withMessages([
                'code' => ['Código de invitación inválido o ya utilizado.'],
            ]);
        }

        CoupleAggregate::retrieve($invitation->couple_uuid)
            ->acceptInvitation($userUuid)
            ->persist();

        return response()->json([
            'couple_uuid' => $invitation->couple_uuid,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $userUuid = $request->user()->uuid;

        $couple = DB::table('couples')
            ->where('user_a_uuid', $userUuid)
            ->orWhere('user_b_uuid', $userUuid)
            ->first();

        if (! $couple) {
            return response()->json(['couple' => null]);
        }

        $partnerUuid = $couple->user_a_uuid === $userUuid
            ? $couple->user_b_uuid
            : $couple->user_a_uuid;

        $partner = DB::table('users')
            ->where('uuid', $partnerUuid)
            ->select('uuid', 'name', 'email')
            ->first();

        return response()->json([
            'couple' => [
                'uuid' => $couple->uuid,
                'linked_at' => $couple->linked_at,
                'partner' => $partner,
            ],
        ]);
    }

    private function userAlreadyInCouple(string $userUuid): bool
    {
        return DB::table('couples')
            ->where('user_a_uuid', $userUuid)
            ->orWhere('user_b_uuid', $userUuid)
            ->exists();
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(6));
        } while (DB::table('couple_invitations')->where('code', $code)->exists());

        return $code;
    }
}
