# MFSD Success, Wealth & Happiness Debate — Technical Specification v1.0

**Plugin directory:** `mfsd-wealth-happiness/`
**Shortcode(s):** `[mfsd_wealth_happiness]`
**Version:** 1.1.0
**Author:** MisterT9007
**Purpose:** "Success, Wealth & Happiness Part 2" — a WhatsApp-style AI debate for students aged 12–14. Following the matching-pairs image game, the student debates whether wealth and success bring happiness with an AI facilitator that plays devil's advocate using a Solutions Mindset, Socratic approach. The AI is personalised with the student's dream job, personality type and a wellbeing signal from their Weekly RAG answers. The conversation is saved per student and resumed on return.

> First as-built spec for this plugin, written at v1.1.0.

---

## File Structure

```
mfsd-wealth-happiness/
├── mfsd-wealth-happiness.php          # Bootstrap, MFSD_Wealth_Happiness singleton, REST routes, AI prompt
├── assets/
│   ├── mfsd-wealth-happiness.js       # Vanilla JS chat app (intro, chat, typing indicator, persistence)
│   └── mfsd-wealth-happiness.css      # WhatsApp-style chat UI
└── techspecs/
```

No admin panel, no settings, no WP options.

---

## Database Schema

### `wp_mfsd_wealth_and_happiness`

Created on activation via `dbDelta()`.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `user_id` | BIGINT UNSIGNED | `UNIQUE KEY uniq_user` — one conversation per student |
| `conversation_data` | LONGTEXT | JSON array of `{sender: 'user'|'ai', text, timestamp}` |
| `last_updated` | DATETIME | `ON UPDATE CURRENT_TIMESTAMP` |
| `created_at` | DATETIME | |

---

## Key Flows

