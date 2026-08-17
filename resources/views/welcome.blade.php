<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Solyx') }}</title>
    <link rel="icon" type="image/png" href="/images/solyx-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/images/pwa-icon-192.png">
    <meta name="theme-color" content="#0b0b0c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Solyx">
    @if(request()->is('/'))
    <link rel="preload" as="image" href="/images/solyx-logo.png" fetchpriority="high">
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Loaded non-render-blocking (preload + swap-to-stylesheet-on-load) — a plain <link rel="stylesheet">
    here would make the browser wait on a third-party round trip before painting anything at all, since
    this page's initial HTML is just an empty SPA shell (see #app below). --}}
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Oxanium:wght@400;600;700;800&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@400;600;700;800&display=swap" rel="stylesheet"></noscript>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-8821979115757942" crossorigin="anonymous"></script>
    @php
        // Personalise Open Graph tags when an invite link is shared.
        $refCode    = request()->query('ref');
        $referrerName = null;
        if ($refCode) {
            $referrer = \App\Models\User::where('referral_code', trim($refCode))
                ->select('name')
                ->first();
            $referrerName = $referrer?->name;
        }
        $ogTitle       = $referrerName
            ? "{$referrerName} invited you to play Solyx RPG!"
            : 'Solyx RPG — Play Free Online';
        $ogDescription = $referrerName
            ? "Join {$referrerName} in Solyx RPG and you both earn gems. Sign up free and start your adventure!"
            : 'A free browser RPG with classes, dungeons, crafting, guilds, PvP and more. Play free today!';
        $ogImage       = config('app.url') . '/api/referral-og-image' . ($refCode ? '?code=' . urlencode($refCode) : '');
        $ogUrl         = config('app.url') . request()->getPathInfo() . (request()->getQueryString() ? '?' . request()->getQueryString() : '');
    @endphp
    {{-- Open Graph (Facebook, Discord, WhatsApp, iMessage, Slack …) --}}
    <meta property="og:type"        content="website">
    <meta property="og:site_name"   content="Solyx RPG">
    <meta property="og:title"       content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:image"       content="{{ $ogImage }}">
    <meta property="og:image:width"  content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url"         content="{{ $ogUrl }}">
    {{-- Twitter / X Card --}}
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image"       content="{{ $ogImage }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
