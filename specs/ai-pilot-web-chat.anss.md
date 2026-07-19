# ANSS-CORE: ai-pilot-web-chat
## AI-Native System Specification — Web Chat Frontend

**Версия:** 1.0
**Статус:** Active
**Дата:** 2026-06-23
**Проект:** AI Pilot Web Chat
**Репозиторий:** github.com/EVGexpert/ai-pilot-web-chat
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

**Что строим:** Веб-чат на Vue 3 для общения с AI-агентами, управляющими WordPress-сайтами. Двухпанельный интерфейс: список диалогов слева, чат справа.

**Проблема:** Клиентам нужен простой интерфейс для AI-ассистента. Никаких админок — только чат.

**Что НЕ делаем:**
- Мобильное приложение (нативные)
- Telegram-бота
- Админ-панель для управления пользователями
- File upload / drag-and-drop

## 1.2 Ключевые возможности

- JWT-аутентификация через auth-api
- Двухпанельный layout (sidebar + чат)
- Сайдбар со списком диалогов + выбор сайта
- Тёмная тема (по умолчанию)
- Action Preview: карточки с approve/reject для human-in-the-loop
- WebSocket через Gateway для реального времени
- Connect popup для привязки WP-сайта

---

# [A] РАЗДЕЛ 2. ПРЕДМЕТНАЯ ОБЛАСТЬ

## 2.1 Глоссарий

**Gateway API**
Определение: `/v1/chat/completions` эндпоинт OpenClaw Gateway. Основной канал AI-запросов.
В коде: gateway.js / WebSocket client

**Action Preview**
Определение: UI-компонент с diff изменения и кнопками approve/reject. Human-in-the-loop.
В коде: ActionPreview.vue

**Connect Popup**
Определение: Модальное окно для ввода connect-code и привязки WP-сайта.

## 2.2 Пользователи

**Роль: Client**
- Кто это: Владелец WP-сайта
- Что делает: Пишет AI, управляет сайтом
- Что ценит: Скорость, простоту, понятность
- Ожидаемое количество: до 100

**Роль: Admin**
- Кто это: Евгений
- Что делает: Видит все сайты, отладка
- Что ценит: Контроль

## 2.4 Технологический стек

| Слой | Технология | Версия |
|---|---|---|
| Frontend | Vue 3 + Vite | 3.x |
| UI | PrimeVUI | latest |
| State | Pinia | latest |
| WS | нативный WebSocket | — |
| Docker | multi-stage build | — |
| Proxy | Caddy → nginx → localhost:3000 | — |

**Внешние зависимости:**

| Сервис | Назначение | Fallback |
|---|---|---|
| auth-api | JWT, сайты, AI-прокси | offline-сообщение |
| Gateway | AI-ответы | offline-сообщение |

## [A] 2.5 ИНВАРИАНТЫ ✅

```
INV-001: JWT не хранится в localStorage
Нельзя: хранить JWT в localStorage/sessionStorage
Причина: XSS-уязвимость
Проверка: JWT только в памяти (Pinia store)

INV-002: Валидация на клиенте — не единственная
Нельзя: доверять только клиентской валидации
Причина: безопасность
Проверка: все запросы дублируют валидацию на сервере

INV-003: v-html только с DOMPurify
Нельзя: использовать v-html без санитизации
Причина: XSS из AI-ответов
Проверка: DOMPurify.sanitize() перед v-html

INV-004: Credentials не передаются в URL
Нельзя: передавать токены через query params
Причина: утечка через реферер и логи
Проверка: все токены в заголовках

INV-005: WebSocket reconnect
Нельзя: терять соединение без уведомления
Причина: UX — пользователь должен знать
Проверка: reconnect + toast-уведомление

INV-006: Тёмная тема — default
Нельзя: показывать светлую тему новому пользователю без выбора
Причина: дизайн-решение
Проверка: theme = dark при первом рендере
```

## [A] 2.6 АРХИТЕКТУРНЫЕ ПРИНЦИПЫ

```
AP-001: Simple > Clever
AP-003: Buy Before Build (PrimeVUI)
AP-004: Human Override Always Available
AP-006: Fail Loud
AP-008: Boring Technology Wins
AP-009: YAGNI
```

---

# [D] РАЗДЕЛ 3. ФУНКЦИОНАЛЬНЫЕ ТРЕБОВАНИЯ

