# Investment Ledger API

A Laravel REST API backend for tracking client cash and stock holdings — deposits, withdrawals, buys, and sells — for an investment firm that previously tracked this manually in spreadsheets.


### Overview

Each client has one account, in one currency. Every change to that account (deposit, withdrawal, buy, sell) is recorded as a new, immutable **movement**. Cash balance and instrument holdings are never stored directly — they are always recalculated from the full movement history, so they can never drift out of sync with reality.

Two hard rules are enforced on every write:
- A client can never spend/withdraw more cash than they have.
- A client can never sell more pieces of an instrument than they own.

If either rule would be broken, the request is rejected with a clear `422` message and nothing is written — the client's balance and holdings stay exactly as they were.

### Setup (step by step)

**Requirements:** PHP >= 8.4.1, Composer, SQLite extension (`pdo_sqlite`, `sqlite3`) enabled in `php.ini`.

```bash
composer install
```

Copy `.env.example` to `.env` if it doesn't exist yet, then set:
```
DB_CONNECTION=sqlite
```
(remove or comment out the other `DB_*` lines)

```bash
touch database/database.sqlite      # Windows: New-Item database\database.sqlite -ItemType File
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The API is now running at `http://127.0.0.1:8000`. Note: the root URL (`/`) on its own shows the default Laravel welcome page — this project has no frontend by design. All functionality lives under `/api/...`, e.g. `http://127.0.0.1:8000/api/clients` (see examples below). The seeder creates 3 sample clients (`Ana`, `Besa`, `Driton`) with real movements already in place, including a scenario matching this brief's own example (Ana: deposit 1000 → buy 5 @100 → sell 3 @120 → 860 cash, 2 shares left).

### Running the tests

```bash
php artisan test
```

Covers: the exact assignment scenario, rejecting an over-withdrawal, rejecting an over-sell, rejecting non-positive amounts, and the same two rules enforced through the actual HTTP API.

### Communication with the system

All endpoints accept and return JSON. Below: one example per endpoint — what you send, what you get back.

**Create a client**
```
POST /api/clients
Body:     { "name": "Ana" }
Response (201):
{
  "name": "Ana",
  "updated_at": "2026-09-04T15:40:08.000000Z",
  "created_at": "2026-09-04T15:40:08.000000Z",
  "id": 1
}
```

**List clients**
```
GET /api/clients
Response (200):
[
  { "id": 1, "name": "Ana", "created_at": "...", "updated_at": "..." },
  { "id": 2, "name": "Besa", "created_at": "...", "updated_at": "..." }
]
```

**Deposit**
```
POST /api/clients/1/deposit
Body:     { "amount": 1000 }
Response (201):
{
  "client_id": 1,
  "type": "deposit",
  "amount": "1000.00",
  "created_at": "...",
  "id": 1
}
```

**Withdraw**
```
POST /api/clients/1/withdraw
Body:     { "amount": 100 }
Response (201):
{ "client_id": 1, "type": "withdraw", "amount": "-100.00", "id": 4, "created_at": "..." }
```

**Buy an instrument**
```
POST /api/clients/1/buy
Body:     { "instrument": "AAPL", "quantity": 5, "price_per_unit": 100 }
Response (201):
{
  "client_id": 1,
  "type": "buy",
  "amount": "-500.00",
  "instrument": "AAPL",
  "quantity": 5,
  "price_per_unit": "100.0000",
  "id": 2,
  "created_at": "..."
}
```

**Sell an instrument**
```
POST /api/clients/1/sell
Body:     { "instrument": "AAPL", "quantity": 3, "price_per_unit": 120 }
Response (201):
{
  "client_id": 1,
  "type": "sell",
  "amount": "360.00",
  "instrument": "AAPL",
  "quantity": 3,
  "price_per_unit": "120.0000",
  "id": 3,
  "created_at": "..."
}
```

**Cash balance**
```
GET /api/clients/1/balance
Response (200):
{ "client_id": 1, "cash_balance": 860 }
```

**Holdings**
```
GET /api/clients/1/holdings
Response (200):
{ "client_id": 1, "holdings": { "AAPL": 2 } }
```

**Movement history**
```
GET /api/clients/1/movements
Response (200): [ ...all movements for this client, in order... ]
```

**Rejected movement (rule violation)**
```
POST /api/clients/1/withdraw
Body:     { "amount": 999999 }
Response (422):
{ "message": "Cannot withdraw 999999, client only has 860 in the account." }
```
Balance is unchanged after this response — nothing was written.

**Invalid input (validation)**
```
POST /api/clients/1/buy
Body:     { "instrument": "AAPL", "quantity": 0.5, "price_per_unit": 100 }
Response (422):
{
  "message": "The quantity field must be an integer.",
  "errors": { "quantity": ["The quantity field must be an integer.", "The quantity field must be at least 1."] }
}
```

