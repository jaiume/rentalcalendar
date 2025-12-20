-- Add cleaner_tails column to properties table
-- When enabled, shows a lighter shade of cleaner color from day after cleaning until next event

ALTER TABLE properties ADD COLUMN cleaner_tails BOOLEAN NOT NULL DEFAULT FALSE;



