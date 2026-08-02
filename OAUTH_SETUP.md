# OAuth Setup Guide

## Problem
OAuth login is currently failing because the provider credentials are not configured. Users are being redirected back to the landing page without being able to authorize.

## Root Cause
The `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`, `GOOGLE_CLIENT_ID`, and `GOOGLE_CLIENT_SECRET` environment variables are not set (or are empty).

## Solution

### 1. Discord OAuth Setup

1. Go to [Discord Developer Portal](https://discord.com/developers/applications)
2. Create a new application (or select existing one)
3. Go to "OAuth2" section
4. Copy the **Client ID** and **Client Secret**
5. Add the redirect URI: `https://play.solyx.gg/api/auth/discord/callback`
   - **IMPORTANT**: Must match your `APP_URL` exactly
   - Must use HTTPS in production
6. Under "OAuth2 Scopes", ensure the following are enabled (for reference):
   - `identify` (to get user ID and username)
   - `email` (to get user email)

Add to your `.env` file:
```env
DISCORD_CLIENT_ID=your_client_id_here
DISCORD_CLIENT_SECRET=your_client_secret_here
```

### 2. Google OAuth Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or select existing one)
3. Enable the "Google+ API"
4. Go to "Credentials" > "Create Credentials" > "OAuth 2.0 Client IDs"
5. Set application type to "Web application"
6. Add authorized redirect URI: `https://play.solyx.gg/api/auth/google/callback`
   - **IMPORTANT**: Must match your `APP_URL` exactly
   - Must use HTTPS in production
7. Copy the **Client ID** and **Client Secret**

Add to your `.env` file:
```env
GOOGLE_CLIENT_ID=your_client_id_here.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your_client_secret_here
```

### 3. Environment Configuration

Ensure these settings in `.env`:

```env
# Must be your production domain (HTTPS required for production)
APP_URL=https://play.solyx.gg

# Must include your domain for cookie/session to work
SANCTUM_STATEFUL_DOMAINS=play.solyx.gg,localhost,localhost:*,127.0.0.1

# For production behind proxy/CloudFlare (optional but recommended)
SESSION_DOMAIN=.solyx.gg
SESSION_SECURE_COOKIE=true
```

### 4. After Configuration

1. Clear config cache: `php artisan config:clear`
2. Clear route cache: `php artisan route:clear`
3. Restart your application server
4. Test OAuth login from the landing page

### 5. Verification

To verify OAuth is configured correctly, run:
```bash
php artisan tinker
>>> config('services.discord.client_id')
=> "your_actual_client_id"
>>> config('services.google.client_id')
=> "your_actual_client_id"
```

Both should show your actual client IDs, not `null`.

## Troubleshooting

### Still getting "unconfigured" error?
- Verify ENV variables are set correctly (no typos)
- Run `php artisan config:clear` to clear cached config
- Check that `.env` file is in the project root
- Restart your web server/PHP-FPM

### Getting "failed" error?
- Check that redirect URIs match exactly in provider console
- Verify client secret is correct
- Check logs for specific error: `storage/logs/laravel.log`

### Users redirected back immediately?
- This was the original bug - now fixed with proper error handling
- Check if ENV variables are actually loaded: `php artisan tinker` then `config('services.discord')`
- Ensure cookies are working (check browser dev tools > Application > Cookies)

### Callback fails with "state validation failed"?
- This means OAuth worked but CSRF check failed
- Ensure cookies are enabled
- Check that your domain is in `SANCTUM_STATEFUL_DOMAINS`
- Verify `APP_URL` matches your actual domain exactly
