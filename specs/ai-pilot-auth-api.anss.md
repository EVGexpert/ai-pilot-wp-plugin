# ANSS-CORE: ai-pilot-auth-api
## AI-Native System Specification — Backend Auth & Orchestration API

**Версия:** 1.0
**Статус:** Active
**Дата:** 2026-06-23
**Проект:** AI Pilot Auth API
**Репозиторий:** github.com/EVGexpert/ai-pilot-auth-api
**Уровень:** CORE

---

## КАК ИСПОЛЬЗОВАТЬ ЭТОТ ДОКУМЕНТ

**Для человека:** Разделы 0-2 — архитектурные решения. Раздел 10 — Change Spec для изменений.

**Для агента:**
```
1. Прочитай весь документ целиком
2. Прочитай GLOSSARY (2.1) — это язык проекта
3. Запомни ИНВАРИАНТЫ (2.5) — их нельзя нарушать
4. Прочитай ПРИНЦИПЫ (2.6) — твой компас
5. Сделай Agent Review (14) ПЕРЕД написанием кода
6. Неопределённость → СТОП И СПРОСИ
```

---

# [A] РАЗДЕЛ 1. КОНТЕКСТ

## 1.1 Суть проекта

**Что строим:** Backend API-сервер на Express.js для аутентификации пользователей и оркестрации AI-агентов, управляющих WordPress-сайтами клиентов.

**Проблема которую решаем:** Клиентам нужен AI-ассистент для управления сайтами — без админок, без сложных интерфейсов, просто чат. Нужна единая точка: логин + роутинг запросов к AI + связь с WordPress.

**Что НЕ делаем в этой версии:**
- Telegram-канал (отложен)
- Собственный UI чата (делает web-chat)
- Аналитика и дашборды
- Multi-language поддержка (только русский)
- OAuth2 / SSO провайдеры

## 1.2 Ключевые возможности

- Email + password аутентификация (JWT)
- RBAC: admin / client
- Регистрация сайтов по connect-code
- Кэширование контекста сайтов (TTL 1 час)
- Идемпотентность запросов (action_requests table)
- Прокси к Gateway для AI-запросов
- Аудит безопасности

---

# [A] РАЗДЕЛ 2. ПРЕДМЕТНАЯ ОБЛАСТЬ

## 2.1 Глоссарий

**[A] Gateway (OpenClaw Gateway)**
Определение: AI-шлюз на базе OpenClaw, обрабатывающий LLM-запросы. Проксируется через auth-api.
В коде: `GATEWAY_URL` + `GATEWAY_TOKEN`

**[A] Connect Code**
Определение: Одноразовый 8-символьный код для привязки WordPress-сайта к аккаунту. TTL 5 минут.
НЕ путать с: JWT-token, API Key
В коде: `connect_codes` таблица

**[A] Site Context**
Определение: Кэш структуры WordPress-сайта (посты, плагины, тема, меню). Хранится в auth-api с TTL 1 час. Первоисточник — сам сайт через `/agent/context`.
В коде: `site_context_cache`

**[A] JWT Token**
Определение: Bearer token для аутентификации пользователя. Access + Refresh пара.
НЕ путать с: Gateway token (между сервисами)
В коде: `auth` middleware

**[A] Action Proposal**
Определение: Human-in-the-loop механика. AI предлагает действие → клиент подтверждает → выполняется на WP.
В коде: `action_requests` (action_proposals)

**[A] Idempotency Key**
Определение: SHA256 хеш запроса для защиты от дублей. Хранится в `action_requests`.
В коде: столбец `idempotency_key`, UNIQUE constraint

**[A] Gateway Token**
Определение: Shared secret между auth-api и OpenClaw Gateway. Не JWT, не user-токен.
НЕ путать с: JWT Token

## 2.2 Пользователи

**[A] Роль: Admin**
- Кто это: Владелец системы (Евгений)
- Что делает: Видит все сайты, управляет пользователями, мониторит
- Что ценит: Контроль, безопасность, стабильность
- Ожидаемое количество: 1

