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
- The repo contains no crontab or `.sh` file that references it, but `scripts/sync_fellis.sh` shows the project runs in a live web server context (`/var/www/aiinf.gnf.dk/`). A sysadmin crontab running `php /var/www/aiinf.gnf.dk/publish.php` is plausible and cannot be ruled out from the repo alone.
- **Verdict: AMBIGUOUS — leave untouched until owner confirms whether it is cron-invoked.**

---

## Summary: Safe to Delete

These five files have zero inbound references from any live file and are superseded by the SPA:

1. `drafts.php`
2. `publish_queue.php`
3. `settings.php`
4. `scripts/draft_action.php`
5. `scripts/publish_action.php`

**Do NOT delete:** `publish.php`, `scripts/env.php`, anything under `/ajax/`, `/api/`, or `/scripts/` except the two listed above.
