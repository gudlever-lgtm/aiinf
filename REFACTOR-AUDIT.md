# Refactor Audit — Dead Standalone UI

## File Inventory

### Root-level pages
| File | Role | Status |
|---|---|---|
| `index.php` | SPA entry point | **LIVE** |
| `drafts.php` | Old standalone drafts UI | **DEAD** |
| `publish_queue.php` | Old standalone publish-queue UI | **DEAD** |
| `settings.php` | Old standalone settings form | **DEAD** |
| `publish.php` | CLI publisher (no-HTML, echo output) | **AMBIGUOUS** — see note |

### `/ajax/` (SPA partials — all LIVE)
- `ajax/dashboard.php`
- `ajax/db.php` — shared DB helper, `require_once`'d by other ajax files
- `ajax/drafts.php`
- `ajax/generate.php`
- `ajax/import.php`
- `ajax/publish_queue.php`
- `ajax/settings.php`

### `/api/` (SPA action handlers — all LIVE)
- `api/draft_action.php` — called by `app.js` lines 60, 96
- `api/publish_action.php` — called by `app.js` lines 122, 142
- `api/settings_save.php`

### `/scripts/` (utilities)
| File | Role | Status |
|---|---|---|
| `scripts/env.php` | Shared `.env` loader | **LIVE** — `require_once`'d by all `/api/` files and `/ajax/db.php` |
| `scripts/generate_drafts.php` | AI draft generation | **LIVE** — invoked by `ajax/generate.php:14` |
| `scripts/import_commits.php` | Commit importer | **LIVE** — invoked by `ajax/import.php:14` |
| `scripts/draft_action.php` | Old draft action handler | **DEAD** — only called by dead `drafts.php` |
| `scripts/publish_action.php` | Old publish action handler | **DEAD** — only called by dead `publish_queue.php` |
| `scripts/sync_fellis.sh` | Git pull shell script | **LIVE** (external cron candidate) |

---

## Inbound-reference trace per candidate file

