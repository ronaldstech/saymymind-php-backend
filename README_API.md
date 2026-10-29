# Say My Mind API

A PHP + MySQL REST API for the "Say My Mind" anonymous social application.

## Overview

This API supports anonymous account creation, session management (create/renew/revoke), and profile retrieval/update. It uses bearer token authentication via sessions stored in `auth_sessions` with SHA-256 hashed tokens.

## Base URLs

- Development: `http://localhost/say-my-mind`
- API routes are accessible via `/api/...` (and also `/api/v1/...` is mapped to `/api/...` for compatibility)

## Authentication

Most endpoints require a bearer token in the `Authorization` header:

```http
Authorization: Bearer <access_token>
```

Tokens are issued on anonymous registration and are the only credential in this version. Treat them as secrets. Only the SHA-256 hash is stored in the database.

## Endpoints

### 1) Create an anonymous account
`POST /api/anonymous/register`

Creates a new anonymous user, profile, user settings, and a new authenticated session. Returns a one-time access token.

#### Request body (optional)
```json
{
  "display_name": "New User",
  "username": "new_user_123"
}
```

- `display_name` (string, optional): 1-50 chars after trimming. Set to `null`/empty to omit.
- `username` (string, optional): 3-30 chars, alphanumeric + underscore only. Must be unique.

#### Response (201 Created)
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 123,
      "public_id": "anon_ab12cd34ef56ab78",
      "username": "new_user_123",
      "display_name": "New User"
    },
    "access_token": "<base64url-random-token>",
    "token_type": "Bearer",
    "expires_at": "2026-11-27 12:34:56",
    "important": "Save this token securely. It is only returned at registration and cannot currently be recovered."
  }
}
```

#### Errors
- `422 INVALID_DISPLAY_NAME` - Invalid display name
- `422 INVALID_USERNAME` - Invalid username format
- `409 USERNAME_UNAVAILABLE` - Username already taken
- `500 REGISTRATION_FAILED` - Registration failed

---

### 2) Create or renew an authenticated session
`POST /api/auth/session`

Requires authentication. Renews/extends the current session's expiry and updates `last_used_at`.

#### Headers
```http
Authorization: Bearer <access_token>
```

#### Request body
None required.

#### Response (200 OK)
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 123,
      "public_id": "anon_ab12cd34ef56ab78",
      "account_role": "user",
      "status": "active"
    },
    "access_token": "<current-access-token>",
    "token_type": "Bearer",
    "expires_at": "2026-11-27 12:34:56"
  }
}
```

#### Errors
- `401 UNAUTHORIZED` - Missing/invalid/expired/revoked token or inactive account
- `500 SESSION_RENEW_FAILED` - Failed to renew session

---

### 3) Revoke the current session
`POST /api/auth/logout`

Requires authentication. Sets `revoked_at` on the current session. The token will no longer be valid after logout.

#### Headers
```http
Authorization: Bearer <access_token>
```

#### Request body
None required.

#### Response (200 OK)
```json
{
  "success": true,
  "data": {
    "revoked": true
  }
}
```

#### Errors
- `401 UNAUTHORIZED` - Missing/invalid/expired/revoked token or inactive account
- `500 LOGOUT_FAILED` - Failed to revoke session

---

### 4) Retrieve the current profile
`GET /api/profile/me`

Requires authentication. Returns user info and profile details.

#### Headers
```http
Authorization: Bearer <access_token>
```

