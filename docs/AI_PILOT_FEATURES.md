# AI Pilot — Возможности плагина

## 🎯 Что это

WordPress-плагин, который превращает сайт в управляемого AI-агента. 
Работает в связке с **AI Pilot Gateway** (pilotsite.ru).

## 📡 API Эндпоинты

### Базовая информация
| Метод | Роут | Описание |
|-------|------|----------|
| GET | `/aipilot/v1/ping` | Проверка соединения |
| GET | `/aipilot/v1/site` | Информация о сайте |

### Контент — Стандартные CRUD
| Метод | Роут | Описание |
|-------|------|----------|
| GET | `/aipilot/v1/posts` | Список постов |
| POST | `/aipilot/v1/posts` | Создать пост |
| PUT | `/aipilot/v1/posts/{id}` | Обновить пост |
| DELETE | `/aipilot/v1/posts/{id}` | Удалить пост |
| GET | `/aipilot/v1/pages` | Список страниц |
| POST | `/aipilot/v1/pages` | Создать страницу |
| GET/POST | `/aipilot/v1/categories` | Категории |
| GET/POST | `/aipilot/v1/tags` | Теги |

### 🤖 Управление AI-агентом (новая функциональность)
| Метод | Роут | Описание |
|-------|------|----------|
| GET | `/aipilot/v1/structure` | Полная структура сайта для контекста агента |
| GET | `/aipilot/v1/overview` | Быстрый обзор (статистика) |
| POST | `/aipilot/v1/agent` | Универсальный: выполнить action |
| POST | `/aipilot/v1/agent/propose` | Предложить действие (human-in-the-loop) |
| GET | `/aipilot/v1/agent/pending` | Список ожидающих предложений |
| POST | `/aipilot/v1/agent/approve/{id}` | Утвердить предложение |
| POST | `/aipilot/v1/agent/reject/{id}` | Отклонить предложение |

## 🤖 Единый эндпоинт агента

`POST /aipilot/v1/agent` принимает:

```json
{
  "action": "create_post",
  "params": {
    "title": "Hello World",
    "content": "Post content...",
    "status": "draft",
    "categories": [1, 2],
    "tags": ["news"]
  }
}
```

Доступные `action`:
- `get_posts`, `get_post`, `create_post`, `update_post`, `delete_post`
- `get_pages`, `create_page`, `update_page`, `delete_page`
- `get_categories`, `create_category`
- `get_tags`, `create_tag`
- `get_menus`
- `get_theme`
- `get_plugins`
- `get_options`, `update_options`
- `search`

## 👤 Human-in-the-loop

1. Агент → `POST /agent/propose` → создаётся предложение
2. Клиент видит превью изменений
3. Клиент → `POST /agent/approve/{id}` → действие исполняется
4. Или → `POST /agent/reject/{id}` → предложение отклоняется

## 🔐 Аутентификация

Заголовок: `X-AI-Pilot-Token: ваш-токен`

Обратная совместимость: `X-OpenClaw-Token` (deprecated)

## ⚙️ Настройки

В админке WordPress: **Settings → AI Pilot**
- Генерация/сброс токена
- Управление правами (permissions)
- URL gateway (pilotsite.ru)
- Site ID

## 📦 Совместимость

Неймспейс: `aipilot/v1` (и `openclaw/v1` для обратной совместимости)
WP: 5.6+
PHP: 7.4+
