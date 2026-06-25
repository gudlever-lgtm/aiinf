# REFACTOR-AUDIT.md — Prompt C: Publish-path Hardening

---

## TOP FINDING: NO AUTHENTICATION WHATSOEVER

**The entire control center is open to the internet with zero auth.** Any visitor who knows the URL can:
- Approve, reject, or modify drafts (`/api/draft_action.php`)
- Approve and queue items for publish (`/api/publish_action.php`)
- Overwrite API credentials including LinkedIn tokens (`/api/settings_save.php`)
- Trigger AI generation (`/ajax/generate.php`)
- View all draft content (`/ajax/drafts.php`)

There is no login page, no session check, no HTTP Basic auth, no token — nothing. `index.php` serves the full SPA to anyone. All `ajax/` and `api/` endpoints call `db.php` or `env.php` directly with no guard.

---

## Audit Findings

### 1. Authentication / Session

| Check | Result |
|---|---|
| Login page | **None** |
| Session check in `index.php` | **None** |
| Session check in any `api/*.php` | **None** |
| Session check in any `ajax/*.php` | **None** |
| CSRF token on POSTs | **None** |
| HTTP Basic Auth (server level) | Not found in codebase (may exist in nginx/apache config outside repo) |

Grep for `session_start`, `$_SESSION`, `login`, `auth`, `password`, `token` across all PHP: only hits are in `scripts/crypto.php` (AES crypto helpers), `scripts/content_safety.php` (JWT-like content classifier), `scripts/generate_drafts.php` (Mistral API token from `.env`), and `api/settings_save.php`/`ajax/settings.php` (LinkedIn credential handling). **None relate to user session auth.**

### 2. `/api/publish_action.php` (full review)

```
POST /api/publish_action.php
  id=<queue_id>&action=reject|publish&targets[]=linkedin
```

- No auth. Any POST with a valid `id` triggers the action.
- `action=reject`: UPDATEs `publish_queue.status='rejected'` — no confirmation, no log.
- `action=publish`: Runs content-safety check, then sets `publish_queue.status='approved'`, sets `ai_drafts.status='published'`, inserts rows into `publish_targets`. **This approves and queues immediately with no delay window.**
- No CSRF token required.
- No immutable record of what was approved or when.
- `targets` is a plain JSON array from `$_POST` — no validation that values are expected strings.

### 3. `/api/draft_action.php` (full review)

```
POST /api/draft_action.php
  id=<draft_id>&action=approve|reject|save&content=...
```

- No auth. Any POST controls the draft lifecycle.
- `action=approve`: content-safety check, then `UPDATE ai_drafts SET status='approved'` + `INSERT INTO publish_queue`.
- `action=reject`: `UPDATE ai_drafts SET status='rejected'`.
- `action=save`: free-text overwrite of `content` column — no length limit, no sanitization beyond PDO binding.
- No CSRF token.

### 4. Publisher CLI / Cron

No `publish.php` or `publisher.php` exists in the repo yet. The `scripts/sync_fellis.sh` script fetches git commits and imports them — it is **not** the publisher. The publish path currently ends at `publish_targets` rows in the DB; the actual outbound posting to LinkedIn has not been implemented yet (the schema and queue exist but the sender script does not). This means there is currently **no cron publisher to harden** — it needs to be written.

### 5. `SHOW CREATE TABLE publish_queue` and `publish_targets`

No DDL file in the repo. Schema inferred from migrations and queries:

**`publish_queue`**
```
id          INT AUTO_INCREMENT PK
draft_id    INT (FK → ai_drafts.id, UNIQUE via uq_pq_draft_id)
status      VARCHAR — 'pending'|'approved'|'rejected'
created_at  DATETIME (assumed)
```
No `scheduled_for`, no `cancelled_at`, no `approved_by`.

