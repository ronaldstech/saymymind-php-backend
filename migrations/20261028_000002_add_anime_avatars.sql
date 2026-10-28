USE say_my_mind;

CREATE TABLE anime_avatars (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(100) NOT NULL UNIQUE,
    mime_type VARCHAR(50) NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE profiles
    ADD COLUMN avatar_id SMALLINT UNSIGNED NULL AFTER avatar_url,
    ADD CONSTRAINT fk_profiles_avatar
        FOREIGN KEY (avatar_id) REFERENCES anime_avatars(id)
        ON DELETE SET NULL;