### `drafts.php` (root)
- Inbound references: **none** from any other file.
- Self-references: filter links `<a href="drafts.php?type=...">` inside its own HTML.
- Outbound: POSTs to `/scripts/draft_action.php` (dead); `require_once scripts/env.php` (live, but that doesn't make this file live).
- SPA equivalent: `/ajax/drafts.php` (what `app.js` actually loads).
- **Verdict: CONFIRMED DEAD.**

### `publish_queue.php` (root)
- Inbound references: **none** from any other file.
- Outbound: POSTs to `/scripts/publish_action.php` (dead).
- SPA equivalent: `/ajax/publish_queue.php`.
- **Verdict: CONFIRMED DEAD.**

### `settings.php` (root)
- Inbound references: **none** from any other file. (`app.js:5` references `/ajax/settings.php`, not this file.)
- Outbound: `require_once scripts/env.php` (live dependency, does not make this page live); POSTs to itself.
- SPA equivalent: `/ajax/settings.php` + `/api/settings_save.php`.
- **Verdict: CONFIRMED DEAD.**

### `scripts/draft_action.php`
- Inbound references: only `drafts.php` (root) at lines 124 and 152 — which is itself dead.
- No reference from `/api/` or `/ajax/` or `app.js`.
- **Verdict: CONFIRMED DEAD** (dead caller, dead callee).

### `scripts/publish_action.php`
- Inbound references: only `publish_queue.php` (root) at lines 65 and 79 — which is itself dead.
- No reference from `/api/` or `/ajax/` or `app.js`.
- **Verdict: CONFIRMED DEAD** (dead caller, dead callee).

---

## Ambiguous / Do Not Touch

### `publish.php` (root)
- Inbound references in repo: **zero**.
- Content: CLI-style PHP, writes to `storage/publish.log`, no HTML output, processes `ai_drafts` with `status = 'approved'` and marks them `published`.
- **Owner confirmed: NOT cron-invoked. CONFIRMED DEAD — add to deletion list.**

---

## Summary: Safe to Delete

These six files have zero inbound references from any live file and are superseded by the SPA:

1. `drafts.php`
2. `publish_queue.php`
3. `settings.php`
4. `publish.php`
5. `scripts/draft_action.php`
6. `scripts/publish_action.php`

**Do NOT delete:** `scripts/env.php`, anything under `/ajax/`, `/api/`, or `/scripts/` except the two listed above.

---

---

# Prompt 2 Audit — Credential Encryption

## Schema

`SHOW CREATE TABLE api_settings` cannot be run directly from this environment, but the
columns are established by every INSERT/UPDATE in the codebase:

| Column | Type (inferred) | Secret? |
|---|---|---|
| `service` | varchar, UNIQUE KEY | No |
| `api_key` | text/varchar | **Yes** |
| `api_secret` | text/varchar | **Yes** |
| `access_token` | text/varchar | **Yes** |
| `refresh_token` | text/varchar | **Yes** |
| `base_url` | text/varchar | No — public URL |

All four secret columns are stored as plaintext today.

## Every read/write site

### Write path
**`api/settings_save.php`** (lines 28–44) — the only write path used by the SPA.
- Directly interpolates `$_POST[...]` into an `INSERT ... ON DUPLICATE KEY UPDATE`.
- If a POST field is empty string (`''`), it overwrites the stored value with blank (bug: must fix).
- No encryption at all.

### Read paths
1. **`ajax/settings.php:4`** — `SELECT * FROM api_settings` fetched into `$rows`.
   - Line 48: `print_r($rows, true)` rendered directly to the page (HTML-escaped but fully visible). Every secret is exposed in the settings panel.
   - Form inputs (lines 36–40) have no `value=` attribute — they show only placeholder text. No pre-population, but the `print_r` below them reveals everything.

2. **`publish.php`** (now confirmed dead) — read from `ai_drafts`, never touched `api_settings`. No credential read.

3. **No other file** reads from `api_settings`. LinkedIn API calls are not yet implemented (publish.php was a simulated stub). So "decrypt at point of use" only applies to a future publisher; for now it applies to the settings display.

### `print_r` audit
Only one live instance: `ajax/settings.php:48`. The dead `settings.php:60` has a bare `print_r` (no htmlspecialchars) — that file is being deleted.

## Environment / key status

- `.env` is gitignored and not present in this checkout.
- `scripts/env.php` is a simple line-by-line parser (no quoting support). It sets `$_ENV[key] = trim(value)`.
- No `APP_ENCRYPTION_KEY` exists. We will add one.
- No `.env.example` exists. We will create one.

## Crypto availability

Both `sodium` (libsodium) and `openssl` extensions are loaded on this PHP installation. **Use libsodium** (`sodium_crypto_secretbox`) — authenticated encryption, simpler API, no separate HMAC needed.

Key size: `SODIUM_CRYPTO_SECRETBOX_KEYBYTES` = 32 bytes. Store as base64 in `.env`.
Nonce size: `SODIUM_CRYPTO_SECRETBOX_NONCEBYTES` = 24 bytes. Prepend to ciphertext, base64-encode the pair.
Ciphertext format on disk: `base64(nonce . ciphertext)` — single string per column, fits existing varchar.

## Proposed implementation plan

1. **Generate key**: `base64_encode(random_bytes(32))` → add as `APP_ENCRYPTION_KEY=<value>` to `.env`.
   Create `.env.example` with placeholder `APP_ENCRYPTION_KEY=base64_32_byte_key_here` and all DB vars.

2. **`scripts/crypto.php`**: `encrypt(string $plain): string` / `decrypt(string $encoded): string`.
   - `encrypt`: generate random nonce, `sodium_crypto_secretbox($plain, $nonce, $key)`, return `base64(nonce . ciphertext)`.
   - `decrypt`: base64-decode, split nonce/ciphertext, `sodium_crypto_secretbox_open(...)`. Return false on failure.
   - Key loaded from `$_ENV['APP_ENCRYPTION_KEY']` (caller must have loaded .env first).

3. **`api/settings_save.php`** changes:
   - Before writing, for each secret field: if POST value is non-empty, encrypt it; if empty, `SELECT` the existing stored value and keep it unchanged (don't overwrite with blank).
   - `base_url` is not a secret — store plaintext.

4. **`ajax/settings.php`** changes:
   - Remove `print_r` dump entirely.
   - After fetching rows, decrypt each secret field and show masked: `str_repeat('*', max(0, strlen($plain)-4)) . substr($plain, -4)` — or `(not set)` if empty/null.
   - Form inputs remain empty (for replacement entry). Add a note: "Leave blank to keep existing value."

5. **`scripts/migrate_encrypt_credentials.php`**: one-off migration.
   - For each row in `api_settings`, for each secret column: check if value looks like existing base64-encoded ciphertext (try `decrypt()`); if decrypt succeeds, skip (already encrypted); otherwise encrypt in place.
   - Print a summary: `N rows migrated, M already encrypted, K skipped (empty)`.
   - Idempotent — safe to run multiple times.

## No behavior change to the SPA

- The settings route (`/ajax/settings.php`) continues to return HTTP 200.
- Saving via `app.js` → `POST /api/settings_save.php` continues to work.
- The `base_url` column is non-secret and unchanged.
- No other SPA routes are affected.
