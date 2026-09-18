<?php

namespace App\Domain\Questionnaires\Aggregates;

use App\Domain\Questionnaires\Events\AnswerRetracted;
use App\Domain\Questionnaires\Events\QuestionAnswered;
use App\Domain\Questionnaires\Events\QuestionScored;
use DomainException;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class QuestionnaireResponseAggregate extends AggregateRoot
{
    /** @var array<string, string> question_uuid => answer (legacy binary) */
    private array $answered = [];

    /** @var array<string, array<string, int>> question_uuid => scores map */
    private array $scored = [];

    /** @var array<string, true> question_uuid => true */
    private array $retracted = [];

    public function answer(string $questionUuid, string $answer): self
    {
        if (! in_array($answer, ['yes', 'no'], true)) {
            throw new DomainException('Answer must be "yes" or "no".');
        }

        $this->assertOpenForCommitment($questionUuid);

        $this->recordThat(new QuestionAnswered($this->uuid(), $questionUuid, $answer));

        return $this;
    }

    /**
     * @param array<string, int> $scores dimension name => integer in [0, 100]
     */
    public function score(string $questionUuid, array $scores): self
    {
        if (empty($scores)) {
            throw new DomainException('Scores map cannot be empty.');
        }

        foreach ($scores as $dimension => $value) {
            if (! is_string($dimension) || $dimension === '') {
                throw new DomainException('Dimension name must be a non-empty string.');
            }
            if (! is_int($value) || $value < 0 || $value > 100) {
                throw new DomainException("Score for '{$dimension}' must be an integer between 0 and 100.");
            }
        }

        $this->assertOpenForCommitment($questionUuid);

        $this->recordThat(new QuestionScored($this->uuid(), $questionUuid, $scores));

        return $this;
    }

    public function retract(string $questionUuid): self
    {
        $hasCommitment = isset($this->answered[$questionUuid]) || isset($this->scored[$questionUuid]);

        if (! $hasCommitment) {
            throw new DomainException('Cannot retract a response that was never given.');
        }

        if (isset($this->retracted[$questionUuid])) {
            throw new DomainException('This response was already retracted.');
        }

        $this->recordThat(new AnswerRetracted($this->uuid(), $questionUuid));

        return $this;
    }

    private function assertOpenForCommitment(string $questionUuid): void
    {
        if (isset($this->retracted[$questionUuid])) {
            throw new DomainException('This question was retracted and cannot be answered again.');
        }

        if (isset($this->answered[$questionUuid]) || isset($this->scored[$questionUuid])) {
            throw new DomainException('This question has already been answered.');
        }
    }

    protected function applyQuestionAnswered(QuestionAnswered $event): void
    {
        $this->answered[$event->questionUuid] = $event->answer;
    }

    protected function applyQuestionScored(QuestionScored $event): void
    {
        $this->scored[$event->questionUuid] = $event->scores;
    }

    protected function applyAnswerRetracted(AnswerRetracted $event): void
    {
        $this->retracted[$event->questionUuid] = true;
    }
}
