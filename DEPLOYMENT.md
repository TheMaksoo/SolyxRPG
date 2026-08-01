# Production Deployment Configuration

## Discord OAuth 401 Error Fix

If you're experiencing a "401 Unauthorized" error on `/api/me` after Discord login, it's because the production domain configuration is missing or incorrect.

### Problem

The OAuth callback successfully authenticates the user, but the subsequent `/sanctum/csrf-cookie` and `/api/me` requests fail with 401 because:

1. Session cookies aren't being sent due to domain mismatch
2. CORS configuration doesn't include the production domain
3. Sanctum stateful domains don't match the actual domain

### Solution

For **production deployment on `https://play.solyx.gg`**, your `.env` file MUST include:

```env
# Application URL - MUST be HTTPS with exact domain
APP_URL=https://play.solyx.gg
APP_ENV=production
APP_DEBUG=false

# Session Domain - use leading dot for all subdomains
SESSION_DOMAIN=.solyx.gg
SESSION_SECURE_COOKIE=true

# Sanctum Stateful Domains - MUST match APP_URL
SANCTUM_STATEFUL_DOMAINS=play.solyx.gg

# CORS Allowed Origins - MUST match APP_URL exactly
CORS_ALLOWED_ORIGINS=https://play.solyx.gg

# Discord OAuth Configuration
DISCORD_CLIENT_ID=your_client_id_here
DISCORD_CLIENT_SECRET=your_client_secret_here
# Redirect URI in Discord Developer Portal MUST be: https://play.solyx.gg/api/auth/discord/callback

# Google OAuth Configuration  
GOOGLE_CLIENT_ID=your_client_id_here
GOOGLE_CLIENT_SECRET=your_client_secret_here
# Redirect URI in Google Cloud Console MUST be: https://play.solyx.gg/api/auth/google/callback
```

### Validation

After updating your `.env`, run:

```bash
php artisan oauth:validate
```

This command checks all OAuth-related configuration and tells you exactly what's wrong.

### OAuth Provider Setup

#### Discord

1. Go to https://discord.com/developers/applications
2. Create or select your application
3. Go to OAuth2 → General
4. Add redirect URI: `https://play.solyx.gg/api/auth/discord/callback`
5. Copy Client ID and Client Secret to your `.env`

#### Google

1. Go to https://console.cloud.google.com/
2. Create or select your project
3. Go to APIs & Services → Credentials
4. Create OAuth 2.0 Client ID (Web application)
5. Add authorized redirect URI: `https://play.solyx.gg/api/auth/google/callback`
6. Copy Client ID and Client Secret to your `.env`

### Common Mistakes

❌ **Wrong**: `SESSION_DOMAIN=play.solyx.gg` (no leading dot)  
✅ **Correct**: `SESSION_DOMAIN=.solyx.gg` (leading dot allows all subdomains)

❌ **Wrong**: `APP_URL=http://play.solyx.gg` (HTTP instead of HTTPS)  
✅ **Correct**: `APP_URL=https://play.solyx.gg` (HTTPS required)

❌ **Wrong**: `SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1` (only local domains)  
✅ **Correct**: `SANCTUM_STATEFUL_DOMAINS=play.solyx.gg` (production domain)

❌ **Wrong**: `CORS_ALLOWED_ORIGINS=*` (wildcard not allowed)  
✅ **Correct**: `CORS_ALLOWED_ORIGINS=https://play.solyx.gg` (exact origin)

### After Configuration Changes

1. Clear configuration cache:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

2. Restart your web server (PHP-FPM, etc.)

3. Hard refresh browser (Ctrl+Shift+R) or clear browser cookies for the domain

4. Test Discord login again

### Debugging

If it still doesn't work, check:

1. **Browser console** for CORS errors
2. **Network tab** - check if cookies are being sent with requests
3. **Application tab** - verify the session cookie domain and secure flag
4. **Server logs** - look for session/authentication errors

The session cookie should:
- Have domain: `.solyx.gg`
- Have `Secure` flag set (for HTTPS)
- Have `SameSite=lax`
- Be sent with every API request

### Technical Details

The OAuth flow works like this:

1. User clicks "Login with Discord"
2. User is redirected to Discord for authorization
3. Discord redirects back to `/api/auth/discord/callback?code=...`
4. Backend creates user account and logs them in
5. Backend calls `session()->save()` and `session()->regenerateToken()`
6. Backend redirects to `/dashboard` or `/character/create`
7. Frontend loads and calls `/sanctum/csrf-cookie`
8. Frontend calls `/api/me` to fetch user data

The 401 error happens at step 8 because the session cookie wasn't persisted or isn't being sent. This is fixed by ensuring all the domain/CORS configuration matches.
