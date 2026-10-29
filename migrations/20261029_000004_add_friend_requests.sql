CREATE TABLE friend_requests (
    requester_user_id BIGINT UNSIGNED NOT NULL,
    recipient_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    PRIMARY KEY (requester_user_id, recipient_user_id),
    CONSTRAINT chk_friend_requests_distinct CHECK (requester_user_id <> recipient_user_id),
    INDEX idx_friend_requests_recipient (recipient_user_id, status, created_at),
    FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;