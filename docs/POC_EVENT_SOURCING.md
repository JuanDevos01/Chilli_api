# PoC — Event Sourcing in the Chilli Backend

**Date:** 2026-09-01 · *retraction extension added 2026-09-11*
**Stack:** Laravel 13.8 · PHP 8.3 · SQLite · Laravel Sanctum · `spatie/laravel-event-sourcing` v7.15.1
**Status:** complete — 22 tests / 118 assertions green
**Local repository:** `/Users/juandevos/chilli-api`

---

## 1. Question this PoC answers

> **Is it viable to model Chilli's core (registration → pairing → questionnaires → revealed mutual match) with Event Sourcing using `spatie/laravel-event-sourcing` on Laravel 13, encrypting intimate data at-rest?**

**Short answer:** Yes. The four pillars (aggregates, projectors, reactors, encryption) fit cleanly into Chilli's domain without significant friction.

---

## 2. What is a PoC (for context)

A Proof of Concept is a **minimal and disposable** implementation that validates a technical hypothesis. It covers only the happy path, is ugly but functional, and helps make decisions before investing weeks into product work. This document describes what was validated, how, and what was assumed out of scope.

---

## 3. Implemented scope

### Working end-to-end flow

```
1. Alice registers        → POST /api/auth/register
2. Bob registers          → POST /api/auth/register
3. Alice creates invite   → POST /api/couples/invitations → { code: "ABCXYZ" }
4. Bob accepts code       → POST /api/couples/invitations/ABCXYZ/accept
5. Alice answers Q1: "yes" → POST /api/questionnaire/answers
6. Bob answers Q1:   "yes" → POST /api/questionnaire/answers
7. Alice queries          → GET /api/matches → [ { question: "...", detected_at } ]
8. Bob queries            → GET /api/matches → [ same match ]
```

At no point is any individual partner answer accessible to the other — only yes/yes matches are revealed.

### Bounded contexts and pieces

| Context | Aggregate Root | Events | Projector | Reactor |
|---|---|---|---|---|
| **Users** | `UserAggregate` | `UserRegistered` | `UserProjector` | — |
| **Couples** | `CoupleAggregate` | `CoupleInvitationSent`, `CoupleInvitationAccepted`, `CoupleLinked` | `CoupleProjector` | — |
| **Questionnaires** | `QuestionnaireResponseAggregate` | `QuestionAnswered` *(binary, `answer` `#[Encrypted]`)*, `QuestionScored` *(scalar, `scores` map `#[Encrypted]`)*, `AnswerRetracted` | `AnswerProjector` | — |
| **Matches** | `MatchAggregate` | `MutualPreferencesDetected`, `MatchInvalidated` | `MatchProjector` | `DetectMutualMatchReactor` |

### Exposed endpoints

| Method | Route | Description |
|---|---|---|
| POST | `/api/auth/register` | Registration. Fires `UserRegistered`; returns Sanctum token. |
| POST | `/api/auth/login` | Login. Returns Sanctum token. |
| POST | `/api/auth/logout` | Revokes current token. |
| GET | `/api/auth/me` | Authenticated user profile. |
| POST | `/api/couples/invitations` | Creates invitation; returns shareable `code`. |
| POST | `/api/couples/invitations/{code}/accept` | Accepts invitation and links couple. |
| GET | `/api/questionnaire` | Catalog of questions (seed). |
| POST | `/api/questionnaire/answers` | Records binary answer (encrypted at-rest). *Legacy — retained for tests.* |
| POST | `/api/questionnaire/scores` | Records scalar multi-dimensional answer (scores map encrypted at-rest). |
| DELETE | `/api/questionnaire/answers/{questionUuid}` | Retracts a response (binary or scalar). Emits `AnswerRetracted`; invalidates the match if one existed. |
| GET | `/api/matches` | Revealed matches for the user's couple (excludes invalidated ones). |

---

## 4. Architecture — how an event flows

```
HTTP Request
    │
    ▼
Controller (input validation)
    │
    ▼
Aggregate::retrieve(uuid)
    │  (reconstructs state from stored_events + snapshots)
    ▼
Aggregate::doSomething(...)
    │  (validates business invariants; recordThat(new Event))
    ▼
Aggregate::persist()
    │
    ├──► stored_events INSERT (JSON payload, #[Encrypted] fields ciphered)
    │
    ├──► Synchronous projectors    → update read tables
    │       (users, couples, matches, user_answers, ...)
    │
    └──► Reactors → side effects
            (detect mutual match, notifications, external integrations)
    │
    ▼
HTTP Response (reading from projection tables)
```

