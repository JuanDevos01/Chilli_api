<?php

namespace App\Domain\Questionnaires\Aggregates;

use App\Domain\Questionnaires\Events\AnswerRetracted;
use App\Domain\Questionnaires\Events\QuestionAnswered;
use DomainException;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class QuestionnaireResponseAggregate extends AggregateRoot
{
    /** @var array<string, string> question_uuid => answer */
    private array $answered = [];

    /** @var array<string, true> question_uuid => true */
    private array $retracted = [];

    public function answer(string $questionUuid, string $answer): self
    {
        if (! in_array($answer, ['yes', 'no'], true)) {
            throw new DomainException('Answer must be "yes" or "no".');
        }

        if (isset($this->retracted[$questionUuid])) {
            throw new DomainException('This question was retracted and cannot be answered again.');
        }

        if (isset($this->answered[$questionUuid])) {
            throw new DomainException('This question has already been answered.');
        }

        $this->recordThat(new QuestionAnswered($this->uuid(), $questionUuid, $answer));

        return $this;
    }

    public function retract(string $questionUuid): self
    {
        if (! isset($this->answered[$questionUuid])) {
            throw new DomainException('Cannot retract an answer that was never given.');
        }

        if (isset($this->retracted[$questionUuid])) {
            throw new DomainException('This answer was already retracted.');
        }

        $this->recordThat(new AnswerRetracted($this->uuid(), $questionUuid));

        return $this;
    }

    protected function applyQuestionAnswered(QuestionAnswered $event): void
    {
        $this->answered[$event->questionUuid] = $event->answer;
    }

    protected function applyAnswerRetracted(AnswerRetracted $event): void
    {
        $this->retracted[$event->questionUuid] = true;
    }
}
