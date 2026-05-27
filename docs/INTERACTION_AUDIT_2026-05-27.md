# AI Pilot — Chat ↔ WP Plugin Interaction Audit

**Date:** 2026-05-27
**Plugin Version:** 2.1.1
**Auth API Version:** 0.3.0
**Scope:** Полный цикл взаимодействия веб-чата, auth-api и WordPress плагина

---

## 1. Архитектура взаимодействия

```
Web Chat (Браузер)
    │ POST /api/auth/login
    │ POST /api/chat/send
    ▼
Auth API (ai-pilot-auth, :3001)
    │ JWT авторизация
    │ Job Queue (worker loop, 2s)
    │ Audit Events
    ▼
Gateway (openclaw, :18789)
    │ /v1/chat/completions → DeepSeek V4 Flash
    ▼
Auth API (обработка ответа)
    │ POST /agent/propose (action proposal)
    │ POST /agent/approve/{id}
    ▼
WordPress Plugin (REST API)
    │ aipilot/v1/agent/*
    │ aipilot/v1/posts|pages|plugins|...
    ▼
WordPress Core
```

---

## 2. Аутентификация

### 2.1. Веб-чат → Auth API
- **Метод:** JWT (Bearer token)
- **Механизм:** `jsonwebtoken` → `jwt.sign({ sub, email, role }, JWT_SECRET, { expiresIn: '7d' })`
- **Проблем:** ✅ Нет — JWT стандартный, SECRET из ENV

### 2.2. Auth API → WordPress Plugin (X-AI-Pilot-Token)
- **Механизм:** `wp_hash(token)` → `wp_check_password()` через hash
- **Хранение:** Только hash в `aipilot_api_token_hash`, plaintext НЕ хранится
- **Ротация:** При каждом `/agent/connect-code` генерируется новый 64-символьный токен
- **Проблем:** ❌ **Токен передаётся в URL connect-попапа** — может засветиться в логах сервера/referer

### 2.3. Обратная совместимость
- Плагин поддерживает заголовки `X-AI-Pilot-Token` и `X-OpenClaw-Token`
- Роуты регистрируются в `aipilot/v1/` и `openclaw/v1/` (один callback)
- **Проблем:** ❌ Двойные роуты = двойная поверхность атаки

---

## 3. Отправка сообщения (chat/send)

### 3.1. Flow
```
Client → Auth API → Gateway → DeepSeek → Auth API (parse actions) → Response
```

### 3.2. Что происходит
1. Auth API проверяет JWT
2. Находит сайт по `siteUrl` (проверяет принадлежность пользователю)
3. Создаёт/использует существующую сессию
4. Берёт контекст из кэша (или фоново обновляет через job)
5. Шлёт запрос в Gateway с `[client:{siteUrl}]` префиксом
6. Gateway отправляет в DeepSeek
7. Ответ парсится на структурированные действия (action parse)
8. Сохраняется в БД + фоново синхронизируется с WP memory

### 3.3. Проблемы
- ❌ **Нет стриминга** — пользователь ждёт весь ответ DeepSeek (до 30s timeout)
- ❌ **Gateway token через config table** — может быть не синхронизирован с ENV
- ⚠️ **Префикс `[client:{siteUrl}]`** — субагент узнаёт сайт, но контекст может быть неполным

---

## 4. Action Proposals (Human-in-the-Loop)

### 4.1. Flow
```
DeepSeek → parseActions() → { actions: [...], answer }
    │ Клиент видит actions в UI
    │ Нажимает Approve
    ▼
Auth API: /actions/approve
    │ 1. Генерирует idempotency_key (SHA256)
    │ 2. Проверяет `action_requests` — дубли?
    │ 3. Ставит 'processing'
    │ 4. POST /agent/propose на WP (создаёт proposal)
    │ 5. POST /agent/approve/{id} на WP (выполняет)
    │ 6. Ставит 'completed' (или 'failed')
    ▼
WordPress Plugin → wp_insert_post / update_option / ...
```

### 4.2. Проблемы
- ❌ **Двойной propose → approve** — два сетевых запроса к WP для одного действия. При ошибке на шаге 2, proposal на WP висит бесконечно
- ❌ **Proposal не чистится** — `aipilot_agent_proposals` option растёт бесконечно
- ❌ **WP execute не расширяемый** — `switch` на 6 действий hardcoded (create_post, update_post, update_option, switch_theme, update_menu, activate_plugin)
- ✅ **Idempotency key** — теперь есть (схема v8, добавлен сегодня)

---

## 5. Контекст и память сайта

### 5.1. Структура контекста
```
GET /agent/context → { site, soul, memory, structure, scanned_at }
```

- **site** — bloginfo, url, wp_version, admin_email
- **soul** — tone_of_voice, rules, description
- **memory** — массив записей (action, summary, details), последние 100
- **structure** — posts, pages, plugins, theme, menus, users (кэш в options)
- **scanned_at** — timestamp последнего сканирования

