# ANSS-CORE: AI Pilot Dual-Mode Architecture v1.1.0

## AI-Native System Specification

**Версия:** 1.1.0
**Статус:** Active
**Дата:** 2026-07-19
**Проект:** AI Pilot — Dual-Mode WordPress Integration
**Уровень:** CORE
**Принял пакет:** `ai-pilot-openclaw-prompts-v1.1.0`

**Компонентные спецификации:**
- [`ai-pilot-wp-plugin.anss.md`](./ai-pilot-wp-plugin.anss.md) — WordPress Remote Site API
- [`ai-pilot-auth-api.anss.md`](./ai-pilot-auth-api.anss.md) — Auth API / Mode Router
- [`ai-pilot-web-chat.anss.md`](./ai-pilot-web-chat.anss.md) — Frontend Chat UI

---

# [A] РАЗДЕЛ 1. КОНТЕКСТ

## 1.1 Суть архитектуры

**Что строим:** Dual-mode систему, где AI Pilot работает с ЛЮБЫМ WordPress-сайтом. `AI Pilot Blocks` — опциональное расширение, а не требование.

**Принцип:** Отсутствие опционального расширения не является ошибкой. Сайт без AI Pilot Blocks полностью функционален для AI-помощника.

**Компоненты:**
1. `AI Pilot Remote Site API` (WP плагин) — обязательное ядро
2. `auth-api` — оркестратор, Mode Router
3. `web-chat` — фронтенд, Agent UI Cards
4. `AI Pilot Blocks` — опциональное расширение
5. OpenClaw prompts v1.1.0 — 8 ролей

## 1.2 Режимы authoring

| Режим | Условие | Описание |
|-------|---------|----------|
| `aipilot_blocks` | Extension available | Типизированные компоненты + валидация |
| `gutenberg_core` | Default | Core/third-party Gutenberg blocks |
| `classic_html` | No block comments | Семантический HTML, shortcodes |
| `builder_managed` | Elementor/Bricks/Divi | Limited metadata, read + analysis |
| `readonly` | No write caps | Анализ, диагностика, рекомендации |

## 1.3 Источники истины (по приоритету)

1. Runtime site capabilities (GET /agent/capabilities)
2. Runtime action map и permission profile
3. Resource content, format, preconditions
4. Runtime extension manifests
5. Validation results
6. Agent UI Manifest
7. Системные промпты
8. Предположения модели

---

# [A] РАЗДЕЛ 2. АРХИТЕКТУРА

## 2.1 Core vs Extensions

### Core (всегда доступно при установке WP плагина)
- Site connection and authentication
- Capability discovery (GET /agent/capabilities)
- Content inventory and resource reading
- Site Health and diagnostics
- Media operations
- Generic post/page actions
- Proposals and approvals
- Permissions, sanitization, preconditions, audit

### Optional Extensions
- AI Pilot Blocks (manifest, rules, validate, audit)
- Builder adapters (Elementor, Bricks, Divi — future)
- Theme-specific context providers
- MCP/Abilities exports

**Принцип:** Core не должен импортировать extension code или падать при отсутствии extension.

## 2.2 Runtime Flow

```
Web Chat
  → Auth / Orchestrator API
    → OpenClaw Orchestrator
      → Site Capability Detector
        → AI Pilot Remote Site API

Read/health → core tools → answer

Write → detect authoring mode → choose skill → validate → proposal → approve → execute
```

## 2.3 Capability Profile (schema v1.1)

```json
{
  "schemaVersion": "1.1",
  "siteId": "...",
  "connector": { "name": "AI Pilot Remote Site API", "version": "2.1.1" },
  "intelligence": { structure, contentList, contentRead, health, diagnostics, media },
  "authoring": {
    "write": true,
    "defaultMode": "gutenberg_core",
    "availableModes": ["gutenberg_core", "classic_html"],
    "validationLayers": ["permissions", "sanitization", "preconditions", "wp_block_parse"]
  },
  "extensions": {
    "aipilotBlocks": { available, version, endpoints, hashes }
  },
  "agentUi": { enabled, schemaVersion, manifestUrl, responseUrl },
  "actions": ["get_posts", "create_post", "update_post", ...]
}
```

## 2.4 Mode Router (auth-api)

