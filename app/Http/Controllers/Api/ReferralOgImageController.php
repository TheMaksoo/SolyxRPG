<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Generates a 1200×630 PNG Open Graph image for referral invite links.
 *
 * When a player shares their invite URL on Discord, WhatsApp, X/Twitter, etc., the platform's
 * link-preview crawler hits this endpoint (via the og:image meta tag in welcome.blade.php) and
 * displays a branded card showing who invited them.  Anonymous (no code) requests get a generic
 * game-promo card.
 *
 * The image is rendered entirely with PHP's bundled GD extension — no Imagick or external
 * dependencies needed.  Results are cached for 24 h per code so crawlers that hit the URL
 * multiple times (common for Slack/Discord unfurls) never trigger more than one render.
 */
class ReferralOgImageController extends Controller
{
    private const WIDTH  = 1200;
    private const HEIGHT = 630;

    // Paths to system fonts bundled with the server image (present in the dev container and most
    // Debian/Ubuntu production hosts).  The controller falls back gracefully if they are absent.
    private const FONT_BOLD   = '/usr/share/fonts/truetype/lato/Lato-Heavy.ttf';
    private const FONT_REGULAR = '/usr/share/fonts/truetype/lato/Lato-Regular.ttf';

    public function __invoke(Request $request): Response
    {
        $code         = trim((string) $request->query('code', ''));
        $referrerName = null;

        if ($code) {
            $referrer     = User::where('referral_code', $code)
                ->orWhere('vanity_referral_code', $code)
                ->select('name')
                ->first();
            $referrerName = $referrer?->name;
        }

        $cacheKey = 'referral_og_image:' . ($code ?: '_generic');
        $png = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addHours(24), function () use ($referrerName) {
            return $this->render($referrerName);
        });

        return response($png, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function render(?string $referrerName): string
    {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        // ── Background gradient (dark navy → deep purple, left→right) ──────────────
        // GD has no native gradient fill so we draw it column by column.
        $r1 = 10;  $g1 = 12;  $b1 = 30;   // left: very dark navy
        $r2 = 40;  $g2 = 10;  $b2 = 80;   // right: deep purple
        for ($x = 0; $x < self::WIDTH; $x++) {
            $t   = $x / (self::WIDTH - 1);
            $col = imagecolorallocate(
                $img,
                (int) ($r1 + ($r2 - $r1) * $t),
                (int) ($g1 + ($g2 - $g1) * $t),
                (int) ($b1 + ($b2 - $b1) * $t),
            );
            imageline($img, $x, 0, $x, self::HEIGHT - 1, $col);
        }

        // ── Decorative accent bar at top ─────────────────────────────────────────
        $accent = imagecolorallocate($img, 120, 80, 220);  // bright purple
        imagefilledrectangle($img, 0, 0, self::WIDTH, 6, $accent);

        // ── Game logo (top-left) ─────────────────────────────────────────────────
        $logoPath = public_path('images/solyx-icon.png');
        if (file_exists($logoPath)) {
            $logo = @imagecreatefrompng($logoPath);
            if ($logo) {
                $lw = imagesx($logo);
                $lh = imagesy($logo);
                // Scale to 80 px tall, preserving aspect ratio.
                $scale  = 80 / $lh;
                $dstW   = (int) ($lw * $scale);
                $dstH   = 80;
                imagecopyresampled($img, $logo, 60, 60, 0, 0, $dstW, $dstH, $lw, $lh);
                imagedestroy($logo);
            }
        }

        // ── Colours for text ────────────────────────────────────────────────────
        $white      = imagecolorallocate($img, 255, 255, 255);
        $lightPurple = imagecolorallocate($img, 180, 150, 255);
        $dimWhite   = imagecolorallocate($img, 190, 190, 210);
        $gold       = imagecolorallocate($img, 255, 210, 80);

        $hasTtf = function_exists('imagettftext') && file_exists(self::FONT_BOLD);

        if ($hasTtf) {
            $fontBold    = self::FONT_BOLD;
            $fontRegular = file_exists(self::FONT_REGULAR) ? self::FONT_REGULAR : self::FONT_BOLD;

            // "SOLYX RPG" game title (top-left, next to icon)
            imagettftext($img, 26, 0, 160, 107, $white, $fontBold, 'SOLYX RPG');

            // Main headline
            if ($referrerName) {
                $headline = $this->truncate($referrerName, 24) . ' invited you!';
                imagettftext($img, 54, 0, 60, 290, $white, $fontBold, $headline);
            } else {
                imagettftext($img, 54, 0, 60, 290, $white, $fontBold, 'Play Solyx RPG Free!');
            }

            // Sub-line
            $sub = $referrerName
                ? 'Sign up free — you both earn 500 gems when you reach Level 5'
                : 'Classes · Dungeons · Crafting · Guilds · PvP · and more';
            imagettftext($img, 26, 0, 60, 360, $lightPurple, $fontRegular, $sub);

            // Reward pill background
            imagefilledroundrect($img, 60, 410, 580, 480, 14, imagecolorallocate($img, 50, 30, 100));
            $pillText = $referrerName ? '🎁  Join now and earn FREE gems' : '🎁  Free to play · No download required';
            imagettftext($img, 22, 0, 90, 455, $gold, $fontBold, $pillText);

            // Bottom-right: URL hint
            imagettftext($img, 20, 0, 60, self::HEIGHT - 40, $dimWhite, $fontRegular, config('app.url', 'solyxrpg.com'));
        } else {
            // Fallback — built-in bitmap font (always available)
            $headline = $referrerName ? "{$referrerName} invited you to Solyx RPG!" : 'Play Solyx RPG Free!';
            imagestring($img, 5, 60, 250, $headline, $white);
            $sub = $referrerName ? 'Join free — you both earn gems!' : 'Classes, dungeons, crafting & more';
            imagestring($img, 4, 60, 300, $sub, $lightPurple);
            imagestring($img, 3, 60, self::HEIGHT - 40, config('app.url', 'solyxrpg.com'), $dimWhite);
        }

        // ── Capture to string ───────────────────────────────────────────────────
        ob_start();
        imagepng($img, null, 6);   // compression 6 = good balance
        $data = ob_get_clean();
        imagedestroy($img);

        return $data;
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}

/**
 * GD doesn't have a built-in filled rounded-rectangle — add it as a module-level helper.
 */
function imagefilledroundrect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}