**[A] Роль: Client**
- Кто это: Владелец WordPress-сайта
- Что делает: Регистрирует сайт, общается с AI-агентом
- Что ценит: Простоту, скорость ответа, "магию"
- Ожидаемое количество: до 100
- Частота использования: daily

## 2.3 Ограничения

**Технические:**
- Node.js (Express.js) — не менять рантайм
- SQLite — не менять БД
- JWT для пользовательской аутентификации
- Gateway token для сервис-сервис
- Docker-окружение (не standalone)

**Бизнес:**
- Один человек (Евгений) — всё开发和
- Бюджет: минимальный (VPS + LLM API)

## 2.4 Технологический стек

| Слой | Технология | Версия |
|---|---|---|
| Backend | Node.js + Express.js | 24.x |
| База данных | SQLite (node:sqlite) | experimental |
| Аутентификация | JWT (access + refresh) | — |
| Прокси Gateway | HTTP fetch | — |
| Деплой | Docker + GitHub Actions | — |
| WebSocket | ws | — |
| Кэш | in-memory (Map) | — |

**Внешние зависимости:**

| Сервис | Назначение | Fallback |
|---|---|---|
| OpenClaw Gateway | LLM-запросы | offline — кэш |
| WordPress Plugin (на сайте) | Контекст сайта | кэш (TTL) |
| GitHub (CI/CD) | Деплой | ручной scp |

## [A] 2.5 ИНВАРИАНТЫ ✅

```
INV-001: Никаких паролей в открытом виде
Нельзя: хранить пароли plaintext, логировать пароли
Причина: P0 safety
Проверка: bcrypt.compare() при логине, bcrypt в БД

INV-002: Gateway Token не泄露
Нельзя: передавать Gateway token клиенту, логировать его
Причина: компрометация = полный доступ к LLM
Проверка: Gateway token только в server-side env

INV-003: Connect Code одноразовый
Нельзя: использовать один код дважды
Причина: защита от перехвата
Проверка: DELETE после первого use_tx

INV-004: Idempotency запросов
Нельзя: выполнить одно действие дважды
Причина: защита от дублей при network retry
Проверка: UNIQUE(idempotency_key), INSERT OR FAIL

INV-005: Не логировать чувствительные данные
Нельзя: писать в логи пароли, токены, verification codes
Причина: безопасность данных
Проверка: allowlist sensitive fields

INV-006: Graceful Shutdown
Нельзя: терять данные при рестарте
Причина: persistence
Проверка: SIGTERM → save() → close()

INV-007: Refresh Token Rotation
Нельзя: использовать refresh token дважды
Причина: защита от token theft
Проверка: SHA256 hash refresh, DELETE при использовании
```

## [A] 2.6 АРХИТЕКТУРНЫЕ ПРИНЦИПЫ ✅

```
AP-001: Simple > Clever
AP-002: Monolith First
AP-003: Buy Before Build
AP-004: Human Override Always Available [обязателен]
AP-005: No Vendor Lock-In
AP-006: Fail Loud
AP-007: Data Outlives Code
AP-008: Boring Technology Wins
AP-009: YAGNI
AP-010: Reversibility Over Efficiency

AP-011: Кэш — временный, сайт — истина
  Всегда проверять актуальность кэша. TTL 1 час.

AP-012: Агент НЕ меняет БД напрямую
  Только через API-слой. SQL-запросы только через db.js модули.
```

---

# [D] РАЗДЕЛ 3. ФУНКЦИОНАЛЬНЫЕ ТРЕБОВАНИЯ

## 3.1 Обзор функциональности

**Функциональные блоки:**
- Аутентификация (register, login, refresh, logout): Must Have
- Управление сайтами (connect, list, связь): Must Have
- AI-прокси (chat/send к Gateway): Must Have
- Action Proposals (human-in-the-loop): Should Have
- Аудит и безопасность: Must Have

## [D] 3.2 User Stories

