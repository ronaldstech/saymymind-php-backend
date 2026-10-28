# Anonymous Social App — PHP API starter

This is the first backend milestone for the Flutter + PHP + MySQL application. It implements:
- `GET /api/v1/health`
- `POST /api/v1/auth/register` (creates an anonymous user, profile, settings, and bearer session)

It intentionally does not implement login/recovery yet. The current SQL schema has no password, email, phone, recovery-code, or trusted-device table. The returned bearer token is the only credential in this first version; save it securely in the Flutter app.

## 1. Install into XAMPP

1. Extract this folder into `C:\xampp\htdocs\anonymous-social-backend`.
2. In `config`, copy `config.example.php` to `config.php`.
3. Edit `config.php` if your MySQL host, username, password, port, or database differs.
4. Start Apache and MySQL from XAMPP.
5. Ensure the `anonymous_social_app` database and its 18 tables have been created from your SQL script.

## 2. Test health endpoint

Open this URL in your browser:

`http://localhost/anonymous-social-backend/api/v1/health`

Expected response:

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "database": "connected"
  }
}
```

## 3. Test anonymous registration

Use Postman or curl:

```bash
curl -X POST http://localhost/anonymous-social-backend/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d "{\"display_name\":\"New User\",\"username\":\"new_user_123\"}"
```

`username` and `display_name` are optional. If you omit them, the API creates an account with no profile labels.

A successful response includes:
- internal database `id`
- random public `public_id`
- one-time `access_token`
- token expiry

**Treat `access_token` like a password.** Store it using secure storage in Flutter; never log it or put it in a URL. This starter stores only a SHA-256 hash in `auth_sessions`.

## 4. Important development notes

- Do not expose this development server to the public internet as-is.
- Set `app_debug` to false and use HTTPS in production.
- Configure appropriate CORS allow-lists before calling from a browser-based client. Native Flutter apps generally do not use browser CORS enforcement.
- Add rate limiting, abuse controls, token revocation, and account recovery before production.
- If PHP reports `could not find driver`, enable `pdo_mysql` in the active `php.ini`, then restart Apache.
- If `.htaccess` routing does not work, enable Apache `mod_rewrite` and `AllowOverride All`, or use the provided `index.php` directly with query routing as a temporary test.