**`publish_targets`**
```
id          INT AUTO_INCREMENT PK
queue_id    INT (FK → publish_queue.id)
target      VARCHAR — 'linkedin'|'blog'|'changelog'
```
No timestamp, no send result, no `external_id`.

Neither table has: delay window, cancel flag, immutable publish log, or kill-switch awareness.

### 6. Other endpoints with no auth

- `/ajax/generate.php` — triggers AI generation (costs money)
- `/ajax/import.php` — imports git commits
- `/ajax/migrate.php` — runs DB migrations (extremely dangerous)
- `/api/settings_save.php` — overwrites LinkedIn OAuth tokens
- `/api/regenerate_draft.php` — re-calls LLM for any draft id

---

## Plan (awaiting confirmation before any code changes)

### Step 1 — Auth + CSRF

Add a thin session-auth layer:
- `scripts/auth.php`: `requireAuth()` function — checks `$_SESSION['authed']`, redirects to `login.php` if not set.
- `login.php`: POST form comparing `ADMIN_PASSWORD` from `.env`, sets session, redirects to `/`.
- Logout link in sidebar.
- `index.php` calls `requireAuth()` at top (after `session_start()`).
- All `api/*.php` and `ajax/*.php` include `auth.php` and call `requireAuth()` — for API endpoints, return 401 JSON instead of redirect.
- CSRF: `requireAuth()` also generates a per-session token stored in `$_SESSION['csrf']`; state-changing POSTs must include `csrf_token` field matching it. GET requests (ajax PHP fragments) are exempt.
- Token is injected into `index.php` as a JS variable so `app.js` can attach it to all fetch POSTs.

### Step 2 — Hold/delay window

- Add `scheduled_for DATETIME NULL` to `publish_queue`.
- `publish_action.php` on `action=publish`: set `scheduled_for = NOW() + INTERVAL {PUBLISH_DELAY_MINUTES} MINUTE` (env var, default 15).
- New `action=cancel` in `publish_action.php`: set `status='cancelled'` where `status='approved'` and `scheduled_for > NOW()`.
- Cancel button in publish queue UI (only shown for approved items not yet sent).
- Publisher (Step 4) only selects `WHERE status='approved' AND scheduled_for <= NOW()`.

### Step 3 — Immutable `published_log` table

```sql
CREATE TABLE published_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    draft_id     INT NOT NULL,
    target       VARCHAR(50) NOT NULL,
    content_sent MEDIUMTEXT NOT NULL,
    external_id  VARCHAR(190) NULL,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status       VARCHAR(30) NOT NULL,   -- 'success'|'failed'
    error        TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

App code only INSERTs — never UPDATEs or DELETEs this table.

### Step 4 — Kill switch

- Add `publishing_enabled TINYINT NOT NULL DEFAULT 0` to `api_settings` (or a new `app_config` table).
- Default **0** (off) — nothing publishes until explicitly enabled.
- Publisher checks this at startup and exits cleanly with a log line if disabled.
- Publish button in UI is disabled/greyed when `publishing_enabled=0`; status shown in header.
- Toggle in Settings page.

### Step 5 — Publisher CLI

Write `scripts/publish.php`:
- Reads kill switch; exits cleanly if off.
- Selects `publish_queue WHERE status='approved' AND scheduled_for <= NOW()`.
- For each item, reads targets from `publish_targets`, posts to LinkedIn via `api_settings` credentials.
- Inserts a row into `published_log` for every attempt (success or failure).
- On success: UPDATEs `publish_queue.status='sent'` and `ai_drafts.published_id`.
- On failure: UPDATEs `publish_queue.status='failed'`, logs error.
- Safe to run from cron.

---

**STOP — awaiting confirmation before any code is written.**

---

# REFACTOR-AUDIT.md — Prompt 0.5 Generator Quality

## 1. Files

| Role | Path |
|---|---|
| Generator | `scripts/generate_drafts.php` |
| Ingest | `scripts/import_commits.php` |
| Generator HTTP wrapper | `ajax/generate.php` |
| Ingest HTTP wrapper | `ajax/import.php` |

No schema file exists in the repo. Structure inferred from queries.

---

## 2. LLM Configuration (current)

| Property | Value |
|---|---|
| Vendor | Mistral AI |
| Endpoint | `https://api.mistral.ai/v1/chat/completions` |
| Model | `mistral-small-latest` (env: `MISTRAL_MODEL`) |
| Temperature | 0.4 |

