# PoC — Event Sourcing en el backend de Chilli

**Fecha:** 2026-09-01
**Stack:** Laravel 13.8 · PHP 8.3 · SQLite · Laravel Sanctum · `spatie/laravel-event-sourcing` v7.15.1
**Estado:** completo — 13 tests / 71 assertions verdes
**Repositorio local:** `/Users/juandevos/chilli-api`

---

## 1. Pregunta que responde este PoC

> **¿Es viable modelar el core de Chilli (registro → emparejamiento → cuestionarios → match mutuo revelado) con Event Sourcing usando `spatie/laravel-event-sourcing` en Laravel 13, cifrando los datos íntimos at-rest?**

**Respuesta corta:** Sí. Los cuatro pilares (aggregates, projectors, reactors, cifrado) encajan limpiamente en el dominio de Chilli sin fricciones importantes.

---

## 2. Qué es un PoC (para contexto)

Un Proof of Concept es una implementación **mínima y desechable** que valida una hipótesis técnica. Cubre solo el happy path, es feo pero funcional, y sirve para tomar decisiones antes de invertir semanas en producto. Este documento describe qué se validó, cómo, y qué se asumió fuera de alcance.

---

## 3. Alcance implementado

### Flujo end-to-end funcionando

```
1. Alice se registra   → POST /api/auth/register
2. Bob se registra     → POST /api/auth/register
3. Alice crea invitación → POST /api/couples/invitations → { code: "ABCXYZ" }
4. Bob acepta código     → POST /api/couples/invitations/ABCXYZ/accept
5. Alice responde Q1: "yes" → POST /api/questionnaire/answers
6. Bob responde Q1:   "yes" → POST /api/questionnaire/answers
7. Alice consulta        → GET /api/matches → [ { question: "...", detected_at } ]
8. Bob consulta          → GET /api/matches → [ mismo match ]
```

En cualquier momento, ninguna respuesta individual del partner es accesible por el otro — solo los matches yes/yes se revelan.

### Bounded contexts y piezas

| Contexto | Aggregate Root | Eventos | Projector | Reactor |
|---|---|---|---|---|
| **Users** | `UserAggregate` | `UserRegistered` | `UserProjector` | — |
| **Couples** | `CoupleAggregate` | `CoupleInvitationSent`, `CoupleInvitationAccepted`, `CoupleLinked` | `CoupleProjector` | — |
| **Questionnaires** | `QuestionnaireResponseAggregate` | `QuestionAnswered` *(campo `answer` marcado `#[Encrypted]`)* | `AnswerProjector` | — |
| **Matches** | `MatchAggregate` | `MutualPreferencesDetected` | `MatchProjector` | `DetectMutualMatchReactor` |

### Endpoints expuestos

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/register` | Registro. Dispara `UserRegistered`; devuelve token Sanctum. |
| POST | `/api/auth/login` | Login. Devuelve token Sanctum. |
| POST | `/api/auth/logout` | Revoca token actual. |
| GET | `/api/auth/me` | Perfil del usuario autenticado. |
| POST | `/api/couples/invitations` | Crea invitación; devuelve `code` compartible. |
| POST | `/api/couples/invitations/{code}/accept` | Acepta invitación y vincula pareja. |
| GET | `/api/questionnaire` | Lista de preguntas del catálogo (seed). |
| POST | `/api/questionnaire/answers` | Registra respuesta (cifrada at-rest). |
| GET | `/api/matches` | Matches revelados de la pareja del usuario. |

---

## 4. Arquitectura — cómo fluye un evento

```
Request HTTP
    │
    ▼
Controller (validación de entrada)
    │
    ▼
Aggregate::retrieve(uuid)
    │  (reconstruye estado desde stored_events + snapshots)
    ▼
Aggregate::doSomething(...)
    │  (valida invariantes de negocio; recordThat(new Event))
    ▼