```
US-001: Регистрация клиента
Как [client]
Я хочу [зарегистрироваться по email + password]
Чтобы [получить JWT и подключить сайт]

Acceptance Criteria:
- [ ] POST /api/auth/register → 201 + JWT
- [ ] POST /api/auth/register с существующим email → 409
- [ ] POST /api/auth/register без email → 400
- [ ] Пароль хешируется bcrypt

US-002: Connect Code
Как [client]
Я хочу [сгенерировать код в WP-админке и ввести его в чате]
Чтобы [привязать сайт к аккаунту]

Acceptance Criteria:
- [ ] POST /api/sites/connect → 201 + siteId
- [ ] POST /api/sites/connect с неверным кодом → 401
- [ ] POST /api/sites/connect с истёкшим кодом → 410
- [ ] Один код нельзя использовать дважды

US-003: Чат с AI
Как [client]
Я хочу [написать вопрос и получить ответ AI, учитывающий мой сайт]
Чтобы [управлять сайтом через чат]

Acceptance Criteria:
- [ ] POST /api/chat/send → 200 + ответ AI
- [ ] Ответ учитывает контекст сайта
- [ ] История сохраняется
- [ ] Если Gateway недоступен → 503
```

## 3.3 Детальные требования

```
МОДУЛЬ: Аутентификация (/api/auth)
Назначение: Регистрация, вход, refresh, logout
Связанные инварианты: INV-001, INV-005, INV-007

User Flow:
1. POST /api/auth/register — email + password → JWT pair
2. POST /api/auth/login — email + password → JWT pair
3. POST /api/auth/refresh — refresh_token → новый JWT pair
4. POST /api/auth/logout — удалить refresh_token
5. POST /api/auth/logout-all — удалить все refresh_token пользователя

Обработка ошибок:
- Неверный пароль → 401 { error: "INVALID_CREDENTIALS" }
- Email не найден → 401 { error: "INVALID_CREDENTIALS" }
- Refresh token expired → 401 { error: "TOKEN_EXPIRED" }
- Refresh token уже использован → 401 { error: "TOKEN_REUSE" }

МОДУЛЬ: Сайты (/api/sites)
Назначение: Управление сайтами пользователя
Связанные инварианты: INV-003, INV-005

User Flow:
1. POST /api/sites/connect — code + url → привязать сайт
2. GET /api/sites — список сайтов пользователя
3. POST /api/sites/scan — обновить кэш структуры сайта

Обработка ошибок:
- Код не найден → 401 code_invalid
- Плагин wp не отвечает → 502 wp_plugin_not_found
- Сайт недоступен → 502 site_unreachable
```

---

# [E] РАЗДЕЛ 4. НЕФУНКЦИОНАЛЬНЫЕ ТРЕБОВАНИЯ

## 4.1 Производительность

| Метрика | Норма | Пик |
|---|---|---|
| Response time p95 auth | < 200ms | < 500ms |
| Response time p95 chat (AI) | < 5s | < 15s |
| Throughput | 100 RPS | 300 RPS |

## 4.2 Надёжность

| Показатель | Значение |
|---|---|
| Uptime SLA | 99.5% |
| RTO | < 5 мин |
| RPO | < 1 час (бэкап БД) |

## 4.3 Performance Budget

- LLM response time p95: < 10с
- Токенов на запрос: ≤ 4096
- Стоимость на запрос: ≤ $0.01
- Месячный бюджет: ≤ $20

---

# [E] РАЗДЕЛ 5. АРХИТЕКТУРА

## 5.1 Обзор

**Тип:** Модульный монолит (Express.js роуты)

**Диаграмма:**
```
[WordPress Plugin] → [auth-api] ← [Web Chat]
                         ↓
                  [OpenClaw Gateway] → [DeepSeek V4 Flash]
                         ↓
                   [SQLite DB]

Socket.IO события:
[аутентифицированный клиент] ↔ [Gateway WebSocket]
```