---

## 3. Current system message (verbatim)

```
Du er en produkt- og kommunikationsassistent for Fellis. Skriv præcist, uden hype, og fokuser på reel værdi.
```

---

## 4. Current user prompt template (verbatim, from `generate_drafts.php` lines 84–119)

```
Du analyserer en Git commit fra Fellis.

FELLIS BESKRIVELSE:
Fellis er en europæisk social platform baseret på transparens, privatliv og ikke-algoritmisk feed.

COMMIT:
- Hash: {commit_hash}
- Author: {author}
- Message: {message}

OPGAVE:
Generér følgende 3 outputs:

1. CHANGELOG (kort og teknisk)
2. LINKEDIN POST (professionel, ingen hype)
3. FOUNDER UPDATE (reflekterende, ærlig)

REGLER:
- ingen overdrivelse
- ingen marketingfluff
- vær konkret
- hvis commit er lille → skriv det som lille ændring
- hvis commit er teknisk → forklar enkel værdi

FORMAT:

CHANGELOG:
...

LINKEDIN:
...

FOUNDER:
...
```

---

## 5. Confirmed DB findings

### `repo_events`
- `processed` column: **does not exist** — generator never marks events done
- `changed_files` column: **does not exist** — ingest only stores hash/author/message
- Dedup at ingest: `INSERT IGNORE` on `commit_hash` (works for re-import, but no UNIQUE constraint confirmed in schema)

### `ai_drafts`
- `(event_id, type)` unique constraint: **does not exist**
- Generator current dedup: `WHERE id NOT IN (SELECT event_id FROM ai_drafts WHERE event_id IS NOT NULL)` — this excludes the entire event if *any* draft exists for it, but it's not atomic and has no DB-level protection

### `processed` flag
- Not set anywhere after generation. Re-running the generator on a fresh DB would re-process all events.

---

## 6. Other issues confirmed

1. **All three draft types generated for every commit** — merge commits, typo fixes, gitignore edits all get a LinkedIn post and a founder update.
2. **Fallback corrupts data**: if regex parsing fails, `$changelog = $aiOutput` (entire raw LLM response including the other sections stored as changelog).
3. **Empty content stored**: no check before `INSERT INTO ai_drafts` — model returning empty string for a section is silently saved.
4. **No retry count** — if the Mistral call fails (network, quota), the event stays unprocessed forever with no cap.
5. **No `processed` update** — on the next run, a partially-drafted event (e.g. only changelog inserted before a crash) gets skipped by the `NOT IN` check, meaning linkedin_post and founder_update are never generated for it.

---

## 7. Proposed replacement prompt

**Requires approval before implementation.**

### 7a. Significance gate (pre-generation, no LLM call needed)

Skip generation entirely (mark processed, no draft) when commit message matches:

```
/^Merge (pull request|branch)/i
/^(chore|ci|build|style|refactor|test)(\(.*?\))?:/i
/^(bump|update) (version|deps|dependencies|lock|lockfile)/i
/^(remove duplicate|fix typo|whitespace|formatting)/i
```

For surviving commits, determine output types:
- **changelog only**: any commit not matching "notable" heuristics below
- **changelog + linkedin_post + founder_update**: only when message matches a "notable" pattern:
  ```
  /^(feat|feature)(\(.*?\))?:/i
  /^(fix)(\(.*?\))?:/i   (non-trivial — message longer than ~30 chars)
  /\b(release|v\d+\.\d+|launch|ship|deploy)\b/i
  ```

