# REFACTOR-AUDIT: Content Safety Gate

> Status: **AUDIT ONLY** — no code has been changed yet.

---

## 1. Draft Insertion Sites

### A. `scripts/generate_drafts.php` — lines 148–155 (ONLY INSERT SITE)

```php
$stmt = $pdo->prepare("
    INSERT INTO ai_drafts (event_id, type, content, status)
    VALUES (?, ?, ?, ?)
");

$stmt->execute([$event['id'], "changelog",     $changelog, "draft"]);
$stmt->execute([$event['id'], "linkedin_post", $linkedin,  "draft"]);
$stmt->execute([$event['id'], "founder_update",$founder,   "draft"]);
```

This is the **sole** INSERT point. It is called by `ajax/generate.php` via
`shell_exec("php scripts/generate_drafts.php")` when a user clicks "Run Generator"
in the web UI. Three rows are inserted per git event (changelog, linkedin_post,
founder_update), always with `status = 'draft'`.

There is NO safety check before this insert. All AI output lands in `ai_drafts`
regardless of content.

---

## 2. Draft Approval / Publish-Queue Sites

There are **two parallel code paths** for approval — a newer API path and an older
scripts path. Both must be gated.

### A. `api/draft_action.php` — lines 26–30 (PRIMARY approve path)

```php
case "approve":
    $pdo->prepare("UPDATE ai_drafts SET status='approved' WHERE id=?")->execute([$id]);
    $pdo->prepare("INSERT INTO publish_queue (draft_id, status) VALUES (?, 'pending')")->execute([$id]);
    echo json_encode(["status" => "approved"]);
    break;
```

This is the path called by the UI (`ajax/drafts.php` renders the Approve button
calling `draftAction(id, 'approve')`). It both marks the draft approved **and**
inserts into `publish_queue` in one step. This is the most important gate point.

### B. `scripts/draft_action.php` — lines 23–26 (OLDER path, no queue insert)

```php
case "approve":
    $stmt = $pdo->prepare("UPDATE ai_drafts SET status='approved' WHERE id=?");
    $stmt->execute([$id]);
    echo json_encode(["status" => "approved"]);
    break;
```

This older endpoint sets `status='approved'` but does **not** insert into
`publish_queue`. It appears to be a legacy path — unclear if it is still
reachable from the current UI. Must still be gated so a direct POST cannot
bypass the safety check.

### C. `publish.php` — lines 22–70 (LEGACY publish path, bypasses publish_queue)

```php
$stmt = $pdo->query("SELECT * FROM ai_drafts WHERE status = 'approved' ORDER BY created_at ASC");
// ... processes drafts directly, sets status='published'
```

This script reads **all** `status='approved'` drafts and publishes them without
going through `publish_queue` at all. It is a legacy path (simulation only for
now), but it must also be gated — a blocked draft reaching `status='approved'`
via the scripts path (B above) could be picked up here.

---

## 3. Publish-Queue Action Sites

These fire after a user clicks "Approve Publish" in the publish queue view.

### A. `api/publish_action.php` — lines 29–47 (PRIMARY)

Sets `publish_queue.status='approved'`, marks draft `status='published'`, inserts
`publish_targets`. This is the final step before real publication. A second-pass
safety check here is the last line of defence.

### B. `scripts/publish_action.php` — lines 24–46 (OLDER path)

Same logic as A but without the `ai_drafts` status update. Same gate needed.

---

## 4. DB Schema (inferred from code — no DB.md exists, no live DB in this environment)

```sql
ai_drafts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    event_id    INT,              -- FK to repo_events.id
    type        VARCHAR(64),      -- 'changelog' | 'linkedin_post' | 'founder_update'
    content     TEXT,
    status      VARCHAR(32),      -- 'draft' | 'approved' | 'rejected' | 'published'
    created_at  TIMESTAMP
)

publish_queue (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    draft_id    INT,              -- FK to ai_drafts.id
    status      VARCHAR(32),      -- 'pending' | 'approved' | 'rejected'
    created_at  TIMESTAMP
)

publish_targets (
    queue_id    INT,              -- FK to publish_queue.id
    target      VARCHAR(64)       -- 'linkedin' | 'blog' | 'changelog'
)

repo_events (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    commit_hash VARCHAR(64),
    author      VARCHAR(255),
    message     TEXT,
    created_at  TIMESTAMP
)
```