**Слои приложения:**
```
/routes — роуты (thin)
/src/db — работа с БД
/src/middleware — auth, adminOnly
Config — config.js (DATABASE_PATH, JWT_SECRET, etc)
```

**Правила слоёв:**
- Роуты не содержат бизнес-логики, только валидация + вызов db
- Бизнес-логика в db.js модулях
- Middleware не делает запросов к БД без необходимости

## 5.2 Ключевые эндпоинты

```
POST   /api/auth/register
Auth: public
Body: { email: string, password: string, name?: string }
Response 201: { user: { id, email, name, role }, accessToken, refreshToken }
Response 400: { error: "VALIDATION_ERROR" }

POST   /api/auth/login
Auth: public
Body: { email: string, password: string }
Response 200: { user: { id, email, name, role }, accessToken, refreshToken }
Response 401: { error: "INVALID_CREDENTIALS" }

POST   /api/auth/refresh
Auth: public
Body: { refreshToken: string }
Response 200: { accessToken, refreshToken }
Response 401: { error: "TOKEN_EXPIRED" | "TOKEN_REUSE" }

POST   /api/sites/connect
Auth: Bearer JWT
Body: { code: string, url: string }
Response 201: { site: { id, url, userId } }
Response 401: { error: "code_invalid" }
Response 502: { error: "site_unreachable" }

POST   /api/chat/send
Auth: Bearer JWT
Body: { message: string, siteId?: string }
Response 200: { reply: string, conversationId: string }

GET    /api/health/db
Auth: X-Deploy-Token
Response 200: { status: "ok", users, sites, schema }
```

## 5.3 Схема данных (ключевые сущности)

```sql
-- Пользователи
CREATE TABLE users (
  id              TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
  email           TEXT NOT NULL UNIQUE,
  password_hash   TEXT NOT NULL,
  name            TEXT DEFAULT '',
  role            TEXT NOT NULL DEFAULT 'client' CHECK(role IN ('admin','client')),
  created_at      TEXT DEFAULT (datetime('now')),
  updated_at      TEXT DEFAULT (datetime('now'))
);

-- Refresh токены
CREATE TABLE refresh_tokens (
  id              TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
  user_id         TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash      TEXT NOT NULL UNIQUE,
  expires_at      TEXT NOT NULL,
  created_at      TEXT DEFAULT (datetime('now'))
);

-- Сайты
CREATE TABLE sites (
  id              TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
  user_id         TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  url             TEXT NOT NULL,
  wp_token        TEXT,
  created_at      TEXT DEFAULT (datetime('now')),
  UNIQUE(user_id, url)
);

-- Connect коды
CREATE TABLE connect_codes (
  id              TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
  code            TEXT NOT NULL UNIQUE,
  site_url        TEXT,
  expires_at      TEXT NOT NULL,
  used_at         TEXT,
  created_by_ip   TEXT
);

-- Action Proposals
CREATE TABLE action_requests (
  id              TEXT PRIMARY KEY,
  user_id         TEXT NOT NULL,
  site_id         TEXT,
  idempotency_key TEXT NOT NULL UNIQUE,
  action_data     TEXT NOT NULL,
  status          TEXT DEFAULT 'pending',
  created_at      TEXT DEFAULT (datetime('now'))
);
```

## 5.4 ADR

```
ADR-001: SQLite вместо PostgreSQL
Контекст: Проект для одного человека с VPS, нагрузка низкая
Решение: node:sqlite (экспериментальный, Node 24)
Причина: Zero ops, не нужно ставить и мониторить БД
Trade-offs: Нет репликации, конкурентность хуже
Для агентов: Всегда проверять что node:sqlite доступен

ADR-002: Frontend отдельно от API
Контекст: Web-chat живёт отдельно в Docker-контейнере
Решение: Два Docker-контейнера, общаются через Gateway API
Причина: Разделение ответственности, CI/CD независимо
Для агентов: Не встраивать фронтенд в auth-api

ADR-003: Gateway Token как shared secret
Контекст: Нужно авторизовать запросы к Gateway
Решение: Env-переменная GATEWAY_TOKEN, передаётся в заголовке
Причина: Проще чем mTLS, достаточно для одного сервиса
Для агентов: Gateway token никогда не идёт клиенту
```