## 3.1 Обзор

**Функциональные блоки:**
- Аутентификация (LoginForm): Must Have
- Двухпанельный чат (ChatWindow + Sidebar): Must Have
- AI-диалог (MessageBubble + Gateway): Must Have
- Выбор сайта (SiteSelector): Must Have
- Action Preview (ActionPreview): Should Have
- Connect Popup: Should Have
- Тёмная тема: Must Have

## 3.2 User Stories

```
US-001: Вход в чат
Как [client]
Я хочу [ввести email + password и войти]
Чтобы [начать общение с AI]

Acceptance Criteria:
- [ ] Форма логина при незагруженном состоянии
- [ ] После входа — сайдбар + чат
- [ ] Неверные данные → сообщение об ошибке
- [ ] Token в памяти, не в localStorage

US-002: Выбор сайта
Как [client с несколькими сайтами]
Я хочу [выбрать сайт из выпадающего списка]
Чтобы [AI знал с каким сайтом работать]

Acceptance Criteria:
- [ ] Селектор в сайдбаре / шапке
- [ ] У admin — все сайты (дедуплицированы по URL)
- [ ] У client — только свои

US-003: Action Preview
Как [client]
Я хочу [видеть карточку с описанием изменения + approve/reject]
Чтобы [не давать AI менять сайт без моего ведома]

Acceptance Criteria:
- [ ] Карточка с description + diff
- [ ] Approve → выполняется
- [ ] Reject → отклоняется
- [ ] Diff не содержит секретных данных
```

---

# [E] РАЗДЕЛ 5. АРХИТЕКТУРА

## 5.1 Обзор

**Тип:** SPA (Single Page Application)

**Диаграмма:**
```
[Браузер] → [Caddy: chat.pilotsite.ru]
                ↓
          [Docker: Nginx → /usr/share/nginx/html]
                ↓
     API: /v1/* → Caddy → auth-api:3001
     WS:  wss:// → Caddy → Gateway:18789
```

**Компоненты Vue:**
```
App.vue
├── Layout (двухпанельный)
│   ├── Sidebar
│   │   ├── SiteSelector
│   │   └── History list
│   └── ChatWindow
│       ├── MessageBubble (с ActionPreview)
│       └── TypingIndicator
├── LoginForm
└── ConnectPopup
```

## 5.2 Ключевые эндпоинты (auth-api)

```
POST /api/auth/login — вход
POST /api/auth/refresh — обновление токена
GET  /api/sites — список сайтов
POST /api/chat/send — отправка сообщения AI
GET  /api/chat/history — история диалога
```

## 5.3 WebSocket

- URL: wss://pilotsite.ru/
- Протокол: OpenAI compatible (Gateway)
- Токен: передаётся в теле сообщения HTTP API
- Reconnect: exponential backoff, не более 5 попыток
- TypingIndicator: true при ожидании AI

---

# [E] РАЗДЕЛ 8. ТЕСТИРОВАНИЕ

## 8.1 Smoke-тесты (после деплоя)

```
□ GET https://chat.pilotsite.ru → HTTP 200
□ Форма логина отображается
□ После логина — сайдбар + чат
□ Тёмная тема включена
□ no JS errors в console
```

---

# [A] РАЗДЕЛ 11. AI-FIRST

## 11.1 Конфигурация агентов

**Артефакты:**
```
/src — исходники Vue 3
docker/nginx.conf — конфиг Nginx
Dockerfile — multi-stage build
specs/ai-pilot-web-chat.anss.md — эта спецификация
```

**Forbidden Patterns:**
```
НЕ добавлять новые npm-зависимости без согласования
НЕ хранить JWT в localStorage
НЕ использовать v-html без DOMPurify
НЕ ломать тёмную тему
```

## 11.2 Fail-Fast

```
АГЕНТ ОСТАНАВЛИВАЕТСЯ при:
□ Нарушении инвариантов
□ Добавлении внешнего UI-фреймворка поверх PrimeVUI
□ Изменении архитектуры без согласования
```

---

# [A] РАЗДЕЛ 14. AGENT REVIEW

```
ПРОМПТ ДЛЯ АГЕНТА:
"Прочитай ANSS-спецификацию. Найди противоречия, 
отсутствующие edge cases, конфликты с инвариантами.
> 3 проблем → стоп."
```
