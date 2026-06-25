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

---

# Auth Audit — Simple Login (single admin)

## Audit Date: 2026-06-25

---

## 1. Current Auth State

**There is NO authentication anywhere in the codebase.**

Grep results for `session_start`, `$_SESSION`, `login`, `auth`, `password`:
- Zero matches across all PHP files
- The app is fully open to any HTTP client

The only protection today is whatever the hosting environment provides (lighttpd basic auth
was mentioned in the task, but no lighttpd config was found in the repository).

---

## 2. Entry Points to Protect

### `index.php` (SPA shell)
- Root file, serves the entire app HTML
- No server-side logic beyond rendering the page
- Must redirect to login if unauthenticated

### `/ajax/` — 8 files (all unprotected)

| File | What it does | Risk |
|---|---|---|
| `dashboard.php` | DB stats query | Read |
| `db.php` | PDO factory (included, not web-called directly) | — |
| `drafts.php` | Lists AI drafts | Read |
| `generate.php` | `shell_exec("php scripts/generate_drafts.php")` | **RCE** |
| `import.php` | `shell_exec("php scripts/import_commits.php")` | **RCE** |
| `migrate.php` | `shell_exec("php scripts/migrate_*.php")` | **RCE** |
| `publish_queue.php` | Lists pending queue | Read |
| `settings.php` | Shows (masked) LinkedIn credentials | Read/Sensitive |

### `/api/` — 4 files (all unprotected, all POST)

| File | What it does |
|---|---|
| `draft_action.php` | Approve / reject drafts |
| `publish_action.php` | Queue / cancel publish actions |
| `regenerate_draft.php` | Re-calls Mistral API for a draft |
| `settings_save.php` | Saves encrypted LinkedIn credentials |

### `publish.php`
- **Does not exist.** Publishing is triggered via `/api/publish_action.php` from the browser,
  not a cron script. No cron directory exists. All publishing is web-driven.

---

## 3. `.env` Loading

`scripts/env.php` is a 16-line parser that reads key=value pairs into `$_ENV`.
It is already required by every ajax and api file. Admin credentials can be added to `.env`
and read via `$_ENV['ADMIN_USER']` / `$_ENV['ADMIN_PASSWORD_HASH']` with no infrastructure changes.

---

## 4. Implementation Plan

### A. Credentials
- Add `ADMIN_USER` and `ADMIN_PASSWORD_HASH` to `.env` (and `.env.example` with placeholders)
- Hash generated once with: `php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT);"`
- Never store plaintext

### B. `scripts/auth.php`
Five functions:
- `startSession()` — configure and start session with secure cookie flags
- `login($user, $pass): bool` — verify against `.env`, call `session_regenerate_id(true)` on success
- `isLoggedIn(): bool` — check `$_SESSION['authed']`
- `logout()` — destroy session + clear cookie
- `requireAuth()` — for SPA pages: redirect to `/login.php`; for `/api`+`/ajax`: return 401 JSON

Brute-force protection: track `$_SESSION['login_fails']` + `$_SESSION['lockout_until']`;
sleep(1) on failure, lockout after 5 failures for 5 minutes.

### C. `login.php`
- Minimal form: username, password, submit
- POST handler: call `login()`, redirect to `/` on success, show generic "invalid credentials" on failure
- Generic error only — never reveal which field was wrong

### D. CSRF
- Generate `$_SESSION['csrf_token']` on login; expose via `<meta name="csrf-token">` in `index.php`
- `app.js` reads the meta tag and sends `X-CSRF-Token` header on all fetch POSTs
- `/api/*.php` verifies the header against session token; reject with 403 on mismatch

### E. Wire-in
- `index.php`: add `require_once 'scripts/auth.php'; requireAuth();` at top
- Every `/ajax/*.php` and `/api/*.php`: same two lines at top (after existing `require_once` calls)
- `index.php` sidebar: add Logout link that POSTs to `/api/logout.php`
- New file: `/api/logout.php` — calls `logout()`, returns JSON success

### F. No CLI impact
- No cron script exists, so no CLI concern
- `db.php` is included, not web-callable directly

---

## 5. Files to Create / Modify

| Action | File |
|---|---|
| Create | `scripts/auth.php` |
| Create | `login.php` |
| Create | `api/logout.php` |
| Modify | `index.php` — add requireAuth() + csrf meta tag + logout button |
| Modify | `ajax/dashboard.php`, `drafts.php`, `generate.php`, `import.php`, `migrate.php`, `publish_queue.php`, `settings.php` — add requireAuth() |
| Modify | `api/draft_action.php`, `publish_action.php`, `regenerate_draft.php`, `settings_save.php` — add requireAuth() + CSRF check |
| Modify | `.env` — add ADMIN_USER, ADMIN_PASSWORD_HASH |
| Modify | `.env.example` — add placeholder entries |
| Modify | `app.js` — read csrf meta tag, send X-CSRF-Token header |

Total: 3 new files, 13 modified files.

---

**STOP. Awaiting confirmation before writing any code.**