### Why this way

**Cash balance and holdings are computed live, not stored.** I could have added a `balance` column on `clients` and updated it on every write, but that opens the door to exactly the problem described in the brief: if any write path forgets to update it, or two writes race each other, the stored number drifts away from the truth. Since every movement is already recorded, summing them on read gives a number that is always correct by construction — there's no second copy of the truth that can go stale.

**Every write goes through `LedgerService`, wrapped in `DB::transaction()` with `lockForUpdate()` on the client row.** Business rules (enough cash, enough shares) are checked and then acted on inside the same transaction, with the client row locked. Without the lock, two concurrent requests for the same client (e.g. two withdrawals fired at the same moment) could both read "sufficient balance" before either one writes, and both would go through — pushing the client into the negative. Locking the row means the second request has to wait until the first transaction finishes, so it re-checks the balance against the up-to-date state.

**Movements are append-only.** There's no update or delete route, and the model disables `updated_at` on purpose. This directly matches the brief's "notebook" analogy — once something is written, it stays, and the full history is always available for an audit trail. It also means the cash/holdings calculation is trustworthy: nothing could have been silently edited after the fact.

**Validation and business rules are kept separate.** `FormRequest` classes handle input shape (is it a number, is it required, is quantity a whole number) before the request ever reaches business logic. `LedgerService` only deals with domain rules (enough balance, enough shares). Separating them keeps each part focused on one job and makes it obvious where to look when something goes wrong — a `422` about bad input versus a `422` about a broken business rule are different failure modes, even though they return the same HTTP status.

**Rule violations are a dedicated exception (`InvalidMovementException`), not a generic error.** The controller catches only that specific exception and turns it into a calm `422` with a clear message. Anything else (an actual bug) is left to bubble up normally instead of being silently swallowed — I only want to catch failures I expect and know how to handle.

### Stack

Laravel (PHP), SQLite, REST API / JSON, PHPUnit for tests.

---

## Македонски

### Преглед

Секој клиент има една сметка, во една валута. Секоја промена на сметката (депозит, подигање, купување, продажба) се запишува како ново, непроменливо **движење**. Готовината и хартиите од вредност никогаш не се чуваат директно — секогаш се пресметуваат од целата историја на движења, така што никогаш не можат да излезат од синхронизација со реалноста.

Две тврди правила важат за секој запис:
- Клиентот никогаш не смее да потроши/извади повеќе готовина отколку што има.
- Клиентот никогаш не смее да продаде повеќе парчиња од некој инструмент отколку што поседува.

Ако кое било од овие правила би било прекршено, барањето се одбива со јасна `422` порака и ништо не се запишува — состојбата на клиентот останува точно иста како пред тоа.

### Поставување (чекор по чекор)

**Потребно:** PHP >= 8.4.1, Composer, вклучен SQLite екстензион (`pdo_sqlite`, `sqlite3`) во `php.ini`.

```bash
composer install
```

Копирај `.env.example` во `.env` доколку не постои, и постави:
```
DB_CONNECTION=sqlite
```
(избриши или закоментирај ги другите `DB_*` редови)

```bash
touch database/database.sqlite      # Windows: New-Item database\database.sqlite -ItemType File
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

API-то сега работи на `http://127.0.0.1:8000`. Забелешка: `http://127.0.0.1:8000/` сама по себе ја покажува стандардната Laravel почетна страница — овој проект нема фронтенд по дизајн. Целата функционалност е под `/api/...`, на пр. `http://127.0.0.1:8000/api/clients` (примери подолу). Сидерот креира 3 примерни клиенти (`Ana`, `Besa`, `Driton`) со реални движења веќе внесени, вклучувајќи сценарио идентично со примерот од самата задача (Ана: депозит 1000 → купува 5 по 100 → продава 3 по 120 → 860 готовина, 2 акции преостанати).

### Извршување на тестовите

```bash
php artisan test
```

Опфаќа: точниот сценарио од задачата, одбивање на подигање над состојбата, одбивање на продажба над количината, одбивање на непозитивни износи, и истите две правила проверени преку вистинското HTTP API.

### Комуникација со системот

Сите endpoint-и примаат и враќаат JSON. Подолу: по еден пример за секој endpoint — што се праќа, што се враќа.

**Креирање клиент**
```
POST /api/clients
Тело:     { "name": "Ana" }
Одговор (201):
{
  "name": "Ana",
  "updated_at": "2026-09-04T15:40:08.000000Z",
  "created_at": "2026-09-04T15:40:08.000000Z",
  "id": 1
}
```

**Листа на клиенти**
```
GET /api/clients
Одговор (200):
[
  { "id": 1, "name": "Ana", "created_at": "...", "updated_at": "..." },
  { "id": 2, "name": "Besa", "created_at": "...", "updated_at": "..." }
]
```

