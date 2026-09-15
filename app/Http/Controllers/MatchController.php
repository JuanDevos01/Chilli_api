<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userUuid = $request->user()->uuid;

        $matches = DB::table('matches')
            ->join('couples', 'matches.couple_uuid', '=', 'couples.uuid')
            ->join('questions', 'matches.question_uuid', '=', 'questions.uuid')
            ->where(function ($q) use ($userUuid) {
                $q->where('couples.user_a_uuid', $userUuid)
                    ->orWhere('couples.user_b_uuid', $userUuid);
            })
            ->whereNull('matches.invalidated_at')
            ->orderBy('matches.detected_at', 'desc')
            ->get([
                'matches.uuid',
                'matches.question_uuid',
                'questions.text as question_text',
                'matches.detected_at',
            ]);

        return response()->json(['matches' => $matches]);
    }
}
