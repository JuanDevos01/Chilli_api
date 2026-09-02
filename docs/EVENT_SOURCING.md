# Event Sourcing in Chilli — Summary for Thomas

**From:** Juan
**Date:** 2026-09-01
**Library:** [`spatie/laravel-event-sourcing`](https://github.com/spatie/laravel-event-sourcing) v7.15.1

---

## 1. What is Event Sourcing

In a normal CRUD, the database stores **the current state**. For example, a `matches` table with a row: "the Alice+Bob couple has a match on question Q1". If tomorrow you want to know *why* that match appeared, or *exactly when* Alice had answered "yes", that information is gone — only the conclusion remains.

In Event Sourcing, the database stores **the list of facts that occurred**, in order:

```
1. AliceAnswered "yes" to Q1       (10:32:15)
2. BobAnswered   "yes" to Q1       (10:35:41)
3. MatchDetected(Alice+Bob, Q1)    (10:35:41)  ← consequence of the two above
```

The "match" is no longer a row someone inserted — it's a **recorded fact** with its full causal chain in plain sight.

Consequences:
- **Nothing is lost**: you can always reconstruct any past state.
- **Free audit trail**: events *are* the log — every match keeps a trace of the two answers that produced it.
- **New features over historical data**: if tomorrow you want to know "how long does an average couple take between their first and second match?", the data is already there.
- **Reproducibility**: if the match rule changes (e.g. requires confirmation), you "rewind" and recompute over the entire history.

---

## 2. The library — `spatie/laravel-event-sourcing`

It's the de facto standard package for Event Sourcing in Laravel. Maintained by Spatie (Belgium), v7 is compatible with Laravel 11–13.

It provides four conceptual pieces:

| Piece | What it is | Example in Chilli |
|---|---|---|
| **Event** | Immutable PHP class describing a past fact. | `UserRegistered`, `QuestionAnswered` |
| **Aggregate** | Class that protects business rules and emits events. | `QuestionnaireResponseAggregate` |
| **Projector** | Listens to events and keeps read tables up to date. | `AnswerProjector` inserts into `user_answers` |
| **Reactor** | Listens to events and triggers side effects (new events, notifications, external integrations). | `DetectMutualMatchReactor` decides to create a match |

Plus one magic table: **`stored_events`** — append-only, the single source of truth.

---

## 3. How it works — the full cycle in one request

```
                HTTP Request
                     │
                     ▼
              Controller (thin)
                     │
                     ▼
    Aggregate::retrieve(uuid)        ← reads stored_events and
                     │                  rebuilds state
                     ▼
    Aggregate::doSomething(...)      ← validates business rules
                     │                  and calls recordThat(new Event)
                     ▼
    Aggregate::persist()
                     │
        ┌────────────┼───────────────┐
        ▼            ▼               ▼
  stored_events   Projectors      Reactors
   (INSERT)      (update read    (side effects,
                 tables)          may emit new
                                  events)
        │            │               │
        └────────────┴───────┬───────┘
                             ▼
                       HTTP Response
                (reading from projections)
```

**Golden rule:** controllers **never** write directly to the database. They only talk to aggregates.

---

## 4. A full example — how a match is produced in code

This is the real flow in Chilli: Bob answers "yes" to a question that Alice had already answered "yes" to. The system detects the match automatically. Let's walk through each piece.

### 4.1 The event — "Bob answered Q1"

```php
// An immutable fact, in past tense. The `answer` field is encrypted at-rest.
class QuestionAnswered extends ShouldBeStored
{
    public function __construct(
        public string $userUuid,
        public string $questionUuid,
        #[Encrypted] public string $answer,
    ) {}
}
```

### 4.2 The aggregate — validates and emits the event

```php
class QuestionnaireResponseAggregate extends AggregateRoot
{
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

    // Called automatically when rebuilding the aggregate from stored_events
    public function applyQuestionAnswered(QuestionAnswered $event): void
    {
        $this->answered[$event->questionUuid] = $event->answer;
    }
}
```

### 4.3 The controller — only orchestrates, doesn't decide

```php
QuestionnaireResponseAggregate::retrieve($bobUuid)
    ->answer($Q1, 'yes')
    ->persist();
```

That `persist()` is the key moment: it writes the event into `stored_events` and dispatches it to all registered projectors and reactors.

### 4.4 The projector — updates the read table

```php
class AnswerProjector extends Projector
{
    public function onQuestionAnswered(QuestionAnswered $event): void
    {
        DB::table('user_answers')->insert([
            'user_uuid'     => $event->userUuid,
            'question_uuid' => $event->questionUuid,
            'answer'        => $event->answer,   // already decrypted
            'answered_at'   => now(),
        ]);
    }
}
```

### 4.5 The reactor — this is where the match happens

A reactor listens to the same event, but its job isn't to update tables — it's to **decide whether something new should happen in the domain**.

```php
class DetectMutualMatchReactor extends Reactor
{
    public function onQuestionAnswered(QuestionAnswered $event): void
    {
        // 1. Only "yes" answers can produce a match
        if ($event->answer !== 'yes') return;

        // 2. Does Bob have a partner?
        $couple = $this->coupleOf($event->userUuid);
        if (! $couple) return;

        // 3. Who's the partner? (Alice)
        $partnerUuid = $couple->user_a_uuid === $event->userUuid
            ? $couple->user_b_uuid
            : $couple->user_a_uuid;

        // 4. What did Alice answer to this same question?
        $partnerAnswer = DB::table('user_answers')
            ->where('user_uuid', $partnerUuid)
            ->where('question_uuid', $event->questionUuid)
            ->value('answer');

        // 5. If Alice also said "yes" → MATCH!
        if ($partnerAnswer === 'yes') {
            MatchAggregate::retrieve((string) Str::uuid())
                ->detect($couple->uuid, $event->questionUuid, $couple->user_a_uuid, $couple->user_b_uuid)
                ->persist();
        }
    }
}
```

That `MatchAggregate::detect(...)->persist()` writes a **new event** — `MutualPreferencesDetected` — which in turn has its own projector (`MatchProjector`) that inserts a row into `matches` so endpoints can read it.

### 4.6 The result

All of the above happens in the **same request** as Bob's, synchronously, in < 30 ms. Bob only receives `{ "status": "recorded" }` — he doesn't know his answer triggered a match. Alice and Bob find out when the mobile app queries `GET /api/matches`.

**And here's the key point:** the match wasn't "inserted" — it was **detected** as an observable consequence of two earlier facts, both preserved forever in `stored_events`.

---

## 5. The `stored_events` table — the source of truth

After Alice registering, pairing with Bob, and both answering "yes" to Q1, the table looks like this:

| id | aggregate_uuid | event_class | event_properties |
|---|---|---|---|
| 1 | alice | `UserRegistered` | `{"uuid":"alice","name":"Alice",...}` |
| 2 | bob | `UserRegistered` | `{"uuid":"bob","name":"Bob",...}` |
| 3 | couple1 | `CoupleInvitationSent` | `{"inviterUuid":"alice","code":"ABC123"}` |
| 4 | couple1 | `CoupleInvitationAccepted` | `{"accepterUuid":"bob"}` |
| 5 | couple1 | `CoupleLinked` | `{"userAUuid":"alice","userBUuid":"bob"}` |
| 6 | alice | `QuestionAnswered` | `{"questionUuid":"Q1","answer":"eyJpdi..."}` ← **encrypted** |
| 7 | bob | `QuestionAnswered` | `{"questionUuid":"Q1","answer":"eyJpdi..."}` ← **encrypted** |
| 8 | match1 | `MutualPreferencesDetected` | `{"coupleUuid":"couple1","questionUuid":"Q1"}` |

Chilli's full history, in a single table, forever.

---

## 6. Encryption of sensitive data

Spatie doesn't ship with native encryption, but it lets you swap out the event serializer. In Chilli we built an `EncryptedEventSerializer` that:

1. Scans event properties marked with the `#[Encrypted]` attribute.
2. Encrypts them with `Crypt::encryptString()` (using `APP_KEY`) before writing to `stored_events`.
3. Decrypts them when recovering the event for projectors/reactors.

In the event's code you just annotate:

```php
class QuestionAnswered extends ShouldBeStored
{
    public function __construct(
        public string $userUuid,
        public string $questionUuid,
        #[Encrypted] public string $answer,   // ← encrypted at-rest
    ) {}
}
```

Verified by test: in the DB the `answer` field is `eyJpdi...` (encrypted blob), but arrives as `"yes"` at the projector.

---

## 7. The "powers" Event Sourcing gives you (for the future)

| Typical requirement | Solution with Event Sourcing |
|---|---|
| "When was this couple's first match detected?" | Direct query on `stored_events` |
| "Match logic changed, reprocess history" | `php artisan event-sourcing:replay MatchProjector` |
| "GDPR: user requests deletion of their answers" | Emit `UserRedacted`; history preserved, projection rebuilt |
| "Audit: what happened with this couple on August 15?" | Query filtering by `aggregate_uuid` and date range |
| "New feature: stats on time between answers and match" | Data already in `stored_events` from day one |

In a traditional CRUD, each item above requires a migration, a backfill, or is simply impossible because the data was never stored.

---

## 8. PoC status

- **13 tests / 71 assertions passing** (< 400 ms)
- **4 bounded contexts** implemented: Users, Couples, Questionnaires, Matches
- **End-to-end flow working**: registration → invitation by code → questionnaire → mutual match revealed
- **At-rest encryption** verified by test
- **Privacy guaranteed**: the partner's answer never leaves the API

To see it running:

```bash
cd /Users/juandevos/chilli-api
composer install
php artisan migrate:fresh --seed
php artisan test
php artisan serve
```

A more detailed walkthrough of the full flow (including a step-by-step narration of the "moment of the match") lives in `docs/POC_EVENT_SOURCING.md`.

---

## Resources

- Official documentation: https://spatie.be/docs/laravel-event-sourcing/v7/introduction
- Repository: https://github.com/spatie/laravel-event-sourcing
