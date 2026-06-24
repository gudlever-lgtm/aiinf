-- Adds content-safety columns to ai_drafts. Safe to re-run (IF NOT EXISTS).
ALTER TABLE ai_drafts
    ADD COLUMN IF NOT EXISTS safety_severity VARCHAR(16) NOT NULL DEFAULT 'ok'  AFTER status,
    ADD COLUMN IF NOT EXISTS safety_reasons  TEXT                DEFAULT NULL   AFTER safety_severity;
