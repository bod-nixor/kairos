-- Migration: Add visibility to courses
-- Added for manager settings panel

ALTER TABLE courses
ADD COLUMN IF NOT EXISTS visibility ENUM('public', 'restricted') NOT NULL DEFAULT 'public';
