
CREATE DATABASE IF NOT EXISTS say_my_mind
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE say_my_mind;

-- =====================================================
-- 1. USERS AND IDENTITY
-- =====================================================

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(24) NOT NULL UNIQUE,
    account_role ENUM('user', 'moderator', 'admin')
        NOT NULL DEFAULT 'user',
    status ENUM('active', 'suspended', 'deleted')
        NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    username VARCHAR(30) UNIQUE,
    display_name VARCHAR(50),
    avatar_url VARCHAR(500),
    bio VARCHAR(250),
    gender ENUM('male', 'female', 'other', 'prefer_not_to_say') NULL,
    age TINYINT UNSIGNED NULL,
    country VARCHAR(100) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT chk_profiles_age CHECK (age IS NULL OR age BETWEEN 18 AND 120)
) ENGINE=InnoDB;

CREATE TABLE auth_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash BINARY(32) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_settings (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    allow_matching BOOLEAN NOT NULL DEFAULT TRUE,
    allow_anonymous_messages BOOLEAN NOT NULL DEFAULT TRUE,
    show_online_status BOOLEAN NOT NULL DEFAULT FALSE,
    notifications_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    preferred_language VARCHAR(10) NOT NULL DEFAULT 'en',
    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- 2. RANDOM MATCHING AND CONVERSATIONS
-- =====================================================

CREATE TABLE match_queue_entries (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    status ENUM('waiting', 'matched', 'cancelled')
        NOT NULL DEFAULT 'waiting',
    preferences JSON NULL,
    queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_queue_status_time (status, queued_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('random', 'direct', 'group')
        NOT NULL DEFAULT 'random',
    status ENUM('active', 'ended', 'closed')
        NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_a_id BIGINT UNSIGNED NOT NULL,
    user_b_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL UNIQUE,
    status ENUM('active', 'ended', 'cancelled')
        NOT NULL DEFAULT 'active',
    matched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,

    CONSTRAINT chk_match_distinct_users
        CHECK (user_a_id <> user_b_id),

    INDEX idx_matches_user_a (user_a_id, status),
    INDEX idx_matches_user_b (user_b_id, status),

    FOREIGN KEY (user_a_id) REFERENCES users(id),
    FOREIGN KEY (user_b_id) REFERENCES users(id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id)
) ENGINE=InnoDB;

CREATE TABLE conversation_participants (
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME NULL,

    PRIMARY KEY (conversation_id, user_id),
    INDEX idx_participants_user (user_id, left_at),

    FOREIGN KEY (conversation_id) REFERENCES conversations(id)
        ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- 3. CHAT MESSAGES AND RECEIPTS
-- =====================================================

CREATE TABLE messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    message_type ENUM('text', 'image', 'audio', 'system')
        NOT NULL DEFAULT 'text',
    content TEXT NULL,
    media_url VARCHAR(1000) NULL,
    reply_to_message_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    deleted_at DATETIME NULL,

    INDEX idx_messages_conversation (conversation_id, id),
    INDEX idx_messages_sender (sender_id, created_at),

    FOREIGN KEY (conversation_id) REFERENCES conversations(id),
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (reply_to_message_id) REFERENCES messages(id)
) ENGINE=InnoDB;

CREATE TABLE message_receipts (
    message_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,

    PRIMARY KEY (message_id, user_id),

    FOREIGN KEY (message_id) REFERENCES messages(id)
        ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- 4. VOICE CALLS
-- =====================================================

CREATE TABLE calls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    initiated_by BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(30) NULL,
    provider_call_id VARCHAR(191) NULL,
    status ENUM(
        'ringing', 'accepted', 'rejected',
        'missed', 'ended', 'failed'
    ) NOT NULL DEFAULT 'ringing',
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_calls_conversation (conversation_id, created_at),

    FOREIGN KEY (conversation_id) REFERENCES conversations(id),
    FOREIGN KEY (initiated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE call_participants (
    call_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('invited', 'joined', 'rejected', 'left', 'missed')
        NOT NULL DEFAULT 'invited',
    joined_at DATETIME NULL,
    left_at DATETIME NULL,

    PRIMARY KEY (call_id, user_id),

    FOREIGN KEY (call_id) REFERENCES calls(id)
        ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- 5. NGL-STYLE ANONYMOUS MESSAGING
-- =====================================================

CREATE TABLE anonymous_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(64) NOT NULL UNIQUE,
    title VARCHAR(100) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_links_owner (owner_user_id),

    FOREIGN KEY (owner_user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE inbox_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    anonymous_link_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NULL,
    content TEXT NOT NULL,
    status ENUM('unread', 'read', 'replied', 'hidden', 'removed')
        NOT NULL DEFAULT 'unread',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_inbox_link_date (anonymous_link_id, created_at),
    INDEX idx_inbox_sender (sender_user_id, created_at),

    FOREIGN KEY (anonymous_link_id) REFERENCES anonymous_links(id),
    FOREIGN KEY (sender_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE inbox_replies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inbox_message_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    visibility ENUM('private', 'public')
        NOT NULL DEFAULT 'private',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_inbox_replies (inbox_message_id, created_at),

    FOREIGN KEY (inbox_message_id) REFERENCES inbox_messages(id)
        ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================
-- 6. BLOCKING AND REPORTING
-- =====================================================

CREATE TABLE user_blocks (
    blocker_user_id BIGINT UNSIGNED NOT NULL,
    blocked_user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (blocker_user_id, blocked_user_id),

    CONSTRAINT chk_block_distinct_users
        CHECK (blocker_user_id <> blocked_user_id),

    FOREIGN KEY (blocker_user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    FOREIGN KEY (blocked_user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_user_id BIGINT UNSIGNED NOT NULL,
    reported_user_id BIGINT UNSIGNED NULL,
    reported_message_id BIGINT UNSIGNED NULL,
    reported_inbox_message_id BIGINT UNSIGNED NULL,
    reason VARCHAR(50) NOT NULL,
    description VARCHAR(1000) NULL,
    status ENUM('pending', 'reviewing', 'resolved', 'dismissed')
        NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,

    CONSTRAINT chk_report_target CHECK (
        reported_user_id IS NOT NULL
        OR reported_message_id IS NOT NULL
        OR reported_inbox_message_id IS NOT NULL
    ),

    INDEX idx_reports_status_date (status, created_at),

    FOREIGN KEY (reporter_user_id) REFERENCES users(id),
    FOREIGN KEY (reported_user_id) REFERENCES users(id),
    FOREIGN KEY (reported_message_id) REFERENCES messages(id),
    FOREIGN KEY (reported_inbox_message_id)
        REFERENCES inbox_messages(id)
) ENGINE=InnoDB;

CREATE TABLE moderation_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    moderator_user_id BIGINT UNSIGNED NOT NULL,
    target_user_id BIGINT UNSIGNED NULL,
    report_id BIGINT UNSIGNED NULL,
    action_type VARCHAR(50) NOT NULL,
    reason VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_moderation_target (target_user_id, created_at),

    FOREIGN KEY (moderator_user_id) REFERENCES users(id),
    FOREIGN KEY (target_user_id) REFERENCES users(id),
    FOREIGN KEY (report_id) REFERENCES reports(id)
) ENGINE=InnoDB;