The implementation requires adding a `safety_flags` column to `ai_drafts`:

```sql
ALTER TABLE ai_drafts
    ADD COLUMN safety_severity VARCHAR(16) DEFAULT 'ok'       AFTER status,
    ADD COLUMN safety_reasons  TEXT        DEFAULT NULL        AFTER safety_severity;
```

This avoids changing the existing `status` enum semantics. A draft with
`safety_severity = 'block'` can be stored as `status = 'rejected'` immediately,
or kept as a new `status = 'blocked'` if you want a separate filter in the UI.

**Question for you to decide before implementation:** Should blocked drafts use
`status = 'rejected'` (reuses existing status) or a new `status = 'blocked'`
(more explicit, easier to filter, needs no UI changes unless you want to show
them)? Recommend `status = 'blocked'` — it keeps blocked content distinct from
human-rejected content.

---

## 5. Where the Gate Should Sit

```
scripts/generate_drafts.php
    line ~147 (before INSERT loop)
    ├── classifyDraft($content, $type)
    ├── if 'block'  → INSERT with status='blocked',   safety_severity='block'
    ├── if 'flag'   → INSERT with status='draft',     safety_severity='flag'
    └── if 'ok'     → INSERT with status='draft',     safety_severity='ok'   ← current behaviour

api/draft_action.php
    line ~26 (start of 'approve' case, before any DB write)
    ├── re-classifyDraft(fetch content by $id)
    ├── if 'block'  → return 400 JSON error, do NOT insert into publish_queue
    └── otherwise  → existing behaviour

scripts/draft_action.php
    line ~23 (start of 'approve' case)
    └── same gate as above (legacy path)

api/publish_action.php
    line ~29 (start of 'publish' action, before status updates)
    └── same gate as above (last line of defence)

scripts/publish_action.php
    line ~24 (start of 'publish' action)
    └── same gate as above
```

---

## 6. Proposed Keyword Block-List (for your approval before implementation)

### Layer 1 — BLOCK (hard stop, do not store as approvable draft)

**Security posture:**
- `security audit` / `sikkerhedsaudit`
- `sårbarhed` / `vulnerability` / `vulnerabilities`
- `CVE-`
- `audit finding` / `audit findings`
- `penetration test` / `pentest`
- `exploit` (as standalone word)
- `disclosure` / `responsible disclosure`

**AI authorship tells:**
- `Claude` (the model name, not "clause")
- `Claude Code`
- `AI-genereret` / `skrevet af AI` / `genereret af AI`
- `claude/` (branch-name pattern — regex `claude\/[a-z]`)
- `Anthropic`

**Internal process:**
- `CLA automation` / `contributor license`
- `CI pipeline` / `GitHub Actions` / `workflow run`
- `pre-commit hook`

**Unreleased / internal:**
- `unreleased` / `ikke frigivet`
- `internal only` / `intern brug`
- `ikke offentliggjort`

### Layer 2 — FLAG (store as draft but show warning, do not auto-approve)

- `intentional skip` / `bevidst spring over`
- `WIP` / `work in progress`
- `coming soon` / `snart`
- Any commit hash pattern (regex `\b[0-9a-f]{7,40}\b`) — may leak internal refs

---

## 7. Summary of Gate Points and Recommended Implementation Order

| Priority | File | Line | Action |
|----------|------|------|--------|
| 1 (insert) | `scripts/generate_drafts.php` | 147 | classify before INSERT, set status/severity |
| 2 (approve) | `api/draft_action.php` | 26 | re-classify before approve; block stops queue insert |
| 3 (legacy approve) | `scripts/draft_action.php` | 23 | same gate |
| 4 (publish) | `api/publish_action.php` | 29 | re-classify before final publish |
| 5 (legacy publish) | `scripts/publish_action.php` | 24 | same gate |

DB migration needed: `ALTER TABLE ai_drafts ADD COLUMN safety_severity, safety_reasons`.

---

**STOP — awaiting your confirmation of:**
1. `status='blocked'` vs `status='rejected'` for hard-blocked drafts
2. The keyword block-list above (add / remove / reclassify any terms)
3. Confirm these are all the insertion/approval points (no other endpoints?)

Do not implement until confirmed.