File-based scoring is deferred until `changed_files` is populated at ingest.

### 7b. New system message (proposed)

```
Du er Lars. Du skriver om dit arbejde på Fellis — en europæisk social platform.
Skriv dansk. Tærslen for at sige noget er høj: skriv kun, hvis der er noget konkret at sige.
Ingen buzzwords. Ingen sætninger der starter med "Det er ikke...". Ingen "men det er nødvendigt".
Ingen refleksioner over tillid, langsigtet tænkning eller "det handler om mere end kode".
Brug ikke disse vendinger: "det er ikke glamourøst", "små skridt men vigtige", "transparent", "autentisk", "deler gerne".
```

### 7c. New user prompt template (proposed)

```
COMMIT:
- Besked: {message}
- Forfatter: {author}

REGLER:
- Skriv kun de sektioner du får besked på nedenfor.
- Returner SKIP i en sektion, hvis der reelt ikke er noget at sige.
- Strip alle markdown-markører (**, ---, #) fra output.
- Max-længder: CHANGELOG = 1-2 linjer faktuel tekst. LINKEDIN = 2-4 sætninger, ingen indledning. FOUNDER = 3-6 sætninger, kun hvis der er en reel pointe.

{SECTIONS_PLACEHOLDER}

FORMAT (eksakt — ingen andre overskrifter):

CHANGELOG:
...

{OPTIONAL_SECTIONS}
```

Where `SECTIONS_PLACEHOLDER` and `OPTIONAL_SECTIONS` are populated by PHP based on the significance classification:
- Trivial commit → only `CHANGELOG:` section requested and expected
- Notable commit → all three sections requested

### 7d. Output handling (proposed)

- Strip leading/trailing `**`, `---`, `#`, whitespace from each parsed section
- If a section contains only `SKIP` or is empty after stripping → do not insert that row
- If all sections are empty/SKIP → log, mark event processed with `processed=2` (skipped), no draft rows
- Max retry count: 2 (configurable). After 2 failures, mark `processed=3` (error), log, move on.

---

## 8. Schema changes needed (for approval)

### A. `repo_events`
```sql
ALTER TABLE repo_events ADD COLUMN processed TINYINT NOT NULL DEFAULT 0;
-- 0 = pending, 1 = done, 2 = skipped (noise), 3 = error
ALTER TABLE repo_events ADD COLUMN retry_count TINYINT NOT NULL DEFAULT 0;
-- Ensure commit_hash is truly unique (ingest uses INSERT IGNORE, but need confirmed UNIQUE):
ALTER TABLE repo_events ADD UNIQUE KEY uq_commit_hash (commit_hash);
-- changed_files deferred to a follow-up prompt
```

### B. `ai_drafts`
```sql
-- Option chosen: existence check before INSERT (simpler than UNIQUE constraint,
-- avoids migration risk on a table that may already have duplicates).
-- Generator will SELECT COUNT(*) WHERE event_id=? AND type=? before each INSERT.
-- A UNIQUE constraint can be added later once duplicates are cleaned.
```

---

**STOP. Awaiting approval of prompt + gates before any code changes.**

---

# Prompt A Audit — Multi-channel settings + encrypted credentials + target wiring

## Table schemas (from scripts/migrate.php)

### api_settings
Columns: `id`, `service`, `api_key`, `api_secret`, `access_token`, `refresh_token`, `base_url`, `author_urn`
- `service` has UNIQUE index `uq_service` — `ON DUPLICATE KEY UPDATE` in settings_save.php works correctly.
- Secret fields (`api_key`, `api_secret`, `access_token`, `refresh_token`) are already stored encrypted.
- `author_urn` added via migrate.php line 98.

### publish_queue
Columns: `id`, `draft_id`, `status`
- UNIQUE index `uq_pq_draft_id` on `draft_id`.