- При connect сайта → GET /agent/capabilities → cache в БД (cached_capabilities)
- При refresh_context → обновлять capability profile
- В buildSystemPrompt → добавлять mode snippet из profile
- Fallback: нет profile (старый плагин) → generic prompt
- TTL: 1 час (AP-011)

## 2.5 Agent UI Cards

Независимы от WordPress blocks. Карточки — это conversation UI.

| Kind | Компонент | Use case |
|------|-----------|----------|
| `single_choice` | AgentChoiceCard | 2-6 вариантов |
| `multi_choice` | AgentChoiceCard | 2-10 вариантов |
| `confirmation` | AgentConfirmationCard | Yes/No |
| `form` | AgentFormCard | Allowlisted поля |

**Безопасность:**
- Browser submits option IDs only
- Executable values remain server-side
- TTL (5 min), one-time resolution
- Idempotency, resolved card is immutable

## 2.6 Proposal Ownership

Единый proposal store в auth-api (`proposalProvider: "auth-api"`).
WordPress proposal endpoints остаются для backward compat / admin workflows.

---

# [E] РАЗДЕЛ 3. ИНВАРИАНТЫ

## INV-001: Extension optionality
**Cannot:** Core functions MUST NOT fail when AI Pilot Blocks is absent.
**Reason:** Отсутствие extension — нормальное состояние.
**Check:** `/agent/capabilities` возвращает `extensions.aipilotBlocks.available=false` без ошибки.

## INV-002: Authoring mode selection
**Cannot:** Система не должна применять block-specific rules к classic HTML или наоборот.
**Reason:** Разные форматы требуют разных validation layers.
**Check:** Authoring mode определяется per-resource, не только site-level.

## INV-003: No write without proposal
**Cannot:** Изменения WordPress выполняются только после proposal + approval.
**Reason:** Human-in-the-loop — обязательное требование.
**Check:** Каждый write action имеет proposal record с status approved.

## INV-004: No plugin list exposure
**Cannot:** Capability profile не раскрывает полный список активных плагинов.
**Reason:** Безопасность — минимальная экспозиция.
**Check:** Extension detection через WP_Block_Type_Registry и REST routes, не через get_option('active_plugins').

## INV-005: Fallback compatibility
**Cannot:** Новый код не должен ломать существующий flow при отсутствии /agent/capabilities.
**Reason:** Старые версии WP плагина (pre-2.1.1) не имеют endpoint.
**Check:** 404/timeout на /agent/capabilities → generic prompt, без ошибки.

## INV-006: Agent UI independence
**Cannot:** Карточки чата не зависят от WordPress block manifest.
**Reason:** Web-chat и WP extension — раздельные системы.
**Check:** Agent UI Cards работают на сайте без AI Pilot Blocks.

---

# [E] РАЗДЕЛ 4. АРХИТЕКТУРНЫЕ ПРИНЦИПЫ

## AP-001: Additive extension strategy
Новые возможности добавляются, существующие не удаляются. Capability discovery добавляется, structure endpoint сохраняется.

## AP-002: Two independent capability groups
Site intelligence (read/health) и content authoring (write) — раздельные booleans. Сайт может быть полезен для мониторинга даже без write.

## AP-003: Resource-level override
Site default mode недостаточен. Каждый ресурс может иметь свой contentFormat override.

## AP-004: Single proposal store
Один авторитетный proposal store в auth-api. Дубликаты предотвращаются через proposalProvider field.

## AP-005: Browser submits IDs only
Карточки отправляют optionId на сервер, backend восстанавливает значения. В браузер не передаётся executable payload.

## AP-006: Cache by composite key
Capability profile кэшируется по: siteId + connector version + schema version + extension hashes.

## AP-007: Validation layering
AI Pilot validation дополняет, не заменяет: permissions → sanitization → preconditions → action validation → URL safety → (optional) aipilot_blocks.

## AP-008: Health-to-action flow
health issue → explain → check remediation action → proposal (if available) → approve → execute → rerun health.

## AP-009: YAGNI for builder adapters
Не заявлять поддержку builder layout mutation до создания конкретного adapter.

---

# [D] РАЗДЕЛ 5. ЭТАПЫ ВНЕДРЕНИЯ