**Депозит**
```
POST /api/clients/1/deposit
Тело:     { "amount": 1000 }
Одговор (201):
{
  "client_id": 1,
  "type": "deposit",
  "amount": "1000.00",
  "created_at": "...",
  "id": 1
}
```

**Подигање**
```
POST /api/clients/1/withdraw
Тело:     { "amount": 100 }
Одговор (201):
{ "client_id": 1, "type": "withdraw", "amount": "-100.00", "id": 4, "created_at": "..." }
```

**Купување инструмент**
```
POST /api/clients/1/buy
Тело:     { "instrument": "AAPL", "quantity": 5, "price_per_unit": 100 }
Одговор (201):
{
  "client_id": 1,
  "type": "buy",
  "amount": "-500.00",
  "instrument": "AAPL",
  "quantity": 5,
  "price_per_unit": "100.0000",
  "id": 2,
  "created_at": "..."
}
```

**Продажба на инструмент**
```
POST /api/clients/1/sell
Тело:     { "instrument": "AAPL", "quantity": 3, "price_per_unit": 120 }
Одговор (201):
{
  "client_id": 1,
  "type": "sell",
  "amount": "360.00",
  "instrument": "AAPL",
  "quantity": 3,
  "price_per_unit": "120.0000",
  "id": 3,
  "created_at": "..."
}
```

**Состојба на готовина**
```
GET /api/clients/1/balance
Одговор (200):
{ "client_id": 1, "cash_balance": 860 }
```

**Хартии од вредност**
```
GET /api/clients/1/holdings
Одговор (200):
{ "client_id": 1, "holdings": { "AAPL": 2 } }
```

**Историја на движења**
```
GET /api/clients/1/movements
Одговор (200): [ ...сите движења за овој клиент, по редослед... ]
```

**Одбиено движење (прекршување на правило)**
```
POST /api/clients/1/withdraw
Тело:     { "amount": 999999 }
Одговор (422):
{ "message": "Cannot withdraw 999999, client only has 860 in the account." }
```
Состојбата останува непроменета по овој одговор — ништо не е запишано.

**Невалиден внес (валидација)**
```
POST /api/clients/1/buy
Тело:     { "instrument": "AAPL", "quantity": 0.5, "price_per_unit": 100 }
Одговор (422):
{
  "message": "The quantity field must be an integer.",
  "errors": { "quantity": ["The quantity field must be an integer.", "The quantity field must be at least 1."] }
}
```

### Зошто вака

**Состојбата на готовина и хартиите од вредност се пресметуваат во живо, а не се чуваат.** Можев да додадам колона `balance` во `clients` и да ја ажурирам при секој запис, но тоа го отвора токму проблемот опишан во задачата: ако некој пат на пишување заборави да ја ажурира, или два записи се судрат, зачуваниот број се оддалечува од вистината. Бидејќи секое движење веќе е запишано, собирањето при читање дава број што е секогаш точен по конструкција — нема втора копија на вистината што може да застари.

**Секој запис поминува преку `LedgerService`, обвиткан во `DB::transaction()` со `lockForUpdate()` на редот на клиентот.** Деловните правила (доволно готовина, доволно акции) се проверуваат и потоа се извршуваат во истата транзакција, со заклучен ред на клиентот. Без заклучувањето, две истовремени барања за истиот клиент (на пр. две подигања во ист момент) можат двете да прочитаат „доволна состојба" пред да запише некоја од нив, и двете би поминале — туркајќи го клиентот во минус. Заклучувањето на редот значи дека второто барање мора да чека додека првата транзакција не заврши, па повторно ја проверува состојбата наспроти ажурираната состојба.

**Движењата се append-only.** Нема рута за update или delete, а моделот намерно го оневозможува `updated_at`. Ова директно се совпаѓа со аналогијата „тетратка" од задачата — еднаш запишано, останува, а целата историја е секогаш достапна за проверка. Ова исто значи дека пресметката на готовина/хартии е доверлива — ништо не можело тивко да се промени накнадно.

**Валидацијата и деловните правила се одделени.** `FormRequest` класите го проверуваат обликот на внесот (дали е број, дали е задолжително, дали количината е цел број) пред барањето воопшто да стигне до деловната логика. `LedgerService` се занимава само со деловни правила (доволна состојба, доволно акции). Одделувањето ги држи двата дела фокусирани на по една работа и прави јасно каде да се гледа кога нешто ќе тргне наопаку.

**Прекршувањата на правилата се посебен exception (`InvalidMovementException`), а не генеричка грешка.** Контролерот фаќа само тој конкретен exception и го претвора во мирен `422` со јасна порака. Сè друго (вистински бug) се остава да излезе нормално наместо тивко да се проголта — сакам да фатам само грешки што ги очекувам и знам како да ги обработам.

### Технологии

Laravel (PHP), SQLite, REST API / JSON, PHPUnit за тестови.