### 1. Page load
1. Shortcode localises `MFSD_WH_CFG` (4 REST URLs + `wp_rest` nonce), enqueues JS/CSS, and outputs `#mfsd-wh-root` plus a hidden `#mfsd-wh-chat-source` containing `[mwai_chatbot id="chatbot-vxk8pu"]` (see Known Issues #1).
2. JS `init()` → `GET /wealth-context` (personalisation) → `GET /wealth-load`.
3. Saved messages → `renderChat()`; none → `renderIntro()` (title card, context badges for dream job / personality type, Start button).

### 2. Starting and chatting
1. Start pushes the hard-coded `INITIAL_AI_MESSAGE` (references the matching-pairs game) and renders the chat.
2. On send: the user message is appended, `POST /wealth-save` persists the full array, a typing indicator shows, then `POST /wealth-chat` with `{message, conversation, context}`.
3. The AI reply is appended and saved again.

### 3. Server-side AI call (`api_chat`)
1. `build_system_prompt($context)` — debate-facilitator persona, Solutions Mindset rules, devil's-advocate approach, WhatsApp tone, plus a `STUDENT CONTEXT` block: dream job, personality type, and a wellbeing note (Reds > 1.5× Greens → "may be experiencing some challenges"; Greens > 1.5× Reds → "generally doing well").
2. Appends the last 6 messages as `User:` / `AI:` lines and the new message.
3. Calls `$GLOBALS['mwai']->simpleTextQuery($full_prompt)` (see Known Issues #1). Empty response or exception → HTTP 500 `AI processing failed`; AI Engine missing → 500 `AI not available`.

---

## REST Endpoints

Namespace **`mfsd-wealth/v1`** (moved from shared `mfsd/v1` in v1.1.0 — see note). All routes: `permission_callback` → `is_user_logged_in()`; WP cookie auth with `X-WP-Nonce`.

| Route | Method | Description |
|---|---|---|
| `/mfsd-wealth/v1/wealth-context` | GET | Personalisation: latest `wp_mfsd_ai_dream_jobs_results` row, RAG answer counts by week from `wp_mfsd_rag_answers`, latest `type4` from `wp_mfsd_mbti_results`, display name. Each table checked with `SHOW TABLES LIKE` first. |
| `/mfsd-wealth/v1/wealth-load` | GET | Returns saved `messages[]` (empty array if none). |
| `/mfsd-wealth/v1/wealth-save` | POST | Upserts the full `messages[]` JSON for the current user. 400 if not an array. |
| `/mfsd-wealth/v1/wealth-chat` | POST | Generates the AI reply (see Flow 3). |

> **Namespace note (v1.1.0):** Until v1.0.0 these routes were under `mfsd/v1`, shared with `mfsd-supabase-bridge`. The bridge's JWT middleware (v3.0.0–v7.0.0) rejected any `/mfsd/v1/` request without a Bearer token, so all four endpoints returned `401 "Missing or invalid Authorization header"`. Fixed by moving to `mfsd-wealth/v1` (this plugin) and by bridge v7.1.0 falling through to cookie auth. Do not register routes under `mfsd/v1`.

---

## AI Integration

| Item | Current (v1.1.0) |
|---|---|
| Server-side call | `$GLOBALS['mwai']->simpleTextQuery()` — **legacy AI Engine** |
| Embedded widget | `[mwai_chatbot id="chatbot-vxk8pu"]` in a hidden div — **legacy AI Engine**; the JS app does not appear to use it |
| SteveGPT integration slots | None registered |
| Prompt | Hard-coded in `build_system_prompt()` |

---

## Security

| Check | Where |
|---|---|
| `if (!defined('ABSPATH')) exit` | Main file |
| Login required | `check_permission()` on all routes; REST cookie auth requires a valid `wp_rest` nonce |
| Per-user data | All reads/writes keyed on the current user — students cannot read or write others' conversations |
| `$wpdb->prepare()` | All user-scoped queries |
| XSS | Messages rendered with `textContent` via the `el()` helper |

---

## Known Issues / Follow-ups (as of v1.1.0)

1. **Not migrated to SteveGPT.** Both the server call (`$GLOBALS['mwai']->simpleTextQuery`) and the hidden `[mwai_chatbot id="chatbot-vxk8pu"]` depend on AI Engine. If AI Engine is deactivated, every chat returns 500 "AI not available". Migration target: register a `mfsd_stevegpt_map_wealth_happiness_chat` slot via `stevegpt_plugin_integration_slots` and call `SteveGPT_Chatbot::get($id)->send_message()` (multi-turn) or `query()`, per `stevegtp/techspecs/MFSD_CHATBOT_IMPLEMENTATION_PATTERN.md`; remove the unused `mwai_chatbot` shortcode.
2. **Student-facing "MBTI" label.** The intro renders a badge "🧠 MBTI: XXXX", and the system prompt says "Their MBTI type is". Platform rule: no MBTI/Myers-Briggs terminology or 4-letter codes in student-facing content — replace with the Who Am I nickname / family.
3. **Ultimate Member dependency.** `get_current_user_id()` prefers `um_profile_id()` and the display name uses `um_get_display_name()` — legacy `um_*` calls after the ProfilePress migration (known cross-suite pitfall). They fall back to WP core, but should be removed.
4. **No limit on saved conversation size** in `/wealth-save`.
5. **No course-ordering integration** (`mfsd_get_task_status` / `mfsd_set_task_status`) and no completion state.
6. **Debug logging** — the JS logs `MFSD_WH_CFG` (including the nonce) to the console on load.

---

## Inter-Plugin Dependencies

| Dependency | Type | Notes |
|---|---|---|
| AI Engine (`$GLOBALS['mwai']`, `[mwai_chatbot]`) | Required (legacy) | All AI replies |
| `ai-dream-jobs` (`wp_mfsd_ai_dream_jobs_results`) | Optional | Dream job context |
| `mfsd-weekly-rag` (`wp_mfsd_rag_answers`, `wp_mfsd_mbti_results`) | Optional | Wellbeing signal and personality type |
| Ultimate Member | Optional (legacy) | See Known Issues #3 |
| `matching-pairs-game` | Content | The opening message follows on from the matching-pairs image game |

---

## Version History

| Version | Changes |
|---|---|
| 1.1.0 | Current. REST namespace moved from `mfsd/v1` to `mfsd-wealth/v1` (4 routes + localised URLs) to stop the Supabase bridge JWT middleware returning HTTP 401. No DB, JS or prompt changes. |
| 1.0.0 | Initial implementation: WhatsApp-style debate chat, per-user conversation persistence, dream job / MBTI / RAG context. |