## Stage 0: Regression Tests ✅ (2026-07-19)
- tests/wp-mock.php — 821 строка, полный WP mock
- tests/TestHelpers.php — 464 строки, assertions + helpers
- tests/RegressionTest.php — 836 строк, 30+ тестов
- tests/test-runner.php — 175 строк, standalone runner

## Stage 1: Capability Profile ✅ (2026-07-19)
- modules/module-capabilities.php — 260 строк
- GET /agent/capabilities endpoint
- AI Pilot Blocks detection (WP_Block_Type_Registry + REST route)
- Authoring mode detection (FSE/classic/builder_managed)

## Stage 2: Mode Router ✅ (2026-07-19)
- src/db/capability_cache.js — profile storage with TTL (1h)
- Migration v12: sites.cached_capabilities column
- chat.js: refresh_context fetches /agent/capabilities (with fallback)
- chat.js: buildSystemPrompt injects mode snippet
- sites.js: connect handlers fetch+cache capabilities
- prompt.js: MODE_PROMPTS + getModePromptSnippet()
- 25/25 smoke tests passed

## Stage 3: OpenClaw Prompts ✅ (2026-07-19)
- 8 промптов сохранены в workspace/prompts-v1.1.0/
- Skill file: skills/ai-pilot-dual-mode/SKILL.md

## Stage 4: Agent UI Cards (в работе)
- Backend: agent_ui_cards table, CRUD, API routes
- Frontend: Vue components, Pinia store, API service

## Stage 5: Enhanced Block Workflows (future)
- Manifest/rules caching by hash
- Block validation before proposal
- Block-aware diff and preview
- Stale extension state detection

## Stage 6: Builder Adapters (future)
- Elementor adapter (read_layout, update_text, preview)
- Bricks adapter
- Divi adapter

---

# [D] РАЗДЕЛ 6. КОНТРАКТЫ

## 6.1 site-capabilities.example.json
Файл: `prompts-v1.1.0/contracts/site-capabilities.example.json`

## 6.2 agent-ui-manifest.example.json
Файл: `prompts-v1.1.0/contracts/agent-ui-manifest.example.json`

## 6.3 proposal.example.json
Файл: `prompts-v1.1.0/contracts/proposal.example.json`

---

# [D] РАЗДЕЛ 7. РИСКИ И МИТИГАЦИЯ

## ⚠️ Риски при смене чата и auth-api

| Риск | Где | Митигация |
|------|-----|-----------|
| auth-api context cache устарел | chat.js refresh_context | Capability profile обновляется вместе с context |
| Proposal store migration | proposalProvider field | Fallback на WP proposals, backward compat |
| System prompt change | buildSystemPrompt | Fallback: нет profile → generic prompt |
| Capability profile cache | TTL 1h | isProfileFresh() проверяет, refresh при истечении |
| Web-chat fallback | Старый плагин без capabilities | 404 → generic, без ошибки |
| Extension state change | Blocks plugin removed | Stale detection: available=false → не выполнять aipilot/* proposals |

---

# [D] РАЗДЕЛ 8. ТЕСТ-ПЛАН

## A. Core site without AI Pilot Blocks
- Connect and inspect → available=false, no error
- Create Gutenberg draft → mode gutenberg_core
- Update classic HTML → shortcodes preserved
- Site Health → diagnostics work without blocks

## B. Site with AI Pilot Blocks
- Capability discovery → extension available/version/endpoints
- Create AI Pilot landing → mode aipilot_blocks, validate passes
- Edit core-only post → resource mode gutenberg_core
- Edit legacy classic page → resource mode classic_html

## C. Builder-managed page
- Detected builder_managed
- Layout write blocked without adapter
- Title/excerpt/status allowed if capability says so

## D. Extension state changes
- Blocks activated → capability refresh detects
- Blocks removed before execution → stale, no write

## E. Agent UI Cards
- Cards render independently from blocks
- Option IDs submitted, server restores values
- TTL/idempotency/status enforced

## F. Regression
- Existing health endpoints work
- Structure/content read works
- Generic actions work
- Proposal approval works
- No dependency error when blocks absent

---

_Спецификация обновляется при изменении системы (ANSS §10 Change Specification)._