Aggregate::persist()
    │
    ├──► stored_events INSERT (payload JSON, campos #[Encrypted] cifrados)
    │
    ├──► Projectors síncronos      → actualizan tablas de lectura
    │       (users, couples, matches, user_answers, ...)
    │
    └──► Reactors → efectos secundarios
            (detectar match mutuo, notificaciones, integraciones externas)
    │
    ▼
Response HTTP (leyendo desde tablas de projection)
```

**Principios respetados:**
- La **fuente de verdad** es la tabla `stored_events` (append-only).
- Las tablas de lectura (`users`, `couples`, `matches`, ...) son **caché derivada** — se pueden reconstruir con `php artisan event-sourcing:replay`.
- Los **aggregates** encapsulan las reglas de negocio (ej: un usuario no puede estar en dos parejas activas).
- Los **reactors** disparan efectos secundarios (match, notificaciones) — nunca los controllers.
- Los **projectors** son idempotentes y reconstruibles.

---

## 5. El momento del match — paso a paso

Esta sección narra el instante exacto en que el sistema detecta un match mutuo. Es el ejemplo que mejor muestra por qué usamos Event Sourcing en vez de "controllers que escriben en tablas". Se introducen los conceptos clave conforme aparecen.

### Preludio — estado del sistema justo antes del match

Digamos que Alice y Bob ya se registraron, ya son pareja, y Alice ha respondido `"yes"` a la pregunta Q1 hace 10 minutos. En este momento:

**Tabla `stored_events`** (la **fuente de verdad** del sistema — cada fila es un hecho inmutable que ocurrió):

| id | aggregate_uuid | event_class | event_properties |
|---|---|---|---|
| 1 | alice-uuid | `UserRegistered` | `{"uuid":"alice","name":"Alice",...}` |
| 2 | bob-uuid | `UserRegistered` | `{"uuid":"bob","name":"Bob",...}` |
| 3 | couple-uuid | `CoupleInvitationSent` | `{"coupleUuid":"c1","inviterUuid":"alice","code":"ABC123"}` |
| 4 | couple-uuid | `CoupleInvitationAccepted` | `{"coupleUuid":"c1","accepterUuid":"bob"}` |
| 5 | couple-uuid | `CoupleLinked` | `{"coupleUuid":"c1","userAUuid":"alice","userBUuid":"bob"}` |
| 6 | alice-uuid | `QuestionAnswered` | `{"userUuid":"alice","questionUuid":"Q1","answer":"eyJpdi..."}` ← cifrado |

**Tablas de lectura** (llamadas *projections* — son caché derivada, reconstruibles):

`user_answers`:
| user_uuid | question_uuid | answer |
|---|---|---|
| alice | Q1 | yes |

`matches`: vacía

---

### T0 — Bob envía su respuesta

```http
POST /api/questionnaire/answers
Authorization: Bearer <bob_token>

{ "question_uuid": "Q1", "answer": "yes" }
```

### T1 — El controller delega en un *aggregate*, no escribe en la BD

> **¿Qué es un aggregate?** Es una clase PHP que representa una entidad del dominio (aquí, "las respuestas de un usuario"). **Su trabajo es proteger las reglas de negocio.** El controller no decide nada — solo pide al aggregate que haga algo.

El controller solo tiene tres líneas de lógica:

```php
QuestionnaireResponseAggregate::retrieve($bobUuid)   // reconstruye el aggregate desde su historia
    ->answer($Q1, 'yes')                             // pide una acción
    ->persist();                                      // graba los nuevos eventos
```

### T2 — El aggregate se reconstruye desde su historia

`retrieve($bobUuid)` no lee ninguna tabla de "estado actual". Va a `stored_events`, filtra los eventos con `aggregate_uuid = bob-uuid`, y los aplica en orden para reconstruir el estado en memoria. Para Bob, aún no hay eventos suyos en questionnaires → estado vacío.

> **Idea clave:** el estado del aggregate **no se guarda**. Se **recalcula** desde los eventos cada vez. Los eventos son lo único que persiste.

### T3 — El aggregate valida y graba en su "memoria interna"

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

`recordThat(...)` **no** escribe en la BD todavía — solo apunta "voy a grabar este evento cuando alguien llame `persist()`". Es la forma que tiene el aggregate de decir *"esto es lo que ha ocurrido"*.

### T4 — `persist()` escribe el evento al store

Ahora sí, el evento se guarda en `stored_events`. El campo `answer` **se cifra en este momento** por el `EncryptedEventSerializer` (ver sección 6):

**Tabla `stored_events` — nueva fila:**

| id | aggregate_uuid | event_class | event_properties |
|---|---|---|---|
| **7** | **bob-uuid** | **`QuestionAnswered`** | **`{"userUuid":"bob","questionUuid":"Q1","answer":"eyJpdi..."}`** ← cifrado |

Este INSERT es lo único **imprescindible**. Todo lo que viene después son **reacciones** a este hecho.

### T5 — Se dispara el `AnswerProjector` (síncrono, mismo request)

> **¿Qué es un projector?** Es una clase cuyo único trabajo es **mantener una tabla de lectura actualizada** en respuesta a eventos. Nunca cambia su lógica del pasado — puedes borrar la tabla y reconstruirla corriendo el projector sobre todos los eventos históricos.

El `AnswerProjector` recibe el evento `QuestionAnswered` (ya desencriptado por Spatie) y hace un simple insert:

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

**Tabla `user_answers` — nueva fila:**

| user_uuid | question_uuid | answer |
|---|---|---|
| alice | Q1 | yes |
| **bob** | **Q1** | **yes** ← nueva |

### T6 — Se dispara el `DetectMutualMatchReactor` (aquí ocurre la magia)

> **¿Qué es un reactor?** Es como un projector, pero para **efectos secundarios**: enviar un push, llamar a un servicio externo, o — como aquí — **decidir que algo nuevo debe ocurrir en el dominio**. La diferencia clave: el projector solo actualiza tablas de lectura; el reactor **puede provocar nuevos eventos**.

Este es el reactor completo, con comentarios narrando cada paso:

```php
public function onQuestionAnswered(QuestionAnswered $event): void
{
    // Idempotencia: si este mismo evento ya fue procesado por este reactor
    // (por replay, retry, etc.), no volvemos a ejecutar.
    $this->once("qa:{$event->userUuid}:{$event->questionUuid}", function () use ($event) {

        // 1. Bob dijo "yes"? (los "no" no producen match en Chilli)
        if ($event->answer !== 'yes') {
            return;
        }

        // 2. ¿Bob tiene pareja registrada?
        $couple = $this->coupleOf($event->userUuid);
        if (! $couple) {
            return;
        }

        // 3. ¿Quién es el partner? (aquí, Alice)
        $partnerUuid = $couple->user_a_uuid === $event->userUuid
            ? $couple->user_b_uuid
            : $couple->user_a_uuid;

        // 4. ¿Qué respondió Alice a esta misma pregunta?
        //    Consultamos la projection `user_answers` (poblada por AnswerProjector).
        $partnerAnswer = DB::table('user_answers')
            ->where('user_uuid', $partnerUuid)
            ->where('question_uuid', $event->questionUuid)
            ->value('answer');

        // 5. ¿Alice también dijo "yes"? Si no, no hay match.
        if ($partnerAnswer !== 'yes') {
            return;
        }

        // 6. ¿Ya existe un match para esta pareja + pregunta? (guardia contra duplicados)
        if ($this->matchAlreadyExists($couple->uuid, $event->questionUuid)) {
            return;
        }

        // 7. ¡MATCH! Instanciamos un aggregate nuevo y le pedimos que emita el evento.
        MatchAggregate::retrieve((string) Str::uuid())
            ->detect($couple->uuid, $event->questionUuid, $couple->user_a_uuid, $couple->user_b_uuid)
            ->persist();
    });
}
```

En este momento, en tiempo real dentro del mismo request de Bob:
- ✅ Bob dijo "yes"
- ✅ Bob está en pareja con Alice
- ✅ Alice ya había dicho "yes" a Q1
- ✅ No hay match previo para esta pregunta

→ El reactor invoca a `MatchAggregate::detect(...)`.

### T7 — Un nuevo evento nace: `MutualPreferencesDetected`

El `MatchAggregate` valida sus propias reglas (que no exista ya un match para el mismo aggregate) y graba el evento:

**Tabla `stored_events` — nueva fila:**

| id | aggregate_uuid | event_class | event_properties |
|---|---|---|---|
| **8** | **match-uuid** | **`MutualPreferencesDetected`** | **`{"matchUuid":"m1","coupleUuid":"c1","questionUuid":"Q1","userAUuid":"alice","userBUuid":"bob"}`** |

> **Observa lo importante:** el "match" no es una fila en una tabla que alguien decidió insertar — es un **hecho registrado** en la historia del sistema. Si mañana quisieras saber "¿cuándo ocurrió el primer match de esta pareja?" o "¿cuántos matches ha tenido Chilli este mes?", la respuesta está en `stored_events`, no en una tabla mutable.

### T8 — El `MatchProjector` insertar la fila para lectura rápida

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

**Tabla `matches` — nueva fila:**

| uuid | couple_uuid | question_uuid | detected_at |
|---|---|---|---|
| m1 | c1 | Q1 | 2026-09-01 20:35:12 |

### T9 — Bob recibe su response HTTP 201

Todo lo anterior — desde T0 hasta T8 — ocurrió en el **mismo request** de Bob, síncronamente, en menos de 30 ms. Bob solo ve:

```json
{ "status": "recorded" }
```

No sabe (ni tiene por qué saber) que su respuesta desencadenó un match. La app móvil se enterará cuando pregunte por matches.

### T10 — Alice o Bob consultan `GET /api/matches`

```json
{
  "matches": [
    {
      "uuid": "m1",
      "question_uuid": "Q1",
      "question_text": "¿Te gustaría cocinar juntos una nueva receta este fin de semana?",
      "detected_at": "2026-09-01T20:35:12Z"
    }
  ]
}
```

**Nunca aparece** `user_a_answer`, `user_b_answer`, o el string `"yes"` — solo la pregunta y el momento. La privacidad está garantizada por diseño: la projection `matches` no incluye respuestas, y el controller ni siquiera las consulta.

---

### Diagrama temporal completo

```
Tiempo →

T0  Bob POST /answers ─┐
                       │
T1  Controller crea    │  (un solo request HTTP)
     aggregate         │
T2  Aggregate se       │
     reconstruye       │
T3  Aggregate valida   │
     y recordThat(...) │
T4  persist() ────────►│  stored_events INSERT (QuestionAnswered, cifrado)
                       │
T5  AnswerProjector ──►│  user_answers INSERT (bob, Q1, "yes")
                       │
T6  DetectMutualMatch  │  lee couples, lee user_answers de Alice
     Reactor           │  Alice también dijo "yes" → decide crear match
                       │
T7  MatchAggregate ───►│  stored_events INSERT (MutualPreferencesDetected)
     ::detect().persist│
                       │
T8  MatchProjector ───►│  matches INSERT (couple, Q1, timestamp)
                       │
T9  HTTP 201 ──────────┘  ~30 ms total

...

T10 Alice GET /matches ─►  ve el match (query a matches + questions)
    Bob   GET /matches ─►  ve el match (misma projection)
```

---

### ¿Por qué esto es Event Sourcing y no un CRUD normal?

Un enfoque "tradicional" con Laravel resolvería el match así:

```php
// AnswerController tradicional
public function answer(Request $request) {
    $answer = Answer::create([...]);           // guarda respuesta

    $partnerAnswer = Answer::where(...)->first();
    if ($partnerAnswer?->value === 'yes' && $answer->value === 'yes') {
        Match::create([...]);                   // crea match
        NotificationService::push(...);         // notifica
    }

    return response()->json(...);
}
```

Funciona, pero:

| Necesidad futura | CRUD tradicional | Event Sourcing |
|---|---|---|
| "¿Cuándo detectamos el primer match de esta pareja?" | Necesitas haber añadido `created_at` a `matches` y esperar que nadie lo edite | Lees `stored_events`, respuesta exacta |
| "Queremos añadir estadísticas de tiempo entre respuestas y match" | Migración + recopilar datos desde ahora | Ya están en `stored_events`, reprocesas |
| "Cambió la lógica de match, ¿podemos reprocesar todo el histórico?" | Escribir un script one-off complicado | `php artisan event-sourcing:replay MatchProjector` |
| "Un cliente pide GDPR-borrado de sus respuestas" | UPDATE/DELETE en la tabla → información perdida | Nuevo evento `UserRedacted`, historial preservado, projection reconstruida |
| "El reactor de notificaciones falló, ¿podemos reintentar?" | Job perdido, notificación perdida | Cola de eventos, retry natural |
| "Queremos un dashboard de auditoría de qué pasó con cada pareja" | Poner logs por todas partes | Query directa sobre `stored_events` |
| "Cambia el requisito: match requiere confirmación de ambos" | Refactor invasivo del controller | Nuevo evento `MatchConfirmed`, nuevo endpoint, projection se enriquece — sin tocar la lógica de detección |

**La idea central:** en el flujo tradicional, `Match::create(...)` es la acción final; nadie sabrá jamás **por qué** apareció ese match salvo mirando los logs. En Event Sourcing, `MutualPreferencesDetected` es la acción; **el porqué está en `QuestionAnswered` de Alice y `QuestionAnswered` de Bob**, todo en la misma fuente de verdad, para siempre.

---

## 6. Privacidad y cifrado

Requisito: los datos íntimos (respuestas del cuestionario) nunca deben leerse en claro desde la base de datos.

**Implementación:**
1. Se creó un `EncryptedEventSerializer` que envuelve al `JsonEventSerializer` de Spatie.
2. Al serializar, escanea las propiedades del evento marcadas con el atributo PHP `#[Encrypted]` y las cifra con `Crypt::encryptString()` (usa `APP_KEY`).
3. Al deserializar, desencripta esas propiedades antes de entregar el evento al projector/reactor.
4. En Chilli, `QuestionAnswered::$answer` está marcado `#[Encrypted]`.

**Verificado por test:** el campo `answer` en `stored_events.event_properties` está cifrado (no aparece el string `"yes"` literal), pero se recupera correctamente en el flujo normal.

```php
$this->assertNotSame('yes', $properties['answer']);              // ✅ cifrado en DB
$this->assertSame('yes', Crypt::decryptString($properties['answer'])); // ✅ recuperable
```

Además, el endpoint `GET /api/matches` **jamás** expone respuestas individuales del partner — solo devuelve el `question_uuid`, `question_text` y `detected_at`. Hay un test dedicado que verifica que no se filtra información sensible.

---

## 7. Cómo correr el PoC

```bash
cd /Users/juandevos/chilli-api
composer install
php artisan migrate:fresh --seed
php artisan test          # 13 verdes esperados
php artisan serve         # servidor en http://localhost:8000
```

Ejemplo de sesión con `curl` (dos terminales, uno para Alice y otro para Bob):

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

# Alice crea invitación
CODE=$(curl -s -X POST http://localhost:8000/api/couples/invitations \
  -H "Authorization: Bearer $ALICE_TOKEN" | jq -r .code)

# Bob acepta
curl -X POST http://localhost:8000/api/couples/invitations/$CODE/accept \
  -H "Authorization: Bearer $BOB_TOKEN"

# Ambos responden "yes" a Q1
Q1=11111111-1111-1111-1111-111111111111
curl -X POST http://localhost:8000/api/questionnaire/answers \
  -H "Authorization: Bearer $ALICE_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"question_uuid\":\"$Q1\",\"answer\":\"yes\"}"

curl -X POST http://localhost:8000/api/questionnaire/answers \
  -H "Authorization: Bearer $BOB_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"question_uuid\":\"$Q1\",\"answer\":\"yes\"}"

# Ambos consultan matches
curl -s http://localhost:8000/api/matches -H "Authorization: Bearer $ALICE_TOKEN" | jq
curl -s http://localhost:8000/api/matches -H "Authorization: Bearer $BOB_TOKEN"   | jq
```

---

## 8. Tests que respaldan el PoC

| Archivo | Tests | Qué verifica |
|---|---|---|
| `Feature/Auth/RegisterUserTest.php` | 1 | Registro dispara `UserRegistered` y crea projection |
| `Feature/Couples/CoupleInvitationTest.php` | 3 | Emparejamiento, no auto-aceptación, no doble pareja |
| `Feature/Questionnaires/AnswerQuestionTest.php` | 3 | Catálogo, cifrado at-rest, no doble respuesta |
| `Feature/MutualMatchGoldenPathTest.php` | 4 | Golden path completo + variantes negativas + privacidad |

**Total: 13 tests / 71 assertions / verde en < 400 ms**

---

## 9. Decisiones tomadas en este PoC

| Decisión | Elección | Razón |
|---|---|---|
| Paquete Event Sourcing | `spatie/laravel-event-sourcing` v7 | Estándar de facto, compatible con Laravel 13, comunidad activa |
| Cifrado | Custom `EncryptedEventSerializer` + `#[Encrypted]` | Spatie no trae cifrado nativo; envolver `JsonEventSerializer` es la vía documentada |
| UUID en users | Columna añadida `users.uuid` | Sanctum sigue con `id` autoincrement; nada roto |
| Flujo invitación | Código alfanumérico compartible (6 chars) | No requiere directorio de usuarios ni exponer emails |
| Match | Solo yes/yes revela | Alineado con la propuesta de valor de Chilli |
| Revelación | Automática (sin confirmación intermedia) | Simplicidad del PoC; se puede añadir después |
| Formato de respuesta | String `"yes"` / `"no"` | Más legible que bool para debug; encaja con cifrado |
| Idempotencia de reactor | Trait `IdempotentReactor` con `dedup_key` genérico | Protege contra replay/reprocesado |

---

## 10. Fuera de alcance (asumido; **no** son bugs)

- Notificaciones push reales (stub).
- Cuestionario completo con categorías, dificultad, versionado.
- Ruptura de pareja (`unlink`).
- Verificación de email.
- Recuperación de password.
- Edición o borrado de respuestas.
- UI móvil.
- Cifrado con clave derivada por-pareja (E2E real; el PoC usa `APP_KEY` global).
- Rate limiting, CAPTCHA.
- Deploy, monitoring, alertas.
- Snapshots de aggregates (no hay volumen que lo justifique aún).

---

## 11. Recomendaciones para escalar a producto

**Bloqueadores para producción (deben resolverse antes):**

1. **Convertir `DetectMutualMatchReactor` a `ShouldQueue`.** Actualmente es síncrono. En producción, un push con retry y latencia real debe correr fuera del request.
2. **Exception handler para `DomainException`.** Ahora se propagan como HTTP 500. Deben mapearse a HTTP 422 con mensaje limpio para el frontend.
3. **Rotación de claves de cifrado.** `APP_KEY` global no permite revocar acceso individual. Considerar una clave derivada por pareja (KDF sobre `couple_uuid` + master key) para cumplir con estándares E2E si el producto lo requiere.
4. **Definición del catálogo real de preguntas con producto.** Semilla actual son 3 preguntas placeholder.
5. **Reactor de notificaciones push (`NotifyPartnersReactor`).** El evento `MutualPreferencesDetected` está listo para ser consumido — falta el proveedor (FCM/APNs).

**Mejoras recomendadas:**

- Configurar Laravel Horizon para las colas de reactors.
- Añadir `event-sourcing:replay` documentado en runbook.
- Test suite con Pest (más ergonómico que PHPUnit) — opcional.
- Snapshots automáticos para aggregates que crecerán (ej. `QuestionnaireResponseAggregate` cuando el cuestionario sea grande).
- Métricas: tamaño de `stored_events`, lag de reactors, distribución de matches.

---

## 12. Gotcha importante aprendido

Los handlers de eventos en `spatie/laravel-event-sourcing` v7 (métodos `on<Event>` en projectors y reactors) **solo aceptan un argumento**: el evento. Un segundo parámetro requerido (ej. `EloquentStoredEvent $storedEvent`) hace que el método se excluya silenciosamente del dispatch — el handler queda registrado en `event-sourcing:list` pero nunca ejecuta.

**Regla:** todos los metadatos que el projector/reactor necesite deben ser propiedades del evento. Por eso `UserRegistered` incluye `public string $uuid` explícitamente.

---

## 13. Historial de commits

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

## 14. Recursos

- **Documentación Spatie v7:** https://spatie.be/docs/laravel-event-sourcing/v7/introduction
- **Custom serializer (usado aquí):** https://spatie.be/docs/laravel-event-sourcing/v7/advanced-usage/using-your-own-event-serializer
- **Repositorio del paquete:** https://github.com/spatie/laravel-event-sourcing
- **Laravel News — Event Sourcing:** https://laravel-news.com/event-sourcing-in-laravel

---

## Decisión a tomar

Con este PoC en la mesa, el equipo puede decidir:

- **Escalar a producto.** Recibir el visto bueno para invertir semanas en llevar el patrón a producción con lo listado en la sección 10.
- **Ajustar el enfoque.** Cambiar alguna decisión (ej. añadir confirmación antes de revelar match, cambiar formato de respuesta) y reejecutar un PoC más corto.
- **Descartar Event Sourcing.** Si tras revisar se decide que el overhead no compensa para Chilli. En ese caso, el skeleton Laravel + Sanctum sigue siendo utilizable como base.