#### Response (200 OK)
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 123,
      "public_id": "anon_ab12cd34ef56ab78",
      "account_role": "user",
      "status": "active",
      "created_at": "2026-10-28 10:20:30"
    },
    "profile": {
      "username": "new_user_123",
      "display_name": "New User",
      "avatar_url": null,
      "bio": null,
      "gender": null,
      "age": null,
      "country": null,
      "updated_at": "2026-10-28 10:20:30"
    }
  }
}
```

#### Errors
- `401 UNAUTHORIZED` - Missing/invalid/expired/revoked token or inactive account

---

### 5) Update optional profile information
`PATCH /api/profile/me`

Requires authentication. Updates one or more profile fields. Empty strings/nulls clear the field (where applicable).

#### Headers
```http
Authorization: Bearer <access_token>
Content-Type: application/json
```

#### Request body (at least one field)
```json
{
  "username": "new_name",
  "display_name": "My Name",
  "avatar_url": "https://example.com/avatar.jpg",
  "bio": "Hello there!",
  "gender": "male",
  "age": 25,
  "country": "Philippines"
}
```

Field rules:
- `username` (string|null): 3-30 chars, alphanumeric + underscore. Set `null` or `""` to clear.
- `display_name` (string|null): 1-50 chars after trimming. Set `null` or `""` to clear.
- `avatar_url` (string|null): valid URL, max 500 chars. Set `null` or `""` to clear.
- `avatar_id` (integer|null): ID from `GET /api/avatars`. Setting it selects a bundled avatar and clears `avatar_url`.
- `bio` (string|null): max 250 chars. Set `null` or `""` to clear.
- `gender` (string|null): one of `male`, `female`, `other`, `prefer_not_to_say`. Set `null` or `""` to clear.
- `age` (number|null): integer between 13 and 120. Set `null` or `""` to clear.
- `country` (string|null): 1-100 chars after trimming. Set `null` or `""` to clear.

#### Response (200 OK)
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 123,
      "public_id": "anon_ab12cd34ef56ab78",
      "account_role": "user",
      "status": "active"
    },
    "profile": {
      "username": "new_name",
      "display_name": "My Name",
      "avatar_url": "https://example.com/avatar.jpg",
      "bio": "Hello there!",
      "gender": "male",
      "age": 25,
      "country": "Philippines",
      "updated_at": "2026-10-28 11:05:42"
    }
  }
}
```

#### Errors
- `401 UNAUTHORIZED` - Missing/invalid/expired/revoked token or inactive account
- `422 EMPTY_BODY` - No fields provided
- `422 INVALID_USERNAME` / `422 INVALID_DISPLAY_NAME` / `422 INVALID_AVATAR_URL` / `422 INVALID_BIO` / `422 INVALID_GENDER` / `422 INVALID_AGE` / `422 INVALID_COUNTRY` - Validation errors
- `422 NO_VALID_FIELDS` - No valid updatable fields
- `409 USERNAME_UNAVAILABLE` / `409 CONFLICT` - Constraint/uniqueness conflicts
- `500 PROFILE_UPDATE_FAILED` - Update failed

## Error response format
All errors follow this structure:
```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human readable message"
  }
}
```

Common HTTP status codes: `400`, `401`, `404`, `405`, `409`, `422`, `500`, `503`.

### Anime avatar catalog
`GET /api/avatars`

Returns all bundled anime avatars from the database. Each entry includes its numeric `id`, filename, MIME type, and a base64 data URI in `image_data` that can be used directly as an image source. Use the returned `id` as `avatar_id` when updating `/api/profile/me`.

```json
{
  "success": true,
  "data": {
    "avatars": [
      {
        "id": 1,
        "filename": "avatar_01.jpg",
        "mime_type": "image/jpeg",
        "image_data": "data:image/jpeg;base64,..."
      }
    ]
  }
}
```

### Random matchmaking
`/api/matchmaking` requires a bearer token.

- `POST /api/matchmaking` joins the FIFO queue and tries to match immediately. Returns `202` with `status: "waiting"` when no eligible partner is available, or `200` with `status: "matched"` and the conversation details.
- `GET /api/matchmaking` checks for an active match or reports `waiting`/`idle`.
- `DELETE /api/matchmaking` cancels a waiting queue entry. It does not end an active conversation.

Match response shape:
```json
{
  "success": true,
  "data": {
    "status": "matched",
    "match": {
      "id": 12,
      "conversation_id": 34,
      "partner": {
        "public_id": "anon_ab12cd34ef56ab78",
        "username": "new_user_123",
        "display_name": "New User",
        "avatar_url": null,
        "avatar_id": 2
      },
      "matched_at": "2026-10-29 12:34:56"
    }
  }
}
```

