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

# Prompt B Audit — Feedback loop + quality gates

## B-1. `draft_action.php` — what each action does today

| Action | SQL written | What is lost |
|--------|-------------|--------------|
| `approve` | `UPDATE ai_drafts SET status='approved'` + `INSERT IGNORE INTO publish_queue (draft_id,'pending')` | Runs `classifyDraft()` first; hard-blocks if severity=`block`. Nothing else written. |
| `reject` | `UPDATE ai_drafts SET status='rejected'` | No reason tag stored. Signal is discarded. |
| `save` | `UPDATE ai_drafts SET content=?` | Overwrites `content` in place. The original generated text is destroyed — the edit delta (training signal) is unrecoverable. |

**Gaps vs Prompt B requirements:**
- `reject` writes no vocabulary tag — reason is lost entirely.
- `save` destroys the original text; there is no way to recover the pre-edit version.
- No scoring pass happens before or after any action.
- No fabricated-claim flag is checked on `approve` (the content-safety BLOCK still fires, but the number gate does not exist yet).

---

## B-2. Generator — system prompt + few-shot assembly

**Files:** `scripts/generate_drafts.php` (main loop) + `scripts/ai_helpers.php` (helpers)

### `buildSystemMsg(PDO $pdo)` — `ai_helpers.php:66`

1. Builds a hard-coded Danish persona block (Lars / Fellis).
2. Calls `getFewShotExamples($pdo, $type, limit=2)` for each of three types.
3. Appends examples as `---`-delimited blocks in the system message.
4. Called once per generation run at `generate_drafts.php:100`; the string is reused
   for every `callMistral()` call in that run (both per-commit changelog calls and the
   batch LinkedIn+Founder call).

### `getFewShotExamples($pdo, $type, $limit=2)` — `ai_helpers.php:50`

```sql
SELECT content FROM ai_drafts
WHERE type = ? AND status IN ('approved', 'published')
ORDER BY id DESC LIMIT ?
```

**Gaps vs Prompt B requirements:**
- Limit is 2; prompt B wants 3–5.
- No preference for human-edited posts (no check for `original_content IS NOT NULL`).
- No preference for recently published (orders by `id`, not `published_at`).
- Does not distinguish auto-approved (never edited) from gold-standard human-edited drafts.

### Where few-shot injection point is

`buildSystemMsg()` at line 66 of `ai_helpers.php`. Updating `getFewShotExamples()` there
(bump limit, add column preference) is the only change needed for Part 3.

---

## B-3. `SHOW CREATE TABLE ai_drafts` — reconstructed from all migration files

No live DB is available; schema inferred from `scripts/migrate.php`,
`scripts/migrate_001.php`, `scripts/migrate_002.php`, and `scripts/db_migrate_safety.sql`.

| Column | Type | Default | Added by |
|--------|------|---------|----------|
| `id` | INT AUTO_INCREMENT PK | — | baseline |
| `event_id` | INT | — | baseline |
| `type` | VARCHAR | — | baseline (`changelog`, `linkedin_post`, `founder_update`) |
| `content` | TEXT / MEDIUMTEXT | — | baseline |
| `status` | VARCHAR | `'draft'` | baseline (`draft`, `approved`, `rejected`, `blocked`, `published`) |
| `safety_severity` | VARCHAR(16) | `'ok'` NOT NULL | migrate_003_safety.sql |
| `safety_reasons` | TEXT | NULL | migrate_003_safety.sql |
| `batch_event_ids` | TEXT | NULL | migrate_002.php |
| `published_at` | DATETIME | NULL | baseline (assumed) |
| `published_id` | VARCHAR(100) | NULL | migrate.php |
| `error` | TEXT | NULL | migrate.php |

Unique index: `uq_event_type` on `(event_id, type)`.

**Columns NOT YET present (all needed for Prompt B):**

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `original_content` | MEDIUMTEXT | NULL | Set once on first `save`; thereafter immutable. NULL = never edited by human. |
| `reject_reason` | VARCHAR(50) | NULL | Controlled vocabulary tag from reject UI. |
| `score_voice` | TINYINT | NULL | 1–5 voice-match score from second Mistral pass. |
| `score_specificity` | TINYINT | NULL | 1–5 specificity score. |
| `score_triviality` | TINYINT | NULL | 1–5 non-triviality score. |
| `flag_unverified_claim` | TINYINT | DEFAULT 0 | 1 if draft contains a number/% not present in source commit. |

