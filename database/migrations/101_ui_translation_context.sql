-- 101_ui_translation_context.sql
-- Group the translation editor by where a string appears. `context` holds the screen/section the
-- string was first seen on (e.g. "calendar", "holders", "settings"), so the editor can present
-- collapsible groups instead of one long flat list. Nullable; "General" covers anything unlabelled.
ALTER TABLE ui_translations ADD COLUMN context VARCHAR(40) NULL AFTER target;
