<?php

namespace App\Http\Controllers;

use App\Domain\Questionnaires\Aggregates\QuestionnaireResponseAggregate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuestionnaireController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userUuid = $request->user()->uuid;

        $questions = DB::table('questions')
            ->leftJoin('user_answers', function ($join) use ($userUuid) {
                $join->on('user_answers.question_uuid', '=', 'questions.uuid')
                    ->where('user_answers.user_uuid', '=', $userUuid)
                    ->whereNull('user_answers.retracted_at');
            })
            ->select([
                'questions.uuid',
                'questions.text',
                'user_answers.answer as my_answer',
            ])
            ->orderBy('questions.id')
            ->get()
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'text' => $row->text,
                'my_answer' => $row->my_answer,
            ]);

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

    public function retract(Request $request, string $questionUuid): JsonResponse
    {
        $userUuid = $request->user()->uuid;

        QuestionnaireResponseAggregate::retrieve($userUuid)
            ->retract($questionUuid)
            ->persist();

        return response()->json(['status' => 'retracted']);
    }
}