### publish_targets
Columns: `id`, `queue_id`, `target`
- `queue_id` was renamed from `draft_id` via migrate.php lines 84–86.
- Holds one row per (queue item, channel). Written to in `api/publish_action.php` line 56.

---

## Finding 1 — api_settings.service UNIQUE index

**Present.** migrate.php line 101:
```sql
CREATE UNIQUE INDEX IF NOT EXISTS uq_service ON api_settings (service)
```
The `ON DUPLICATE KEY UPDATE` in settings_save.php is correctly anchored.

---

## Finding 2 — Credential encryption

**Already fully implemented.**

- `scripts/crypto.php`: libsodium `sodium_crypto_secretbox` (authenticated encryption, random nonce per call, stored as `enc:` + base64(nonce . ciphertext)).
- `api/settings_save.php`: encrypts on write, preserves existing encrypted value when field left blank.
- `ajax/settings.php`: decrypts and masks (last 4 chars) via `maskSecret()`. No `print_r` anywhere.
- `scripts/migrate_encrypt_credentials.php`: idempotent one-off migration to encrypt pre-existing plaintext rows.
- `APP_ENCRYPTION_KEY` documented in `.env.example` with generation command.

Nothing to build here — it exists and is correct.

---

## Finding 3 — Bluesky and Mastodon in settings

**Not implemented.** `ajax/settings.php` only shows LinkedIn. `api/settings_save.php` accepts any `service` value via POST and uses the same `api_settings` columns for all services — no per-service allowlist needed.

Field mapping for each service onto the generic columns:

| Service   | api_key         | api_secret  | access_token  | refresh_token | base_url                   | author_urn |
|-----------|-----------------|-------------|---------------|---------------|----------------------------|------------|
| LinkedIn  | Client ID       | Client Sec. | Access Token  | Refresh Token | https://api.linkedin.com   | urn:li:person:… |
| Bluesky   | Handle          | App Password | (unused)      | (unused)      | https://bsky.social        | (unused) |
| Mastodon  | (unused)        | (unused)    | Access Token  | (unused)      | instance URL (required)    | (unused) |

The UI must label fields per-service; the DB columns are generic enough to hold all three.

---

## Finding 4 — publish_targets wiring

**Already wired.** `api/publish_action.php` lines 55–57 loop over `$_POST['targets']` (JSON array from frontend) and insert one `publish_targets` row per target. `ajax/publish_queue.php` renders LinkedIn/Blog/Changelog checkboxes; `app.js` `publishApprove()` collects checked values and POSTs them as JSON. The write path is complete.

**Gap:** The publish queue UI only has `linkedin`, `blog`, `changelog` checkboxes — no `bluesky` or `mastodon`. This needs to be added alongside the settings sections.

---

## Plan (pending approval)

### Step 1 — `ajax/settings.php`: add Bluesky + Mastodon sections
- Mirror the LinkedIn form structure.
- Show only the fields each service uses; label them with the service-specific name (e.g. "Handle" not "API Key" for Bluesky, "App Password" not "API Secret").
- The "Current Settings" table at the bottom should list all three services.

### Step 2 — `api/settings_save.php`: no changes needed
- Accepts any `service`, encrypts the right fields, preserves blanks. Works as-is for bluesky and mastodon.

### Step 3 — `ajax/publish_queue.php`: add bluesky + mastodon checkboxes
- Add `bluesky` and `mastodon` as targets alongside the existing three.

### Step 4 — No publish_targets schema change needed
- `queue_id` + `target` (VARCHAR) already supports any string target value.

### Step 5 — No crypto changes needed
- Already using libsodium secretbox with authenticated encryption.

### Step 6 — Run `scripts/migrate_encrypt_credentials.php` in production
- One-off: encrypts any pre-existing plaintext rows. Already written and idempotent.

---

**STOP. Awaiting confirmation before implementation.**
