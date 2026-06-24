-- Run once on the production DB to add content safety columns to ai_drafts.
-- Existing rows default to safety_severity='ok' / safety_reasons=NULL.
-- Re-run sweep_existing_drafts.php after this migration to back-fill real scores.

ALTER TABLE ai_drafts
    ADD COLUMN safety_severity VARCHAR(16) NOT NULL DEFAULT 'ok'  AFTER status,
    ADD COLUMN safety_reasons  TEXT                DEFAULT NULL   AFTER safety_severity;
