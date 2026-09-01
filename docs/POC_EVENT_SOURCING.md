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

## 5. Privacidad y cifrado

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

## 6. Cómo correr el PoC

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

## 7. Tests que respaldan el PoC

| Archivo | Tests | Qué verifica |
|---|---|---|
| `Feature/Auth/RegisterUserTest.php` | 1 | Registro dispara `UserRegistered` y crea projection |
| `Feature/Couples/CoupleInvitationTest.php` | 3 | Emparejamiento, no auto-aceptación, no doble pareja |
| `Feature/Questionnaires/AnswerQuestionTest.php` | 3 | Catálogo, cifrado at-rest, no doble respuesta |
| `Feature/MutualMatchGoldenPathTest.php` | 4 | Golden path completo + variantes negativas + privacidad |

**Total: 13 tests / 71 assertions / verde en < 400 ms**

---

## 8. Decisiones tomadas en este PoC

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

## 9. Fuera de alcance (asumido; **no** son bugs)

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

## 10. Recomendaciones para escalar a producto

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

## 11. Gotcha importante aprendido

Los handlers de eventos en `spatie/laravel-event-sourcing` v7 (métodos `on<Event>` en projectors y reactors) **solo aceptan un argumento**: el evento. Un segundo parámetro requerido (ej. `EloquentStoredEvent $storedEvent`) hace que el método se excluya silenciosamente del dispatch — el handler queda registrado en `event-sourcing:list` pero nunca ejecuta.

**Regla:** todos los metadatos que el projector/reactor necesite deben ser propiedades del evento. Por eso `UserRegistered` incluye `public string $uuid` explícitamente.

---

## 12. Historial de commits

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

## 13. Recursos

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
