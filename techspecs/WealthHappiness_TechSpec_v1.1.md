# MFSD Success, Wealth & Happiness Debate — Technical Specification v1.1

**Plugin directory:** `mfsd-wealth-happiness/`
**Shortcode(s):** `[mfsd_wealth_happiness]`
**Version:** 1.2.0
**Author:** MisterT9007
**Purpose:** "Success, Wealth & Happiness Part 2" — a WhatsApp-style AI debate for students aged 12–14. Following the matching-pairs image game, the student debates whether wealth and success bring happiness with an AI facilitator that plays devil's advocate using a Solutions Mindset, Socratic approach. The AI is personalised with the student's dream job, Who Am I personality name and a wellbeing signal from their Weekly RAG answers. The conversation is saved per student and resumed on return.

> First as-built spec written at v1.1.0 (spec v1.0); updated for v1.2.0 (spec v1.1).

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
1. Shortcode localises `MFSD_WH_CFG` (4 REST URLs + `wp_rest` nonce), enqueues JS/CSS, and outputs `#mfsd-wh-root`. (The unused hidden `[mwai_chatbot id="chatbot-vxk8pu"]` was removed in v1.2.0.)
2. JS `init()` → `GET /wealth-context` (personalisation) → `GET /wealth-load`.
3. Saved messages → `renderChat()`; none → `renderIntro()` (title card, context badges for dream job and Who Am I personality name — e.g. "🧠 The Advocate", Start button).

### 2. Starting and chatting
1. Start pushes the hard-coded `INITIAL_AI_MESSAGE` (references the matching-pairs game) and renders the chat.
2. On send: the user message is appended, `POST /wealth-save` persists the full array, a typing indicator shows, then `POST /wealth-chat` with `{message, conversation, context}`.
3. The AI reply is appended and saved again.

### 3. Server-side AI call (`api_chat`)
1. Reads the chatbot from `mfsd_stevegpt_map_wealth_happiness_chat`. If the slot is empty or SteveGPT is inactive → HTTP 500 `AI not available` (logged).
2. Rebuilds the student context **server-side** with `build_context($user_id)`. Any `context` sent by the JS is ignored, so a client can't inject text into the prompt.
3. Takes the last 6 client-sent messages as `User:` / `AI:` history (text sanitised with `sanitize_textarea_field`).
4. If the chatbot has a `prompt_template`, the prompt is `render_prompt()` with tokens `student_name`, `dream_job`, `personality`, `wellbeing_note`, `conversation`, `message`. Otherwise it sends the built-in prompt: `build_system_prompt()` (debate persona, Solutions Mindset rules, student context, wellbeing note, "never mention MBTI/DISC/codes") + history + message.
5. `SteveGPT_Chatbot::get($id)->query($prompt, $user_id)`. Empty response or exception → 500 `AI processing failed`.

**Personality source (`build_context`):** latest `mbti_type` from `wp_mfsd_ptest_results` (Who Am I), falling back to the latest `type4` in `wp_mfsd_mbti_results` (Weekly RAG). The code is mapped to a student-facing name and family via `PERSONALITY_NAMES` (mirrors `mfsd-personality-test`). Only `{name, family}` leaves the server; the 4-letter code is never sent to the browser or the prompt. **Wellbeing note:** Reds > 1.5× Greens → "may be experiencing some challenges"; Greens > 1.5× Reds → "generally doing well".

---

## REST Endpoints

Namespace **`mfsd-wealth/v1`** (moved from shared `mfsd/v1` in v1.1.0 — see note). All routes: `permission_callback` → `is_user_logged_in()`; WP cookie auth with `X-WP-Nonce`.

| Route | Method | Description |
|---|---|---|
| `/mfsd-wealth/v1/wealth-context` | GET | Personalisation from `build_context()`: latest dream job, RAG answer counts by week, `personality: {name, family}`, display name. Each table checked with `SHOW TABLES LIKE` first. |
| `/mfsd-wealth/v1/wealth-load` | GET | Returns saved `messages[]` (empty array if none). |
| `/mfsd-wealth/v1/wealth-save` | POST | Upserts the full `messages[]` JSON for the current user. 400 if not an array. |
| `/mfsd-wealth/v1/wealth-chat` | POST | Generates the AI reply (see Flow 3). |

