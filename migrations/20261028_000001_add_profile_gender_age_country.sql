-- Migration: add gender, age, country to profiles
-- Date: 2026-10-28

USE say_my_mind;

ALTER TABLE profiles
    ADD COLUMN gender ENUM('male', 'female', 'other', 'prefer_not_to_say') NULL AFTER bio,
    ADD COLUMN age TINYINT UNSIGNED NULL AFTER gender,
    ADD COLUMN country VARCHAR(100) NULL AFTER age,
    ADD CONSTRAINT chk_profiles_age CHECK (age IS NULL OR age BETWEEN 13 AND 120);
