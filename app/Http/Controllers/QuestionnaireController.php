<?php

namespace App\Http\Controllers;

use App\Domain\Questionnaires\Aggregates\QuestionnaireResponseAggregate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuestionnaireController extends Controller
{
    public function index(): JsonResponse
    {
        $questions = DB::table('questions')
            ->select(['uuid', 'text'])
            ->orderBy('id')
            ->get();

        return response()->json(['questions' => $questions]);
    }

    public function answer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question_uuid' => ['required', 'uuid', Rule::exists('questions', 'uuid')],
            'answer' => ['required', Rule::in(['yes', 'no'])],
        ]);

        $userUuid = $request->user()->uuid;

        QuestionnaireResponseAggregate::retrieve($userUuid)
            ->answer($validated['question_uuid'], $validated['answer'])
            ->persist();

        return response()->json(['status' => 'recorded'], 201);
    }
}