> **Namespace note (v1.1.0):** Until v1.0.0 these routes were under `mfsd/v1`, shared with `mfsd-supabase-bridge`. The bridge's JWT middleware (v3.0.0–v7.0.0) rejected any `/mfsd/v1/` request without a Bearer token, so all four endpoints returned `401 "Missing or invalid Authorization header"`. Fixed by moving to `mfsd-wealth/v1` (this plugin) and by bridge v7.1.0 falling through to cookie auth. Do not register routes under `mfsd/v1`.

---

## AI Integration

| Item | v1.2.0 |
|---|---|
| Server-side call | `SteveGPT_Chatbot::get($id)->query()` (task mode, conversation history sent in the prompt) |
| Integration slot | **Wealth & Happiness → Debate chat**: `mfsd_stevegpt_map_wealth_happiness_chat`. Tokens: `student_name`, `dream_job`, `personality`, `wellbeing_note`, `conversation`, `message` |
| Embedded widget | None. The custom WhatsApp UI is kept; the old hidden `mwai_chatbot` shortcode was removed |
| AI Engine | Not used |

> **Deployment:** the slot starts empty. Assign a chatbot in SteveGPT → Chatbots before deploying, or every chat returns "AI not available".
>
> **Pattern note:** the implementation standard prefers `send_message()` + the SteveGPT widget for chat. This plugin keeps its own UI and history, so it uses `query()` with history in the prompt. Moving to the widget would be a UX change.

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

1. ~~Not migrated to SteveGPT.~~ **Fixed in v1.2.0.**
2. ~~Student-facing "MBTI" label.~~ **Fixed in v1.2.0** — badge shows the Who Am I name; code never leaves the server; prompt forbids MBTI/DISC terms.
3. **Ultimate Member dependency.** `get_current_user_id()` prefers `um_profile_id()` and the display name uses `um_get_display_name()` — legacy `um_*` calls after the ProfilePress migration (known cross-suite pitfall). They fall back to WP core, but should be removed.
4. **No limit on saved conversation size** in `/wealth-save`.
5. **No course-ordering integration** (`mfsd_get_task_status` / `mfsd_set_task_status`) and no completion state.
6. ~~Debug logging of `MFSD_WH_CFG`~~ **Removed in v1.2.0.** (Other `console.log` calls remain.)
7. **`PERSONALITY_NAMES` duplicates** the nickname map in `mfsd-personality-test` (private there). If names change, update both, or expose a public helper from the personality test.

---

## Inter-Plugin Dependencies

| Dependency | Type | Notes |
|---|---|---|
| SteveGPT (`stevegtp`) | Required | All AI replies via the Debate chat slot |
| `mfsd-personality-test` (`wp_mfsd_ptest_results`) | Optional | Personality name (preferred source) |
| `ai-dream-jobs` (`wp_mfsd_ai_dream_jobs_results`) | Optional | Dream job context |
| `mfsd-weekly-rag` (`wp_mfsd_rag_answers`, `wp_mfsd_mbti_results`) | Optional | Wellbeing signal and personality type |
| Ultimate Member | Optional (legacy) | See Known Issues #3 |
| `matching-pairs-game` | Content | The opening message follows on from the matching-pairs image game |

---

## Version History

| Version | Changes |
|---|---|
| 1.2.0 | Current. SteveGPT migration: `api_chat` uses `SteveGPT_Chatbot::get()->query()` via the new **Debate chat** slot (`mfsd_stevegpt_map_wealth_happiness_chat`); removed the unused `[mwai_chatbot]`. Context rebuilt server-side (client context ignored), history text sanitised. Student-facing "🧠 MBTI: XXXX" badge replaced by the Who Am I personality name (from `wp_mfsd_ptest_results`, falling back to RAG); 4-letter codes no longer leave the server; prompt forbids MBTI/DISC terms. Removed the `MFSD_WH_CFG` console log. |
| 1.1.0 | REST namespace moved from `mfsd/v1` to `mfsd-wealth/v1` (4 routes + localised URLs) to stop the Supabase bridge JWT middleware returning HTTP 401. No DB, JS or prompt changes. |
| 1.0.0 | Initial implementation: WhatsApp-style debate chat, per-user conversation persistence, dream job / MBTI / RAG context. |
