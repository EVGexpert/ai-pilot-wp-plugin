# ANSS-CORE: ai-pilot-wp-plugin
## AI-Native System Specification — WordPress Plugin

**Версия:** 1.0
**Статус:** Active
**Дата:** 2026-06-23
**Проект:** AI Pilot WordPress Plugin
**Репозиторий:** github.com/EVGexpert/ai-pilot-wp-plugin
**Уровень:** CORE

---

## КАК ИСПОЛЬЗОВАТЬ ЭТОТ ДОКУМЕНТ

**Для агента:**
```
1. Прочитай весь документ
2. Прочитай GLOSSARY (2.1)
3. Запомни ИНВАРИАНТЫ (2.5)
4. Прочитай ПРИНЦИПЫ (2.6)
5. Agent Review (14) ПЕРЕД кодом
```

---

# [A] РАЗДЕЛ 1. КОНТЕКСТ

## 1.1 Суть проекта

**Что строим:** WordPress-плагин, который подключает сайт клиента к AI-оркестратору (auth-api). Предоставляет REST API для AI-агентов: чтение контекста, сканирование структуры, создание/редактирование контента.

**Проблема:** AI-агент должен иметь доступ к сайту — читать структуру, посты, плагины, и выполнять действия. WP REST API сам по себе недостаточно специфичен.

**Что НЕ делаем:**
- Собственный UI в админке
- Telegram-интеграцию
- Мультисайт (WP Network)
- SEO-оптимизацию

## 1.2 Ключевые возможности

- REST API эндпоинты под префиксом `/agent/`
- Connect Code генерация (одноразовый, 8 символов)
- Возврат структуры сайта (посты, страницы, плагины, тема, меню)
- Чтение/запись Agent Memory (через post meta / options API)
- Action Proposal механика (diff + approve/reject)
- Выполнение типовых действий (update_post, create_post, update_option)

---

# [A] РАЗДЕЛ 2. ПРЕДМЕТНАЯ ОБЛАСТЬ

## 2.1 Глоссарий

**Connect Code**
Определение: 8-символьный одноразовый код. Генерируется в админке WP → вводится в чате → привязывает сайт.
В коде: `wp_ajax_aipilot_connect_code`, `wp_ajax_nopriv_aipilot_verify_code`

**Agent Context**
Определение: GET /agent/context — возвращает site (title, URL) + soul + memory + structure + last_access.

**Agent Scan**
Определение: GET /agent/scan — сканирование: посты (последние 20), страницы, плагины, тема, меню, медиа, пользователи.

**Agent Memory**
Определение: История обращений AI. Хранится в post meta или options API.
В коде: `aipilot_agent_*` опции

**Action Proposal**
Определение: POST /agent/propose → создаёт запись с diff. POST /agent/approve/{id} → применяет.

**Allowlist опций**
Определение: Только 17 опций доступны AI-агенту для чтения/записи. Остальные — запрещены.

## 2.2 Пользователи

**Роль: WordPress Admin**
- Кто это: Владелец сайта
- Что делает: Генерирует connect code, одобряет действия AI
- Ожидаемое количество: 1 на сайт

**Роль: AI Agent (системная)**
- Авторизация: Bearer token из connect-code
- Может: читать контекст, сканировать, предлагать действия
- Не может: менять пароли, удалять плагины, редактировать users

## 2.4 Технологический стек

| Слой | Технология |
|---|---|
| Backend | PHP (WordPress Plugin) |
| REST API | WP REST API, кастомные роуты |
| Данные | WP Options API, Post Meta |
| Безопасность | nonce + capability check |

## [A] 2.5 ИНВАРИАНТЫ ✅

```
INV-001: Никаких SQL запросов напрямую
Нельзя: использовать $wpdb->query() без крайней необходимости
Причина: совместимость с будущими версиями WP
Проверка: все через WP API (get_posts, get_option, update_option)

INV-002: Allowlist опций
Нельзя: читать/писать произвольные опции WP
Причина: безопасность — некоторые опции раскрывают инфраструктуру
Проверка: функция проверки allowlist перед каждым get_option/update_option

INV-003: Action Proposal — только через API
Нельзя: выполнять действия без proposal + approve
Причина: human-in-the-loop
Проверка: approve/reject через API, not directly

INV-004: Verification Code не логируется
Нельзя: писать connect code в логи WP или error_log
Причина: защита от утечки
Проверка: sanitize перед любым выводом

INV-005: admin_email не возвращается в контексте
Нельзя: включать admin_email и другие sensitive поля в /agent/context
Причина: утечка данных
Проверка: allowlist на контекст

INV-006: Каждое действие требует capabilities
Нельзя: выполнять действия без проверки прав
Причина: безопасность
Проверка: current_user_can() перед каждым действием

INV-007: Connect Code одноразовый
Нельзя: использовать код дважды
Причина: защита
Проверка: удаление после verify
```