---

# [E] РАЗДЕЛ 6. БЕЗОПАСНОСТЬ

## 6.1 Аутентификация и авторизация

- Метод: пароль + JWT (access 15min, refresh 7d)
- Авторизация: RBAC (admin/client) через middleware
- Refresh token rotation: SHA256 хеш, одноразовые

**Матрица прав:**

| Операция | admin | client |
|---|---|---|
| GET /api/stats | ✓ | ✗ |
| GET /api/users | ✓ | ✗ |
| GET /api/sites | ✓ | только свои |
| POST /api/chat/send | ✓ | ✓ |
| POST /api/sites/connect | ✓ | ✓ |

## 6.2 Защита данных

- Шифрование at rest: SQLite (файловая система)
- Шифрование in transit: TLS (Caddy → Docker)
- Чувствительные данные: пароли (bcrypt), токены, connect codes
- **Никогда не логировать:** пароли, JWT токены, Gateway token, connect codes

## 6.3 Compliance

- Персональные данные: да (email, имя) → GDPR-like
- AI-система: да (AI Pilot для управления сайтами)

---

# [E] РАЗДЕЛ 7. ИНФРАСТРУКТУРА

## 7.1 Окружения

| Окружение | Данные | Доступ |
|---|---|---|
| Development | синтетические | локально |
| Production | реальные | VPS (Docker) |

## 7.2 Деплой

- Провайдер: VPS (193.176.78.35, Калининград)
- Стратегия: GitHub Actions → SSH → docker compose
- Rollback: git revert + redeploy; pre-backup БД перед деплоем
- Healthcheck: POST /api/health/db с X-Deploy-Token

## 7.3 Мержи и CI

- Ветки: master (production) / feature-ветки
- CI: GitHub Actions (deploy.yml)
- Проверки: pre-backup, healthcheck после деплоя, rollback при пустой БД

---

# [A] РАЗДЕЛ 10. CHANGE SPECIFICATION

*Заполняется перед каждым изменением (через раздел 10 шаблона).*

---

# [A] РАЗДЕЛ 11. AI-FIRST

## 11.1 Конфигурация агентов

**Артефакты проекта:**
```
/repos/ai-pilot-auth-api/ — код
docs/specs/ai-pilot-auth-api.anss.md — эта спецификация
TOOLS.md — инфраструктурные константы
```

**Иерархия правил:**
```
1. ANSS-спецификация проекта → высший приоритет
2. Текущий промпт сессии → оперативные инструкции
Нижний уровень НЕ отменяет верхний.
```

**Forbidden Patterns:**
```
НЕ менять БД напрямую без API-слоя
НЕ использовать require() для npm-пакетов без согласования
НЕ убирать idempotency keys
НЕ инлайнить секреты в код
НЕ менять миграции БД без Change Spec
```

## 11.2 Fail-Fast условия

```
АГЕНТ ОСТАНАВЛИВАЕТСЯ при:
□ Нарушении любого инварианта (2.5)
□ Задаче вне этой спецификации
□ Противоречии между API-эндпоинтами
□ YAGNI violation

АГЕНТ ДЕЙСТВУЕТ САМОСТОЯТЕЛЬНО при:
□ Деталях реализации конкретного эндпоинта
□ Форматировании, именовании
□ Паттернах следующих из существующего кода
```

---

# [A] РАЗДЕЛ 14. AGENT REVIEW

*Выполняется перед каждым внесением кода.*

```
ПРОМПТ ДЛЯ АГЕНТА:
"Прочитай ANSS-спецификацию проекта и найди:
1. Противоречия между разделами
2. Неописанные ситуации
3. Edge cases
4. Конфликты с инвариантами (2.5)
5. Конфликты с принципами (2.6)

Если > 3 проблем → стоп."
```
