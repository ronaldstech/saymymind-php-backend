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

## Setup

1. Copy `config/config.example.php` to `config/config.php` and configure DB credentials (defaults to `say_my_mind` database).
2. Import `db.sql` into MySQL to create the schema.
3. Ensure Apache `mod_rewrite` is enabled and `.htaccess` is respected, or access via `index.php` directly.
4. Start Apache/MySQL (XAMPP recommended).

## Notes
- Sessions expire based on `session_ttl_days` in config (default 30 days).
- Logout revokes sessions (sets `revoked_at`). Expired sessions are also rejected.
- Profile updates only affect the `profiles` table; `users` table core fields are not modified via this endpoint.
- The `access_token` is returned only on registration - it cannot be recovered.