Users whose `user_settings.allow_matching` is false receive `403 MATCHING_DISABLED`. The client can poll `GET /api/matchmaking` while waiting.

### Conversations
`GET /api/conversations` requires a bearer token and lists conversations the authenticated user has joined, newest first. Each entry includes active participants and the latest non-deleted message, if any.

Optional query parameters:
- `limit` (positive integer, default `20`, maximum `100`)
- `before_id` (positive conversation ID returned as `next_before_id` to fetch the next page)

```json
{
  "success": true,
  "data": {
    "conversations": [
      {
        "id": 34,
        "type": "random",
        "status": "active",
        "created_at": "2026-10-29 12:34:56",
        "ended_at": null,
        "participants": [
          { "public_id": "anon_ab12cd34ef56ab78", "display_name": "New User", "avatar_url": null, "avatar_id": 2 }
        ],
        "last_message": null
      }
    ],
    "pagination": { "limit": 20, "has_more": false, "next_before_id": null }
  }
}
```

Errors: `401 UNAUTHORIZED`, `422 INVALID_LIMIT`, `422 INVALID_BEFORE_ID`.

`GET /api/conversations/{id}/messages` returns messages in ascending ID order for a conversation the caller participates in. It accepts `limit` (default `50`, maximum `100`) and `after_id` (the last message ID received) for polling newer messages. Conversation participants include `username` and `is_mine`; each message includes `sender_username` so clients can label both sides of the chat.

`POST /api/conversations/{id}/messages` sends a message to an active conversation. It requires authentication and membership in that conversation.

```json
{ "type": "text", "content": "Hi!" }
```

`type` may be `text`, `image`, or `audio`; it defaults to `text`. Text messages require `content`. Image/audio messages accept `content`, `media_url`, or both. An optional `reply_to_message_id` must refer to a message in the same conversation. The response is `201 Created`; inactive conversations return `409 CONVERSATION_ENDED`.

### Anonymous inbox
Inbox management routes require bearer authentication. Public link submissions do not require authentication.

- `GET /api/inbox/links` lists the authenticated user's anonymous links.
- `POST /api/inbox/links` creates a link. Optional body: `{ "title": "Ask me anything" }`. The response contains its unique `slug`.
- `POST /api/inbox/links/{slug}/messages` submits an anonymous message with body `{ "content": "Hello!" }`.
- `GET /api/inbox/messages` lists messages sent to the authenticated user's links, newest first. Supports `limit` (default `20`, maximum `100`) and `before_id` pagination.
- `PATCH /api/inbox/messages/{id}` updates an owned message status. Body: `{ "status": "read" }`. Allowed values: `read`, `hidden`, `removed`.
- `POST /api/inbox/messages/{id}/replies` replies to an owned message. Body: `{ "content": "Thanks!", "visibility": "private" }`. `visibility` is optional and may be `private` or `public`; replies mark the inbox message `replied`.

Public link submissions create `unread` messages with no sender account. Use `404 LINK_NOT_FOUND` for missing/inactive slugs and `404 MESSAGE_NOT_FOUND` when a message does not exist or is not owned by the caller. Content must contain 1–5000 characters. Link titles are optional and limited to 100 characters.

## Setup

1. Copy `config/config.example.php` to `config/config.php` and configure DB credentials (defaults to `say_my_mind` database).
2. Import `db.sql` into MySQL to create the schema.
   For an existing database, apply `migrations/20261028_000002_add_anime_avatars.sql` instead.
3. Import the image files into the avatar table with `php scripts/seed_anime_avatars.php`.
4. Ensure Apache `mod_rewrite` is enabled and `.htaccess` is respected, or access via `index.php` directly.
5. Start Apache/MySQL (XAMPP recommended).

## Notes
- Sessions expire based on `session_ttl_days` in config (default 30 days).
- Logout revokes sessions (sets `revoked_at`). Expired sessions are also rejected.
- Profile updates only affect the `profiles` table; `users` table core fields are not modified via this endpoint.
- The `access_token` is returned only on registration - it cannot be recovered.