---

## B-4. Implementation plan (independent parts, each a separate commit)

### Part 0 — `scripts/migrate_004.php` (schema first, zero app impact)

```sql
ALTER TABLE ai_drafts ADD COLUMN IF NOT EXISTS original_content MEDIUMTEXT NULL;
ALTER TABLE ai_drafts ADD COLUMN IF NOT EXISTS reject_reason VARCHAR(50) NULL;
ALTER TABLE ai_drafts ADD COLUMN IF NOT EXISTS score_voice TINYINT NULL;
ALTER TABLE ai_drafts ADD COLUMN IF NOT EXISTS score_specificity TINYINT NULL;
ALTER TABLE ai_drafts ADD COLUMN IF NOT EXISTS score_triviality TINYINT NULL;
ALTER TABLE ai_drafts ADD COLUMN IF NOT EXISTS flag_unverified_claim TINYINT NOT NULL DEFAULT 0;
```

All NULL-able / defaulted — existing rows unaffected.

### Part 1 — Capture the edit diff

**File:** `api/draft_action.php` `save` case.

Before `UPDATE ai_drafts SET content=?`, check whether `original_content IS NULL`.
If so, read current `content` and write both in one statement:

```sql
UPDATE ai_drafts
SET original_content = CASE WHEN original_content IS NULL THEN content ELSE original_content END,
    content = ?
WHERE id = ?
```

This is set-once: second and subsequent saves only update `content`.

### Part 2 — Structured reject reasons

**Files:** `api/draft_action.php` + UI (JS).

- Accept `reject_reason` POST param; validate it against the allowed vocabulary.
- `UPDATE ai_drafts SET status='rejected', reject_reason=? WHERE id=?`.
- UI: reject button opens a small picker before submitting:
  `off-voice | trivial | leaks-internal | repetitive | factually-wrong | other`.

### Part 3 — Few-shot from published posts (highest quality impact)

**File:** `scripts/ai_helpers.php` `getFewShotExamples()`.

Updated query:

```sql
SELECT COALESCE(original_content, content)  -- prefer edited version as gold standard (not yet: use content until col exists)
FROM ai_drafts
WHERE type = ?
  AND status IN ('approved', 'published')
ORDER BY
  (original_content IS NOT NULL) DESC,   -- human-edited first
  published_at DESC,                     -- most recent
  id DESC
LIMIT ?
```

Bump default limit from 2 to 4.

### Part 4 — Pre-review scoring pass

**Files:** `scripts/ai_helpers.php` (new `scoreDraft()`) + `scripts/generate_drafts.php` + UI.

`scoreDraft()`:
- One Mistral call at temperature 0.0, asking for JSON `{"voice":N,"specificity":N,"triviality":N}` (1–5).
- Wrapped in try/catch + CURLOPT_TIMEOUT; on failure, leave columns NULL — no exception propagated.
- Called from `insertDraft()` in `generate_drafts.php` after INSERT succeeds.
- Env var `SCORE_THRESHOLD` (default 3): drafts where any score < threshold are hidden from the
  default view and shown only under a "low score" filter toggle.

### Part 5 — Fabricated-number gate

**Files:** `scripts/ai_helpers.php` (new `hasUnverifiedClaim()`) + `scripts/generate_drafts.php` + UI.

`hasUnverifiedClaim(string $draftContent, string $sourceText): bool`:
- Regex-extract numbers and percentages from `$draftContent`.
- For each, check if it appears literally in `$sourceText` (commit message + `changed_files`).
- Return `true` if any extracted number is absent from source.

Called after `insertDraft()`. If true:
```sql
UPDATE ai_drafts SET flag_unverified_claim = 1 WHERE id = ?
```

UI shows a warning banner on flagged cards. `draft_action.php` `approve` does NOT hard-block
(human decides), but the flag must be visible. Auto-publish is not implemented (prompt 0 guard
still applies).

### Implementation order

0. `migrate_004.php` — run first, no app change.
1. Part 1 (edit diff) — minimal `draft_action.php` change.
2. Part 2 (reject reason) — `draft_action.php` + UI.
3. Part 3 (few-shot) — `ai_helpers.php` only, highest ROI.
4. Part 4 (scoring) — new helper + `generate_drafts.php` + UI filter.
5. Part 5 (fabrication gate) — new helper + `generate_drafts.php` + UI warning.

---

**STOP (Prompt B). Awaiting confirmation before writing any code.**
