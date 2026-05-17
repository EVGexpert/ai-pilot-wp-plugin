# AI Pilot Chat — Результаты разведки

Подготовлено: 2026-05-16 23:00 (Евгений: спокойной ночи ☕)

## 🔌 Как чат общается с OpenClaw Gateway

Gateway использует **WebSocket протокол** (не REST).

### Подключение
```json
{
  "type": "req",
  "id": "1",
  "method": "connect",
  "params": {
    "role": "operator",
    "scopes": ["operator.read", "operator.write"],
    "auth": { "token": "..." },
    "locale": "ru-RU"
  }
}
```

### Отправка сообщения (RPC метод `chat.send`)
```json
{
  "type": "req",
  "id": "2",
  "method": "chat.send",
  "params": {
    "content": "Напиши пост про скидки"
  }
}
```

### Получение истории (`chat.history`)
```json
{
  "type": "req",
  "id": "3",
  "method": "chat.history",
  "params": { "limit": 50 }
}
```

### Инжект сообщения от агента (`chat.inject`)
```json
{
  "type": "req",
  "id": "4",
  "method": "chat.inject",
  "params": { "content": "...", "role": "assistant" }
}
```

### Стриминг ответа
Ответ приходит чанками (события `agent` с `type: reasoning_block` / `text_block`).

## 📦 Готовая библиотека: openclaw-webchat

Существует npm-пакет `openclaw-webchat` (автор raw34).

### Для Vue
```bash
npm install openclaw-webchat-vue
```

**ChatWidget (готовый компонент):**
```vue
<script setup>
import { ChatWidget } from 'openclaw-webchat-vue'
import 'openclaw-webchat-vue/style.css'
</script>
<template>
  <ChatWidget
    gateway="wss://pilotsite.ru"
    token="your-token"
    position="bottom-right"
    theme="light"
    title="AI Pilot"
  />
</template>
```

**useOpenClawChat (composable для кастомного UI):**
```vue
<script setup>
import { useOpenClawChat } from 'openclaw-webchat-vue'

const { messages, isConnected, isLoading, streamingContent, send, getHistory, inject } = useOpenClawChat({
  gateway: 'wss://pilotsite.ru',
  token: 'your-token',
})
</script>
```

### Что даёт из коробки:
- ✅ WebSocket подключение + авто-реконнект
- ✅ Стриминг ответов (streamingContent)
- ✅ История сообщений
- ✅ TypeScript
- ✅ Light/dark темы
- ❌ **Нет multi-site** (самому делать)
- ❌ **Нет action preview** (самому делать)
- ❌ **Нет JWT-авторизации** (использует gateway token)

## 🧩 PrimeVue vs Naive UI

| Критерий | PrimeVue | Naive UI |
|----------|----------|----------|
| Компонентов | ~90 | ~80 |
| Чат-компоненты | Есть InputText, Button, Card, Dialog, Avatar | Есть NInput, NButton, NCard, NModal |
| Tree-shaking | Да | Да |
| Размер (min) | ~300KB | ~200KB |
| Документация | Отличная, на английском | Хорошая, есть китайский |
| Темы | Material/ Bootstrap / Custom | CSS variables |
| Сообщество | Очень большое | Среднее |
| **Вердикт** | **PrimeVue** (популярнее, больше примеров) | Naive UI (чуть легче) |

**Рекомендация:** PrimeVue — больше готовых компонентов, лучше docs, проще найти решение.

## 📋 План компонентов (уточнение)

```
web-chat/
├── src/
│ ├── components/
│ │ ├── ChatWindow.vue           ← основной контейнер
│ │ │   └── использует useOpenClawChat (openclaw-webchat-vue)
│ │ ├── MessageBubble.vue        ← сообщение (текст / превью)
│ │ ├── ActionPreview.vue        ← карточка действия (с кнопками ✅❌)
│ │ ├── SiteSelector.vue         ← выпадающий список сайтов
│ │ ├── TypingIndicator.vue      ← "Агент думает..."
│ │ └── LoginForm.vue            ← JWT-логин
│ ├── stores/
│ │ ├── chatStore.js             ← обёртка над useOpenClawChat
│ │ ├── authStore.js             ← JWT-токен
│ │ └── sitesStore.js            ← список сайтов клиента
│ ├── api/
│ │ ├── gateway.js               ← openclaw-webchat config
│ │ └── orchestrator.js          ← Axios к нашему API
│ └── App.vue
```

## 🔄 Архитектура (как связать с gateway)

```
[Браузер клиента]
    │
    ├── WebSocket ─────────► Gateway (OpenClaw)
    │   (чат, стриминг)
    │
    └── HTTP (Axios) ─────► Gateway API
        (список сайтов,    или наш backend
         логин, настройки)  на pilotsite.ru

```

### Варианты архитектуры

**А) Всё через Gateway** (проще)
- Используем `openclaw-webchat-vue` напрямую
- Gateway проксирует запросы
- Клиент авторизуется через gateway token
- Минус: нужно настраивать multi-site через контекст

**Б) Gateway + Backend** (правильнее)
- Отдельный Node.js/PHP бэкенд на pilotsite.ru
- Backend управляет пользователями, JWT, списком сайтов
- Gateway используется только для AI-диалогов
- Чат через `openclaw-webchat-vue` к gateway
- Логин/JWT через наш бэкенд

## 🚀 Рекомендуемый стек (итоговый)

| Компонент | Технология | Причина |
|-----------|-----------|---------|
| Фреймворк | **Vue 3 + Vite** | По ТЗ |
| Чат | **openclaw-webchat-vue** | Готовый WebSocket, стриминг, composable |
| UI-кит | **PrimeVue** | Богаче компоненты, больше комьюнити |
| Стейт | **Pinia** | По ТЗ |
| HTTP | **Axios** | По ТЗ |
| Бэкенд | **На Gateway** (схема А) | MVP быстрее |
| Docker | **Vite dev server → Caddy** | На VPS рядом с gateway |

## 📌 Что нужно реализовать (чего нет в openclaw-webchat)

1. **SiteSelector** — переключение между сайтами
   - Хранить `site_id` в Pinia
   - При смене сайта: inject контекст в чат (структура сайта)
   
2. **ActionPreview** — превью действий
   - Отслеживать сообщения с `action_preview` типом
   - Показывать карточку с содержимым + кнопки

3. **LoginForm** — JWT-авторизация
   - Или используем gateway token напрямую (проще для MVP)

4. **Auto-scroll + infinite scroll history**

5. **Интеграция с WP плагином**
   - При выборе сайта → GET /aipilot/v1/structure → inject в контекст

## Ссылки

- Документация Gateway protocol: https://docs.openclaw.ai/gateway/protocol
- openclaw-webchat: https://github.com/raw34/openclaw-webchat
- openclaw-webchat-vue: https://npmjs.com/package/openclaw-webchat-vue
- PrimeVue: https://primevue.org