## [A] 2.6 АРХИТЕКТУРНЫЕ ПРИНЦИПЫ

```
AP-001: Simple > Clever
AP-004: Human Override Always Available
AP-006: Fail Loud
AP-008: Boring Technology Wins
AP-009: YAGNI
```

---

# [D] РАЗДЕЛ 3. ФУНКЦИОНАЛЬНЫЕ ТРЕБОВАНИЯ

## 3.1 Обзор

**Эндпоинты /agent/*:**
- GET /agent/context — полный контекст
- GET /agent/scan — сканирование
- GET /agent/memory — история
- POST /agent/memory — запись истории
- GET /agent/soul — ToV
- PUT /agent/soul — обновление ToV
- POST /agent/connect-code — создать код
- GET /agent/verify-code — проверить код
- POST /agent/propose — action proposal
- GET /agent/pending — список proposals
- POST /agent/approve/{id} — одобрить
- POST /agent/reject/{id} — отклонить
- POST /agent/action — выполнить действие

## 3.2 User Stories

```
US-001: Connect Code
Как [владелец сайта]
Я хочу [нажать кнопку в админке, получить код]
Чтобы [привязать сайт к чату, введя код]

Acceptance Criteria:
- [ ] Кнопка в админке WP
- [ ] Код 8 символов, TTL 5 минут
- [ ] Один код = одна привязка

US-002: AI управляет сайтом
Как [AI Agent]
Я хочу [читать контекст сайта и выполнять действия]
Чтобы [помогать клиенту через чат]

Acceptance Criteria:
- [ ] GET /agent/context возвращает структуру
- [ ] POST /agent/action применяет изменения
- [ ] Каждое действие требует proposal + approve
```

---

# [E] РАЗДЕЛ 5. АРХИТЕКТУРА

## 5.1 Обзор

**Тип:** WordPress Plugin (PHP)

**Диаграмма:**
```
[auth-api] → [WP Plugin REST API]
                 ↓
           [WordPress Core DB]
                 ↓
           [Posts, Options, Meta]
```

## 5.2 Ключевые эндпоинты

```
GET    /wp-json/agent/context
Auth: Bearer token
Response: { site: {name, url}, soul, memory, structure }

GET    /wp-json/agent/scan
Auth: Bearer token
Response: { posts, pages, plugins, theme, menus }

POST   /wp-json/agent/propose
Auth: Bearer token
Body: { action: string, description: string, params: {}, diff?: string }
Response: { id, status: "pending" }

POST   /wp-json/agent/approve/{id}
Auth: Bearer token
Response: { status: "approved", result: {} }
```

## 5.3 Что НЕ доступно AI

- wp-config.php
- Пользователи (users)
- Пароли
- Опции безопасности
- Плагины (установка/удаление)
- Email

---

# [E] РАЗДЕЛ 8. ТЕСТИРОВАНИЕ

## 8.1 Smoke-тесты

```
□ GET /wp-json/agent/context → 200
□ POST /agent/propose → 201 + id
□ POST /agent/approve/{id} → 200
□ Connect code генерируется
□ Verification code не в логах
```

---

# [A] РАЗДЕЛ 11. AI-FIRST

## 11.1 Forbidden Patterns

```
НЕ писать SQL напрямую (только WP API)
НЕ возвращать admin_email в контексте
НЕ выполнять действия без approve
НЕ трогать safe опции (не в allowlist)
```

## 11.2 Fail-Fast

```
АГЕНТ ОСТАНАВЛИВАЕТСЯ при:
□ Нарушении allowlist опций
□ Действии без proposal
□ SQL запросе мимо WP API
```

---

# [A] РАЗДЕЛ 14. AGENT REVIEW

```
ПРОМПТ ДЛЯ АГЕНТА:
"Прочитай ANSS-спецификацию. Найди противоречия,
отсутствующие edge cases, конфликты с инвариантами.
> 3 проблем → стоп."
```
