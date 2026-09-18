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

        $rows = DB::table('questions')
            ->leftJoin('user_answers', function ($join) use ($userUuid) {
                $join->on('user_answers.question_uuid', '=', 'questions.uuid')
                    ->where('user_answers.user_uuid', '=', $userUuid)
                    ->whereNull('user_answers.retracted_at');
            })
            ->leftJoin('user_question_scores', function ($join) use ($userUuid) {
                $join->on('user_question_scores.question_uuid', '=', 'questions.uuid')
                    ->where('user_question_scores.user_uuid', '=', $userUuid)
                    ->whereNull('user_question_scores.retracted_at');
            })
            ->select([
                'questions.uuid',
                'questions.text',
                'questions.dimensions',
                'user_answers.answer as my_answer',
                'user_question_scores.scores as my_scores',
            ])
            ->orderBy('questions.id')
            ->get();

        $questions = $rows->map(fn ($row) => [
            'uuid' => $row->uuid,
            'text' => $row->text,
            'dimensions' => $row->dimensions ? json_decode($row->dimensions, true) : [],
            'my_answer' => $row->my_answer,
            'my_scores' => $row->my_scores ? json_decode($row->my_scores, true) : null,
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

    public function score(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question_uuid' => ['required', 'uuid', Rule::exists('questions', 'uuid')],
            'scores' => ['required', 'array', 'min:1'],
            'scores.*' => ['integer', 'between:0,100'],
        ]);

        $userUuid = $request->user()->uuid;

        QuestionnaireResponseAggregate::retrieve($userUuid)
            ->score($validated['question_uuid'], $validated['scores'])
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
