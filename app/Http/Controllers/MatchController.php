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

        $rows = DB::table('matches')
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
                'matches.narrative',
                'matches.matched_dimensions',
                'matches.diverging_dimensions',
                'matches.confidence',
                'matches.detected_at',
            ]);

        $matches = $rows->map(fn ($row) => [
            'uuid' => $row->uuid,
            'question_uuid' => $row->question_uuid,
            'question_text' => $row->question_text,
            'narrative' => $row->narrative,
            'matched_dimensions' => $row->matched_dimensions ? json_decode($row->matched_dimensions, true) : [],
            'diverging_dimensions' => $row->diverging_dimensions ? json_decode($row->diverging_dimensions, true) : [],
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
            'detected_at' => $row->detected_at,
        ]);

        return response()->json(['matches' => $matches]);
    }
}