### 5.2. Проблемы
- ❌ **admin_email в контексте** — утекает email админа сайта в AI-промпт
- ❌ **Memory хранится** как json-строка в `wp_options`, не шардится по сайтам
- ⚠️ **scan()** возвращает ВСЕ посты (posts_per_page = -1) — проблема на больших сайтах (1000+ постов)
- ❌ **Нет пагинации** нигде — ни posts, ни pages, ни users
- ❌ **Soul (ToV) хранится без аудита** — кто менял, когда — не видно

---

## 6. Система прав (Capabilities)

### 6.1. Реализация
```php
function aipilot_can($capability) {
    $caps = aipilot_get_capabilities();
    return !empty($caps[$capability]);
}
```
- 34 capabilities в `aipilot_get_core_capabilities()`
- Default: включены только чтение (read-only)
- Настройка через WP Admin → AI Pilot → Capabilities

### 6.2. Проблемы
- ❌ **`full_access` bypass** — проверка `aipilot_can($cap)` при true/false, но `full_access` не проверяется отдельно. `"if (!aipilot_can('full_access'))"` стоит на agent/scan и некоторых agent роутах. Работает, но не консистентно
- ❌ **Нет allowlist опций** — `/options` PUT может изменить ЛЮБУЮ опцию (wp_options). `update_option(sanitize_text_field($key), $value)` — опасно: blog_public, siteurl, admin_email и т.д.
- ❌ **Capability names разбросаны** — в плагине используется `aipilot_can('posts_read')`, а в auth-api проверяется `aipilot_verify_token_and_can('posts_read')` — хорошо, что match

---

## 7. Job Queue (Auth API side)

### 7.1. Типы jobs
| Job type | What it does | Retry |
|----------|-------------|-------|
| `refresh_context` | POST /agent/context, обновляет кэш | 1 попытка |
| `sync_wp_memory` | POST /agent/memory на WP | 3 попытки |

### 7.2. Проблемы
- ❌ **refresh_context падает** — в логах: `NOT NULL constraint failed: jobs.payload_json`. Где-то payload приходит без нужных полей
- ❌ **job handler'ы в chat.js** — зарегистрированы в chat.js, не в отдельном worker-файле. Если chatRoutes не загрузится — jobs встанут
- ❌ **Worker живёт в db.js** (теперь в `src/db/jobs.js`) — worker loop стартует при первом импорте db

---

## 8. Безопасность

### 8.1. Утечки
- ❌ **admin_email** — в контексте (response от `/agent/context`) уходит в LLM
- ❌ **wp-config.php доступ** — `/options` позволяет читать ЛЮБЫЕ опции, включая зашифрованные пароли и API-ключи
- ❌ **Self-update залогинен** — `/self-update` требует `plugins_update` capability, но если токен скомпрометирован, злоумышленник может обновить плагин до своей версии

### 8.2. Аудит
- Auth API пишет audit events в таблицу `audit_events`
- WordPress plugin **не пишет аудит** — нет лога изменений, сделанных AI Pilot
- ❌ Невозможно понять: "кто изменил заголовок сайта — админ через админку или AI?"

---

## 9. Рекомендации

### P0 — Критические
1. **Add allowlist for wp_options** — запретить AI менять blog_public, siteurl, admin_email, users_can_register и другие критичные опции
2. **Убрать admin_email из контекста** — не должен попадать в LLM

### P1 — Важные
3. **Ограничить scan() пагинацией** — posts_per_page не больше 50, возвращать total_pages
4. **Очистка proposal'ов** — удалять выполненные/отклонённые proposal'ы старше 24h
5. **Добавить audit log на WP side** — meta-box или custom post type для логов AI действий

### P2 — Улучшения
6. **Убрать двойные route namespaces** — оставить только `aipilot/v1/`, удалить `openclaw/v1/`
7. **Job queue handler'ы** — вынести из chat.js в отдельный workers-модуль
8. **Streaming (SSE)** — чтобы клиент не ждал полного ответа DeepSeek
9. **Capability `full_access` — сделать консистентной проверкой** везде, а не точечно

---

## 10. Сводка

| Метрика | Значение |
|---------|----------|
| Ручек REST API в плагине | ~50 (включая agent-роуты) |
| Из них публичных | 2 (ping, connect-code) |
| Job queue types | 2 (refresh_context, sync_wp_memory) |
| Capabilities | 34 |
| Action types (human-in-the-loop) | 6 |
| Idempotency keys | ✅ (schema v8) |
| Audit events (auth-api) | ✅ |
| Audit log (WP side) | ❌ |
| Allowlist WP options | ❌ |

**Общая оценка:** 🟡 Средняя — критических дыр нет, но P0-P1 выявлены.
