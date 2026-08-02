# OAuth Setup Guide

## Overview
OAuth login with Discord and Google uses the standard Laravel Socialite stateful flow. It's simple and clean - just set your environment variables and it works.

## Environment Variables Required

Add these to your `.env` file or hosting platform's environment configuration:

```env
DISCORD_CLIENT_ID=your_client_id_here
DISCORD_CLIENT_SECRET=your_client_secret_here
GOOGLE_CLIENT_ID=your_client_id_here.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your_client_secret_here
```

**IMPORTANT**: Never commit these credentials to the repository.

### 1. Discord OAuth Setup

1. Go to [Discord Developer Portal](https://discord.com/developers/applications)
2. Create a new application (or select existing one)
3. Go to "OAuth2" section
4. Copy the **Client ID** and **Client Secret**
5. Add the redirect URI: `{APP_URL}/api/auth/discord/callback`
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
3. (Optional) Enable **Google People API** only if you need extra profile fields beyond standard OAuth claims
4. Go to "Credentials" > "Create Credentials" > "OAuth 2.0 Client IDs"
5. Set application type to "Web application"
6. Add authorized redirect URI: `{APP_URL}/api/auth/google/callback`
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
# Your production domain (HTTPS required for production)
APP_URL=https://play.solyx.gg

# Must include your domain for cookie/session to work
SANCTUM_STATEFUL_DOMAINS=play.solyx.gg,localhost,localhost:*,127.0.0.1

# For production behind proxy/CloudFlare
SESSION_DOMAIN=.solyx.gg
SESSION_SECURE_COOKIE=true
```

### 4. After Configuration

1. Clear config cache: `php artisan config:clear`
2. Restart your application server
3. Test OAuth login from the landing page

That's it! OAuth now uses the standard Laravel Socialite flow.

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

### OAuth not working?
- Verify ENV variables are set correctly (no typos)
- Run `php artisan config:clear` to clear cached config
- Restart your web server/PHP-FPM
- Check redirect URIs match exactly in provider console
- Check logs: `storage/logs/laravel.log`

### Users can't log in?
- Ensure cookies are enabled in browser
- Check `SANCTUM_STATEFUL_DOMAINS` includes your domain
- Verify `APP_URL` matches your actual domain exactly