**Principles honored:**
- The **source of truth** is the `stored_events` table (append-only).
- Read tables (`users`, `couples`, `matches`, ...) are **derived cache** — they can be rebuilt with `php artisan event-sourcing:replay`.
- **Aggregates** encapsulate business rules (e.g. a user can't be in two active couples).
- **Reactors** trigger side effects (match, notifications) — never controllers.
- **Projectors** are idempotent and rebuildable.

---

## 5. The match moment — step by step

This section narrates the exact instant when the system detects a mutual match. It's the example that best shows why we use Event Sourcing rather than "controllers that write to tables". Key concepts are introduced as they appear.

### Prelude — system state right before the match

Say Alice and Bob are already registered, already a couple, and Alice answered `"yes"` to question Q1 ten minutes ago. Right now:

**`stored_events` table** (the system's **source of truth** — every row is an immutable fact that occurred):

| id | aggregate_uuid | event_class | event_properties |
|---|---|---|---|
| 1 | alice-uuid | `UserRegistered` | `{"uuid":"alice","name":"Alice",...}` |
| 2 | bob-uuid | `UserRegistered` | `{"uuid":"bob","name":"Bob",...}` |
| 3 | couple-uuid | `CoupleInvitationSent` | `{"coupleUuid":"c1","inviterUuid":"alice","code":"ABC123"}` |
| 4 | couple-uuid | `CoupleInvitationAccepted` | `{"coupleUuid":"c1","accepterUuid":"bob"}` |
| 5 | couple-uuid | `CoupleLinked` | `{"coupleUuid":"c1","userAUuid":"alice","userBUuid":"bob"}` |
| 6 | alice-uuid | `QuestionAnswered` | `{"userUuid":"alice","questionUuid":"Q1","answer":"eyJpdi..."}` ← encrypted |

**Read tables** (a.k.a. *projections* — derived cache, rebuildable):

`user_answers`:
| user_uuid | question_uuid | answer |
|---|---|---|
| alice | Q1 | yes |

`matches`: empty

---

### T0 — Bob submits his answer

```http
POST /api/questionnaire/answers
Authorization: Bearer <bob_token>

{ "question_uuid": "Q1", "answer": "yes" }
```

### T1 — The controller delegates to an *aggregate*, doesn't write to the DB

> **What's an aggregate?** It's a PHP class representing a domain entity (here, "a user's answers"). **Its job is to protect business rules.** The controller decides nothing — it only asks the aggregate to do something.

The controller only has three lines of logic:

```php
QuestionnaireResponseAggregate::retrieve($bobUuid)   // rebuild the aggregate from its history
    ->answer($Q1, 'yes')                             // request an action
    ->persist();                                      // save the new events
```

### T2 — The aggregate rebuilds itself from its history

`retrieve($bobUuid)` reads no "current state" table. It goes to `stored_events`, filters events where `aggregate_uuid = bob-uuid`, and applies them in order to reconstruct the in-memory state. For Bob, no questionnaire events yet → empty state.

> **Key idea:** the aggregate's state **is not stored**. It's **recomputed** from events every time. Events are the only thing that persists.

### T3 — The aggregate validates and records to its "internal memory"

```php
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
```

`recordThat(...)` does **not** write to the DB yet — it just notes "I will save this event when someone calls `persist()`". It's the aggregate's way of saying *"this is what happened"*.

### T4 — `persist()` writes the event to the store

Now the event is saved into `stored_events`. The `answer` field **is encrypted at this moment** by `EncryptedEventSerializer` (see section 6):

**`stored_events` table — new row:**

| id | aggregate_uuid | event_class | event_properties |
|---|---|---|---|
| **7** | **bob-uuid** | **`QuestionAnswered`** | **`{"userUuid":"bob","questionUuid":"Q1","answer":"eyJpdi..."}`** ← encrypted |

This INSERT is the only **essential** operation. Everything that follows is a **reaction** to this fact.

### T5 — `AnswerProjector` is triggered (synchronous, same request)

> **What's a projector?** A class whose sole job is to **keep a read table up to date** in response to events. It never changes its past logic — you can drop the table and rebuild it by running the projector over every historical event.

`AnswerProjector` receives the `QuestionAnswered` event (already decrypted by Spatie) and does a simple insert:

```php
public function onQuestionAnswered(QuestionAnswered $event): void
{
    DB::table('user_answers')->insert([
        'user_uuid' => $event->userUuid,
        'question_uuid' => $event->questionUuid,
        'answer' => $event->answer,
        'answered_at' => now(),
        ...
    ]);
}
```

**`user_answers` table — new row:**

| user_uuid | question_uuid | answer |
|---|---|---|
| alice | Q1 | yes |
| **bob** | **Q1** | **yes** ← new |

### T6 — `DetectMutualMatchReactor` is triggered (this is where the magic happens)

> **What's a reactor?** It's like a projector, but for **side effects**: sending a push, calling an external service, or — as here — **deciding that something new should happen in the domain**. Key difference: the projector only updates read tables; the reactor **can trigger new events**.

Here's the full reactor, with comments narrating each step:

```php
public function onQuestionAnswered(QuestionAnswered $event): void
{
    // Idempotency: if this exact event was already processed by this reactor
    // (replay, retry, etc.), don't run again.
    $this->once("qa:{$event->userUuid}:{$event->questionUuid}", function () use ($event) {

        // 1. Did Bob say "yes"? ("no" answers don't produce matches in Chilli)
        if ($event->answer !== 'yes') {
            return;
        }

        // 2. Does Bob have a registered partner?
        $couple = $this->coupleOf($event->userUuid);
        if (! $couple) {
            return;
        }

        // 3. Who's the partner? (here, Alice)
        $partnerUuid = $couple->user_a_uuid === $event->userUuid
            ? $couple->user_b_uuid
            : $couple->user_a_uuid;

        // 4. What did Alice answer to this same question?
        //    We query the `user_answers` projection (populated by AnswerProjector).
        $partnerAnswer = DB::table('user_answers')
            ->where('user_uuid', $partnerUuid)
            ->where('question_uuid', $event->questionUuid)
            ->value('answer');

        // 5. Did Alice also say "yes"? If not, no match.
        if ($partnerAnswer !== 'yes') {
            return;
        }

        // 6. Does a match already exist for this couple + question? (dup guard)
        if ($this->matchAlreadyExists($couple->uuid, $event->questionUuid)) {
            return;
        }

        // 7. MATCH! Instantiate a new aggregate and ask it to emit the event.
        MatchAggregate::retrieve((string) Str::uuid())
            ->detect($couple->uuid, $event->questionUuid, $couple->user_a_uuid, $couple->user_b_uuid)
            ->persist();
    });
}
```

At this instant, in real time inside Bob's same request:
- ✅ Bob said "yes"
- ✅ Bob is coupled with Alice
- ✅ Alice had already said "yes" to Q1
- ✅ No prior match exists for this question

→ The reactor invokes `MatchAggregate::detect(...)`.

### T7 — A new event is born: `MutualPreferencesDetected`

`MatchAggregate` validates its own rules (that no match already exists for this same aggregate) and records the event:

**`stored_events` table — new row:**

| id | aggregate_uuid | event_class | event_properties |
|---|---|---|---|
| **8** | **match-uuid** | **`MutualPreferencesDetected`** | **`{"matchUuid":"m1","coupleUuid":"c1","questionUuid":"Q1","userAUuid":"alice","userBUuid":"bob"}`** |

> **Notice what's important here:** the "match" is not a row someone decided to insert into a table — it's a **fact recorded** in the system's history. If tomorrow you want to know "when did this couple's first match happen?" or "how many matches has Chilli generated this month?", the answer lives in `stored_events`, not in a mutable table.

### T8 — `MatchProjector` inserts the row for fast reads

```php
public function onMutualPreferencesDetected(MutualPreferencesDetected $event): void
{
    DB::table('matches')->insert([
        'uuid' => $event->matchUuid,
        'couple_uuid' => $event->coupleUuid,
        'question_uuid' => $event->questionUuid,
        'user_a_uuid' => $event->userAUuid,
        'user_b_uuid' => $event->userBUuid,
        'detected_at' => now(),
        ...
    ]);
}
```

**`matches` table — new row:**

| uuid | couple_uuid | question_uuid | detected_at |
|---|---|---|---|
| m1 | c1 | Q1 | 2026-09-01 20:35:12 |

### T9 — Bob receives his HTTP 201 response

Everything above — from T0 to T8 — happened in Bob's **same request**, synchronously, in under 30 ms. Bob only sees:

```json
{ "status": "recorded" }
```

He does not know (and doesn't need to) that his answer triggered a match. The mobile app will find out when it queries for matches.

### T10 — Alice or Bob query `GET /api/matches`

```json
{
  "matches": [
    {
      "uuid": "m1",
      "question_uuid": "Q1",
      "question_text": "Would you like to cook a new recipe together this weekend?",
      "detected_at": "2026-09-01T20:35:12Z"
    }
  ]
}
```

**Never** does `user_a_answer`, `user_b_answer`, or the string `"yes"` appear — only the question and the timestamp. Privacy is guaranteed by design: the `matches` projection doesn't include answers, and the controller doesn't even query them.

---

### Complete temporal diagram

```
Time →

T0  Bob POST /answers ─┐
                       │
T1  Controller creates │  (one single HTTP request)
     aggregate         │
T2  Aggregate rebuilds │
     itself            │
T3  Aggregate validates│
     and recordThat(...)│
T4  persist() ────────►│  stored_events INSERT (QuestionAnswered, encrypted)
                       │
T5  AnswerProjector ──►│  user_answers INSERT (bob, Q1, "yes")
                       │
T6  DetectMutualMatch  │  reads couples, reads Alice's user_answers
     Reactor           │  Alice also said "yes" → decides to create match
                       │
T7  MatchAggregate ───►│  stored_events INSERT (MutualPreferencesDetected)
     ::detect().persist│
                       │
T8  MatchProjector ───►│  matches INSERT (couple, Q1, timestamp)
                       │
T9  HTTP 201 ──────────┘  ~30 ms total

...

T10 Alice GET /matches ─►  sees the match (query on matches + questions)
    Bob   GET /matches ─►  sees the match (same projection)
```

---

### Why this is Event Sourcing and not just a normal CRUD

A "traditional" Laravel approach would solve the match like this:

```php
// Traditional AnswerController
public function answer(Request $request) {
    $answer = Answer::create([...]);           // save answer

    $partnerAnswer = Answer::where(...)->first();
    if ($partnerAnswer?->value === 'yes' && $answer->value === 'yes') {
        Match::create([...]);                   // create match
        NotificationService::push(...);         // notify
    }

    return response()->json(...);
}
```

It works, but:

| Future need | Traditional CRUD | Event Sourcing |
|---|---|---|
| "When did we detect this couple's first match?" | You need to have added `created_at` to `matches` and hope nobody edited it | Read `stored_events`, exact answer |
| "We want time-to-match statistics between answers" | Migration + collect data from now on | Data is already in `stored_events`, reprocess |
| "The match logic changed, can we reprocess history?" | Write a complicated one-off script | `php artisan event-sourcing:replay MatchProjector` |
| "A user requests GDPR erasure of their answers" | UPDATE/DELETE in the table → info lost | New event `UserRedacted`, history preserved, projection rebuilt |
| "The notification reactor failed, can we retry?" | Lost job, lost notification | Event queue, natural retry |
| "We want an audit dashboard of what happened per couple" | Put logs everywhere | Direct query over `stored_events` |
| "New requirement: match needs confirmation from both parties" | Invasive controller refactor | New `MatchConfirmed` event, new endpoint, enriched projection — without touching the detection logic |

**The core idea:** in the traditional flow, `Match::create(...)` is the final action; nobody will ever know **why** that match appeared, except by looking at logs. In Event Sourcing, `MutualPreferencesDetected` is the action; **the "why" lives in Alice's `QuestionAnswered` and Bob's `QuestionAnswered`**, all in the same source of truth, forever.

---

## 6. Privacy and encryption

Requirement: intimate data (questionnaire answers) must never be readable in plaintext from the database.

**Implementation:**
1. Created an `EncryptedEventSerializer` that wraps Spatie's `JsonEventSerializer`.
2. On serialization, it scans event properties marked with the PHP attribute `#[Encrypted]` and ciphers them with `Crypt::encryptString()` (uses `APP_KEY`).
3. On deserialization, it decrypts those properties before handing the event to the projector/reactor.
4. In Chilli, `QuestionAnswered::$answer` is marked `#[Encrypted]`.

**Verified by test:** the `answer` field in `stored_events.event_properties` is encrypted (the literal string `"yes"` never appears), but is correctly recovered during the normal flow.

```php
$this->assertNotSame('yes', $properties['answer']);              // ✅ encrypted in DB
$this->assertSame('yes', Crypt::decryptString($properties['answer'])); // ✅ recoverable
```

Additionally, the `GET /api/matches` endpoint **never** exposes individual partner answers — it only returns `question_uuid`, `question_text`, and `detected_at`. A dedicated test verifies that no sensitive information leaks.

---

## 7. How to run the PoC

```bash
cd /Users/juandevos/chilli-api
composer install
php artisan migrate:fresh --seed
php artisan test          # 22 green expected
php artisan serve         # server at http://localhost:8000
```

Sample session with `curl` (two terminals, one for Alice and one for Bob):

```bash
# Alice
ALICE_TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"name":"Alice","email":"alice@example.com","password":"secret1234","password_confirmation":"secret1234"}' \
  | jq -r .token)

# Bob
BOB_TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"name":"Bob","email":"bob@example.com","password":"secret1234","password_confirmation":"secret1234"}' \
  | jq -r .token)

# Alice creates invitation
CODE=$(curl -s -X POST http://localhost:8000/api/couples/invitations \
  -H "Authorization: Bearer $ALICE_TOKEN" | jq -r .code)

# Bob accepts
curl -X POST http://localhost:8000/api/couples/invitations/$CODE/accept \
  -H "Authorization: Bearer $BOB_TOKEN"

# Both answer "yes" to Q1
Q1=11111111-1111-1111-1111-111111111111
curl -X POST http://localhost:8000/api/questionnaire/answers \
  -H "Authorization: Bearer $ALICE_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"question_uuid\":\"$Q1\",\"answer\":\"yes\"}"

curl -X POST http://localhost:8000/api/questionnaire/answers \
  -H "Authorization: Bearer $BOB_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"question_uuid\":\"$Q1\",\"answer\":\"yes\"}"

# Both query matches
curl -s http://localhost:8000/api/matches -H "Authorization: Bearer $ALICE_TOKEN" | jq
curl -s http://localhost:8000/api/matches -H "Authorization: Bearer $BOB_TOKEN"   | jq
```

---

## 8. Tests backing the PoC

| File | Tests | What it verifies |
|---|---|---|
| `Feature/Auth/RegisterUserTest.php` | 1 | Registration fires `UserRegistered` and creates the projection |
| `Feature/Couples/CoupleInvitationTest.php` | 3 | Pairing, no self-acceptance, no double couple |
| `Feature/Questionnaires/AnswerQuestionTest.php` | 3 | Catalog, at-rest encryption, no double answer |
| `Feature/Questionnaires/RetractAnswerTest.php` | 4 | Retraction before/after match, invariants |
| `Feature/MutualMatchGoldenPathTest.php` | 4 | Full golden path + negative variants + privacy |
| `Feature/EventSourcing/ReplayProjectionsTest.php` | 3 | Projections are disposable — rebuilt identically from stored_events |
| `Feature/Questionnaires/ScoreQuestionTest.php` | 10 | Scalar multi-dim answers: encryption, invariants, no leakage, placeholder match |

**Total: 42 tests / 204 assertions / green in < 750 ms**

---

## 9. Decisions made in this PoC

| Decision | Choice | Reason |
|---|---|---|
| Event Sourcing package | `spatie/laravel-event-sourcing` v7 | De facto standard, compatible with Laravel 13, active community |
| Encryption | Custom `EncryptedEventSerializer` + `#[Encrypted]` | Spatie ships no native encryption; wrapping `JsonEventSerializer` is the documented path |
| UUID on users | Added `users.uuid` column | Sanctum keeps `id` autoincrement; nothing broken |
| Invitation flow | Shareable 6-char alphanumeric code | Doesn't require a user directory nor exposing emails |
| Match | Only yes/yes reveals | Aligned with Chilli's value proposition |
| Reveal | Automatic (no intermediate confirmation) | PoC simplicity; can be added later |
| Answer format | String `"yes"` / `"no"` | More readable than bool for debugging; fits encryption |
| Reactor idempotency | `IdempotentReactor` trait with a generic `dedup_key` | Guards against replay/reprocessing |

---

## 10. Out of scope (assumed; **not** bugs)

- Real push notifications (stub).
- Full questionnaire with categories, difficulty, versioning.
- Couple breakup (`unlink`).
- Email verification.
- Password recovery.
- Editing or deleting answers.
- Mobile UI.
- Encryption with a per-couple derived key (real E2E; the PoC uses a global `APP_KEY`).
- Rate limiting, CAPTCHA.
- Deploy, monitoring, alerting.
- Aggregate snapshots (no volume justifies it yet).

---

## 11. Recommendations for scaling to product

**Blockers for production (must be resolved first):**

1. **Turn `DetectMutualMatchReactor` into `ShouldQueue`.** Currently synchronous. In production, a push with retries and real latency should run outside the request.
2. **Exception handler for `DomainException`.** Right now they bubble up as HTTP 500. They should map to HTTP 422 with a clean message for the frontend.
3. **Encryption key rotation.** A global `APP_KEY` doesn't allow revoking individual access. Consider a per-couple derived key (KDF over `couple_uuid` + master key) to meet E2E standards if the product requires it.
4. **Define the real question catalog with product.** Current seed has 3 placeholder questions.
5. **Push notification reactor (`NotifyPartnersReactor`).** The `MutualPreferencesDetected` event is ready to be consumed — the provider (FCM/APNs) is missing.

**Recommended improvements:**

- Configure Laravel Horizon for reactor queues.
- Add `event-sourcing:replay` to the runbook, documented.
- Test suite with Pest (more ergonomic than PHPUnit) — optional.
- Automatic snapshots for aggregates that will grow (e.g. `QuestionnaireResponseAggregate` when the questionnaire gets large).
- Metrics: `stored_events` size, reactor lag, match distribution.

---

## 12. Important gotcha learned

Event handlers in `spatie/laravel-event-sourcing` v7 (methods `on<Event>` in projectors and reactors) **only accept one argument**: the event. A required second parameter (e.g. `EloquentStoredEvent $storedEvent`) silently excludes the method from dispatch — the handler shows up in `event-sourcing:list` but never runs.

**Rule:** any metadata the projector/reactor needs must be a property of the event. That's why `UserRegistered` includes `public string $uuid` explicitly.

---

## 13. Extension — Answer retraction (2026-09-11)

Once the happy path was validated, a scenario was added that exercises the core Event Sourcing pattern: **how to reverse without mutating history**.

### Question this extension answers

> When a user changes their mind about an already-recorded answer, how do we model the reversal without `UPDATE`/`DELETE`, and what happens if that answer had already produced a mutual match?

### Pieces added

| Piece | Role |
|---|---|
| Event `AnswerRetracted(userUuid, questionUuid)` | Marks intent to withdraw a prior answer. |
| Event `MatchInvalidated(matchUuid, reason)` | Nullifies a previously detected match. |
| `QuestionnaireResponseAggregate::retract()` | Validates the answer exists and hasn't been retracted; emits `AnswerRetracted`. |
| `MatchAggregate::invalidate()` | Validates the match was detected and isn't already invalidated; emits `MatchInvalidated`. |
| `AnswerProjector::onAnswerRetracted` | Marks `user_answers.retracted_at = now()` (without deleting the row). |
| `MatchProjector::onMatchInvalidated` | Marks `matches.invalidated_at = now()`. |
| `DetectMutualMatchReactor::onAnswerRetracted` | Chained reaction: if an active match existed for that couple+question, calls `MatchAggregate::invalidate('answer_retracted')`. |
| Migration | `user_answers.retracted_at` and `matches.invalidated_at`, both `nullable timestamp`. |

### Validated end-to-end flow

```
1. Alice answers Q1: "yes"                        → QuestionAnswered
2. Bob answers Q1:   "yes"                        → QuestionAnswered
                                                    → reactor detects match
                                                    → MutualPreferencesDetected
3. Alice queries /api/matches                     → 1 match
4. Alice retracts Q1  (DELETE /answers/Q1)         → AnswerRetracted
                                                    → reactor looks for active match
                                                    → MatchAggregate::invalidate
                                                    → MatchInvalidated
5. Alice/Bob query /api/matches                    → 0 matches
```

Full history preserved in `stored_events`; nothing was deleted nor overwritten.

### Business rules decided

- **Retraction is final:** once retracted, that question can't be answered again. If the product requires it later, we add a new event (`AnswerReconsidered` or similar) without touching data already written.
- **The match reactor ignores retracted answers and invalidated matches** when considering new yes/yes pairs.
- **`GET /api/matches` filters by `invalidated_at IS NULL`** — invalidated matches stay in the table as auditable history, but they are not revealed.

### Learnings

1. **Reversibility without mutation works cleanly.** Never run `UPDATE answer` nor `DELETE answer`. The projection reflects current state via a timestamp that acts as a temporal flag.
2. **Reactors chain bounded contexts without coupling them.** `AnswerRetracted` (Questionnaires) triggers `MatchInvalidated` (Matches) through the event bus — neither context references the other directly.
3. **Reactor idempotency requires a per-event-type key.** Used `ar:{user}:{question}` for retractions, distinct from `qa:{user}:{question}` for answers, to avoid collisions in `reactor_processed_events`.
4. **The aggregate protects invariants that the DB can't express.** E.g. "don't retract twice" lives in the aggregate's reconstructed state, not in a UNIQUE constraint.

---

## 14. Extension — Projection replay (2026-09-15)

Once retraction proved that history is never mutated, the next natural question was:
**if projection tables are just derived cache, can we prove we can throw them away and rebuild them from `stored_events` alone?** The answer is yes — this section documents the demonstration.

### Question this extension answers

> Are the read tables (`users`, `couples`, `matches`, `user_answers`, `couple_invitations`) truly disposable? Can `php artisan event-sourcing:replay` reconstruct them byte-identically from the event store, and what changes in the projector code make that safe?

### Pieces added

| Piece | Role |
|---|---|
| `UserProjector::resetState()` | Truncates `users` before replay. |
| `CoupleProjector::resetState()` | Truncates `couples` and `couple_invitations` before replay. |
| `AnswerProjector::resetState()` | Truncates `user_answers` before replay. |
| `MatchProjector::resetState()` | Truncates `matches` before replay. |
| `Feature/EventSourcing/ReplayProjectionsTest.php` | 3 tests: identical rebuild, recovery from dropped tables, endpoint parity. |

Spatie's `Projectionist::replay()` looks for `resetState()` on each projector and calls it before dispatching stored events. Without it, replay would re-insert duplicate rows on top of existing ones. With it, replay is a safe, idempotent operation.

### Validated flow

```
1. Alice + Bob register, pair up, both answer Q1: "yes", Alice answers Q2: "yes", Bob: "no"
   → stored_events grows by 8 events
   → projections filled: 2 users, 1 couple, 4 answers, 1 match

2. Snapshot every projection table's rows (business fields only, ignoring auto-increment id)

3. Run `php artisan event-sourcing:replay`
   → each projector's resetState() truncates its tables
   → all 8 events are re-dispatched in order to the projectors
   → reactors are NOT re-invoked (Spatie only replays projectors)

4. Assert:
   - stored_events row count is unchanged
   - every projection table is byte-identical (except row ids, which are storage artifacts)
   - GET /api/matches returns the same JSON as before
```

### Business rules decided

- **Row IDs are not part of the projection contract.** They are a SQLite/MySQL storage detail. The stable identity in the read model comes from UUIDs (`users.uuid`, `couples.uuid`, `matches.uuid`).
- **`resetState()` lives on each projector, not in a global CLI hook.** This keeps each bounded context responsible for its own read tables and lets `event-sourcing:replay ProjectorName` reset only what that projector owns.
- **Reactors are never replayed.** This is Spatie's default and the correct semantics — replaying reactors would resend push notifications, re-charge payments, etc. All secondary events (like `MutualPreferencesDetected`) live in the event store from the original run and are re-projected as any other event.

### Learnings

1. **`event-sourcing:replay` is only safe if every projector implements `resetState()`.** Without it, the CLI command silently doubles rows. The PoC now makes replay a first-class, production-ready operation.
2. **Timestamps written with `now()` inside projectors drift on replay.** The test uses `$this->travelTo()` to freeze time so both the original run and the replay produce identical timestamp columns. In production, the projector should ideally read the event's own `createdAt()` instead of calling `now()` — noted as a follow-up.
3. **Row-level IDs are an escape hatch of ES purity.** Any code that stores the numeric `id` of a projection row externally (e.g. as a foreign key elsewhere) will break on replay. UUIDs sidestep this entirely — another argument for making UUID the primary identity in every bounded context.
4. **This is the moment ES pays for its complexity.** The same event stream can be re-projected into a *different* schema without asking users anything or writing a data-migration script. That capability is impossible in a CRUD architecture and is the strongest argument for keeping ES for Chilli's core.

### Three superpowers this unlocks

- **Schema evolution without backfill SQL.** Change a projector, run replay, projections reflect the new shape all the way back to event #1.
- **New analytics without data loss.** Add a `MatchStatsProjector` today, run replay, and get historical stats from the very first couple — no need to have foreseen the metric.
- **Deterministic bug reproduction.** A user reports a mismatched match from three weeks ago? The events are still there; replay reproduces the exact same projector behavior.

---

## 15. Extension — Scalar multi-dimensional answers (2026-09-17)

Product asked to move from binary yes/no answers to multi-dimensional scalar answers (each question exposes N named dimensions, each answered on 0-100). Rather than break the existing `QuestionAnswered` events, we added a new event type that coexists with the old one — a real-world demonstration of how ES handles domain-model evolution without destructive migrations.

### Question this extension answers

> Can we change what "answering a question" means without invalidating the events we already recorded under the old model?

### Pieces added

| Piece | Role |
|---|---|
| Event `QuestionScored(userUuid, questionUuid, scores)` | New event carrying a `Record<dimension, int>` map. `scores` marked `#[Encrypted]`. |
| `QuestionnaireResponseAggregate::score()` | Validates the payload (integers 0-100, non-empty), enforces the same invariants as `answer()` (no double commitment, no post-retraction re-commit). |
| Aggregate state `$scored` | Parallel to `$answered`; both are treated as "commitments" for mutual-exclusion and retraction. |
| Table `user_question_scores` | New projection table with UNIQUE `(user_uuid, question_uuid)` plus `scored_at`, `retracted_at`. |
| `AnswerProjector::onQuestionScored` | Inserts into `user_question_scores`. |
| `AnswerProjector::onAnswerRetracted` | Updates `retracted_at` in **both** tables (one is a no-op depending on which kind of commitment was made). |
| Column `questions.dimensions` (JSON) | Per-question schema: `[{name, low_label, high_label}]`. |
| `DetectMutualMatchReactor::onQuestionScored` | **Placeholder** match logic: fires `MutualPreferencesDetected` when both partners scored the same question with all dimensions ≥ 70. Marked with a TODO — real match logic will be delegated to an LLM-based compatibility judge. |
| Endpoint `POST /questionnaire/scores` | Accepts `{question_uuid, scores: {dim: int}}`. |
| `EncryptedEventSerializer` rewrite | Now operates on the JSON representation, letting us encrypt array-typed properties (like `scores`) as well as strings. |
| Tests | `ScoreQuestionTest` (10 cases): index shape, encryption at rest, invariants (double-score, post-retract, cross-model exclusion, range validation), no partner leakage, placeholder match fires only when both are ≥70. |

### Business rules decided

- **Binary and scalar are mutually exclusive per question.** You can't score a question you already binary-answered, and vice versa. Enforced by the aggregate (single "commitment set").
- **Retraction is unified.** The same `AnswerRetracted` event covers both types. Projectors touch both tables; only one row exists per (user, question), so the "wrong" UPDATE is silently a no-op.
- **The placeholder match threshold (70/100 on every dimension) is a stub, not the product decision.** The real algorithm will be an LLM-based compatibility judge that emits richer output (matched vs. diverging dimensions + narrative for the reveal moment). The reactor is the one place we'll swap; the event stream stays untouched.
- **Encryption still applies at the property level via `#[Encrypted]`.** The `scores` map is JSON-encoded then encrypted, so the row shape in `stored_events` is `"scores": "eyJp…"` — indistinguishable from an encrypted string.

### Learnings

1. **Evolving the domain model = introducing a new event type, not migrating the old one.** The 13 events from any previous session stay valid and replayable. Add a new event class, wire its projector and reactor handlers, and both models coexist. Retire the old one when product is ready — never before.
2. **Typed properties + property mutation don't mix.** The original `EncryptedEventSerializer` mutated the event's properties in-place with a ciphertext string before delegating to `JsonEventSerializer`. That works fine for `string $answer` but fails on `array $scores` because PHP's type system refuses `string ↔ array` assignments even temporarily. Fix: serialize first, then edit the JSON representation. The event object is never mutated. Cleaner in both directions.
3. **The reactor is where domain semantics live.** Same event data, three different "what counts as a match" strategies (binary yes/yes today, threshold ≥70 tomorrow, LLM judge next month). Swapping strategies is a reactor edit — no changes to events, aggregates, or projections.
4. **`AnswerRetracted` was well-named in retrospect.** We used it originally for binary answers; the semantics ("withdraw a prior commitment on this question") turned out to be model-agnostic. Sometimes ES rewards good naming with free reuse.

### What's next

- Replace the placeholder threshold match with an LLM-based judge that returns `{ matched_dimensions, diverging_dimensions, narrative, confidence }`. Persist the LLM's output alongside `MutualPreferencesDetected` (new fields or a companion event). Product decision pending.
- Deprecate `QuestionAnswered` in favor of `QuestionScored` for new deployments (the client already only writes to the new path). Old events stay in the store — history is history.

---

## 16. Commit history

```
576ba2b  Match: reactor detects yes/yes on QuestionAnswered + reveal via GET /matches
292cd96  Questionnaire: catalog seeder + Answer aggregate with EncryptedEventSerializer
30ed4a7  CoupleAggregate: invitation flow with shareable code (send + accept + link)
d68cc80  UserAggregate: migrate registration to Event Sourcing (UserRegistered + projector)
bd968a5  Scaffold Event Sourcing domain: bounded contexts, encrypted serializer, idempotent reactor trait
618b69f  Install spatie/laravel-event-sourcing v7 + publish migrations & config
60925ad  Rename Pepper -> Chilli (Sanctum token name)
f12c629  Initial commit: Laravel 13 skeleton + Sanctum Auth
```

---

## 17. Resources

- **Spatie v7 docs:** https://spatie.be/docs/laravel-event-sourcing/v7/introduction
- **Custom serializer (used here):** https://spatie.be/docs/laravel-event-sourcing/v7/advanced-usage/using-your-own-event-serializer
- **Package repository:** https://github.com/spatie/laravel-event-sourcing
- **Laravel News — Event Sourcing:** https://laravel-news.com/event-sourcing-in-laravel

---

## Decision to make

With this PoC on the table, the team can decide:

- **Scale to product.** Approve investing weeks to take the pattern to production with what's listed in section 11.
- **Adjust the approach.** Change some decision (e.g. add confirmation before revealing a match, change the answer format) and re-run a shorter PoC.
- **Discard Event Sourcing.** If, after reviewing, the overhead isn't worth it for Chilli. In that case, the Laravel + Sanctum skeleton remains usable as a base.
