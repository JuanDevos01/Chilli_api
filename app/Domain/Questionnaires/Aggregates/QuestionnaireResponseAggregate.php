<?php

namespace App\Domain\Questionnaires\Aggregates;

use App\Domain\Questionnaires\Events\QuestionAnswered;
use DomainException;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class QuestionnaireResponseAggregate extends AggregateRoot
{
    /** @var array<string, string> question_uuid => answer */
    private array $answered = [];

    public function answer(string $questionUuid, string $answer): self
    {
        if (! in_array($answer, ['yes', 'no'], true)) {
            throw new DomainException('Answer must be "yes" or "no".');
        }

        if (isset($this->answered[$questionUuid])) {
            throw new DomainException('This question has already been answered.');
        }

        $this->recordThat(new QuestionAnswered($this->uuid(), $questionUuid, $answer));

        return $this;
    }

    protected function applyQuestionAnswered(QuestionAnswered $event): void
    {
        $this->answered[$event->questionUuid] = $event->answer;
    }
}
