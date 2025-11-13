-- Migration: Remove duplicate bot whitelist entries
-- Keep only the oldest entry for each unique pattern

-- First, identify and delete duplicates (keep lowest ID per pattern)
DELETE t1 FROM bot_whitelist t1
INNER JOIN bot_whitelist t2 
WHERE t1.id > t2.id 
AND t1.pattern = t2.pattern;

-- Add unique constraint to prevent future duplicates
ALTER TABLE bot_whitelist 
ADD UNIQUE KEY `idx_pattern_unique` (`pattern`);
