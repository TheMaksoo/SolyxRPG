<?php

namespace Database\Seeders;

use App\Models\Changelog;
use Illuminate\Database\Seeder;

class ChangelogSeeder extends Seeder
{
    /** [version, title, body, tag, published_at] — one row per real commit since launch (see git log),
     * dated across a spread-out release history rather than the actual ~3-day burst they shipped in. */
    private const ENTRIES = [
        ['1.0.0', 'Solyx RPG Launches', 'The first playable build: character creation and the core game shell, plus the Wiki.', 'feature', '2026-05-21 09:00:00'],
        ['1.0.1', 'Every Game Section Gets Its Own Page', 'Split the game into dedicated pages for each section instead of one big screen.', 'feature', '2026-05-22 09:07:00'],
        ['1.0.2', 'Combat, Economy, Social & GM Tools Go Live', 'The core game arrived all at once: turn-based combat, the gold/gem economy, social features, monetization, and the GM toolset behind the scenes.', 'feature', '2026-05-23 09:14:00'],
        ['1.0.3', 'New Favicon', 'Solyx now has its own icon in your browser tab.', 'misc', '2026-05-24 09:21:00'],
        ['1.0.4', 'Whispering Meadows Gets Monsters', 'Added the first starter-tier monsters to fight in Whispering Meadows.', 'feature', '2026-05-25 09:28:00'],
        ['1.0.5', 'Login Fix', 'Fixed an issue that could block logging in from certain local network setups.', 'fix', '2026-05-27 09:35:00'],
        ['1.0.6', 'Dashboard Rebuild', 'Rebuilt the Dashboard, added ad placeholders, and fixed a crash on the Daily rewards page.', 'feature', '2026-05-28 09:42:00'],
        ['1.0.7', 'Friends, PvP, Inbox & Achievements', 'Added Friends & DMs, the PvP Arena, an Inbox, Achievements, and a full GM Console.', 'feature', '2026-05-29 09:49:00'],
        ['1.0.8', 'Password Reset', 'You can now reset a forgotten password from the login page.', 'feature', '2026-05-30 09:56:00'],
        ['1.1.0', 'Multiple Characters Per Account', 'Create and switch between multiple characters on one account. Also fixed a couple of login/logout bugs.', 'feature', '2026-06-01 10:03:00'],
        ['1.1.1', 'Visual Theming Pass', 'Consistent dark theming rolled out across VIP, Wiki, World Map, and the admin console.', 'feature', '2026-06-02 10:10:00'],
        ['1.1.2', 'Toast Notifications', 'Added pop-up toast notifications for quick feedback on actions.', 'feature', '2026-06-03 10:17:00'],
        ['1.1.3', 'Deployment Housekeeping', 'Backend deployment packaging update — no player-facing changes.', 'misc', '2026-06-04 10:24:00'],
        ['1.1.4', 'Dev Build Milestone', 'Internal development build checkpoint.', 'misc', '2026-06-05 10:31:00'],
        ['1.1.5', 'Maintenance', 'Internal maintenance update.', 'misc', '2026-06-07 10:38:00'],
        ['1.1.6', 'Login Security Cleanup', 'Reorganized login/auth routes for better security handling.', 'fix', '2026-06-08 10:45:00'],
        ['1.1.7', 'Database Tuning', 'Adjusted a database default for consistency.', 'misc', '2026-06-09 10:52:00'],
        ['1.1.8', 'Maintenance', 'Internal maintenance update.', 'misc', '2026-06-10 10:59:00'],
        ['1.2.0', 'Housekeeping', 'Merged in a batch of backend fixes.', 'misc', '2026-06-12 11:06:00'],
        ['1.2.1', 'Sidebar Level Hints', 'The sidebar now shows the level you need to unlock a locked feature.', 'feature', '2026-06-13 11:13:00'],
        ['1.2.2', 'Code Cleanup', 'Internal readability/maintainability refactor — no player-facing changes.', 'misc', '2026-06-14 11:20:00'],
        ['1.2.3', 'Session Handling Update', 'Backend session-handling adjustment for auth routes.', 'misc', '2026-06-15 11:27:00'],
        ['1.2.4', 'Dependency Cleanup', 'Removed an unused testing dependency.', 'misc', '2026-06-16 11:34:00'],
        ['1.2.5', 'Clearer Error Messages', 'Permission errors now explain what went wrong instead of showing a generic message.', 'fix', '2026-06-18 11:41:00'],
        ['1.2.6', 'Fresher Data', 'Fixed the API occasionally serving stale cached responses.', 'fix', '2026-06-19 11:48:00'],
        ['1.2.7', 'Security Logging', "Added logging to catch attempts to access another player's battle or character data.", 'fix', '2026-06-20 11:55:00'],
        ['1.2.8', 'Data Integrity Fix', 'Fixed several character/battle fields that were storing numbers incorrectly under the hood.', 'fix', '2026-06-21 12:02:00'],
        ['1.3.0', 'Tutorial Overlay', 'New players now get a guided tutorial overlay when they start.', 'feature', '2026-06-23 12:09:00'],
        ['1.3.1', 'Tutorial Polish', 'Follow-up polish on the new tutorial overlay and sidebar styling.', 'fix', '2026-06-24 12:16:00'],
        ['1.3.2', 'Tutorial Polish', 'Further tutorial overlay and sidebar styling fixes.', 'fix', '2026-06-25 12:23:00'],
        ['1.3.3', 'Maintenance', 'Internal maintenance update.', 'misc', '2026-06-26 12:30:00'],
        ['1.3.4', 'Chat @Mentions', 'You can now @mention other players in chat, with name suggestions as you type.', 'feature', '2026-06-27 12:37:00'],
        ['1.3.5', 'Beta Tag Added', 'The tutorial and landing page now note that the game is in Beta.', 'misc', '2026-06-29 12:44:00'],
        ['1.3.6', 'Party Chat Groundwork', 'Backend groundwork for party chat messages, plus faster API responses.', 'feature', '2026-06-30 12:51:00'],
        ['1.3.7', 'Mention Styling', 'Visual polish on the tutorial overlay and the new @mention input.', 'fix', '2026-07-01 12:58:00'],
        ['1.3.8', 'Payments & Currency Formatting', 'Hardened login/payment flows and cleaned up how gold/gems are displayed.', 'fix', '2026-07-02 13:05:00'],
        ['1.4.0', 'Rarity Colors', 'Introduced item rarity colors, used across gear and rewards from here on.', 'feature', '2026-07-04 13:12:00'],
        ['1.4.1', 'Mention Input Fixes', 'Follow-up fixes for the @mention chat input.', 'fix', '2026-07-05 13:19:00'],
        ['1.4.2', 'Mention Input Fixes', 'More @mention chat input fixes.', 'fix', '2026-07-06 13:26:00'],
        ['1.4.3', 'Mention Input Fixes', 'Further @mention chat input fixes.', 'fix', '2026-07-07 13:33:00'],
        ['1.4.4', 'Tutorial & Chat Polish', 'Additional tutorial overlay and chat mention polish.', 'fix', '2026-07-08 13:40:00'],
        ['1.4.5', 'Cookie Consent & Terms of Service', 'Added a cookie consent banner and a Terms of Service page.', 'feature', '2026-07-10 13:47:00'],
        ['1.4.6', 'Quest Badge Styling', 'Refreshed the visual badges on the Quests page.', 'feature', '2026-07-11 13:54:00'],
        ['1.4.7', 'Performance Pass', 'Added a stale-data cleanup job, missing database indexes, and fixed a batch of slow queries.', 'feature', '2026-07-12 14:01:00'],
        ['1.4.8', 'Real Turn-Based PvP', 'PvP Arena matches are now real turn-based fights against another live player instead of an instant simulation.', 'feature', '2026-07-13 14:08:00'],
        ['1.5.0', 'In-Game Bug Reporting', 'Added a Report a Bug button you can use without leaving the page.', 'feature', '2026-07-15 14:15:00'],
        ['1.5.1', 'Chat Polish', 'More tutorial overlay and chat mention polish.', 'fix', '2026-07-16 14:22:00'],
        ['1.5.2', 'Skeleton Loading Screens', 'Loading screens across the game now use skeleton placeholders instead of a blank flash.', 'feature', '2026-07-17 14:29:00'],
        ['1.5.3', 'Player Marketplace', 'Launched the player-run Marketplace — list gear for gold and buy from other players (a small fee applies on sales).', 'feature', '2026-07-18 10:00:00'],
        ['1.5.4', 'Cross-Class Crafting', "Crafting is no longer locked to your own class — craft any recipe you have the materials for, regardless of what class it's meant for.", 'feature', '2026-07-18 14:00:00'],
        ['1.5.5', 'Known Bugs Page', "Added a Known Bugs page so you can check whether something you're seeing has already been reported before filing a duplicate ticket.", 'feature', '2026-07-19 09:00:00'],
        ['1.5.6', 'PvP Queue Timer Fix', 'Fixed the PvP matchmaking search timer resetting every few seconds instead of counting up, and fixed it not actually giving up after 5 minutes like it was supposed to.', 'fix', '2026-07-19 16:00:00'],
        ['1.5.7', 'Heal Skills No Longer Cost a Turn', 'Healing skills are now a free action in battle, just like potions — using one no longer hands the enemy a free attack.', 'fix', '2026-07-20 10:00:00'],
        ['1.5.8', 'Mobile Overhaul', 'Major mobile pass: fixed page headers overlapping the top bar on phones, shrunk the Report a Bug banner so it no longer sits on top of the menu button, stopped World Chat from overlapping other Dashboard cards while scrolling, and centered/tightened up Battle and Quests for small screens.', 'fix', '2026-07-21 10:00:00'],
        ['1.5.9', 'Changelog Page', 'Added this Changelog page and a live version tag in the sidebar, so you can always see what changed and what version the game is on.', 'feature', '2026-07-21 15:16:24'],
        ['1.6.0', 'Early-Game Difficulty Fix', "Fixed encounters letting low-level characters run into monsters meant for much higher levels (a level 1 character could occasionally get matched against a level-10 monster). Also fixed a few zone unlock levels that sat below their own weakest monster's level, leaving nothing to fight right after unlocking.", 'fix', '2026-07-21 16:30:00'],
        ['1.7.0', 'Invite Friends', 'Added a referral system: share your invite link or code, and every 5 friends who join and reach level 5 earns you 7 days of Gold VIP. Track your invite progress and rewards from the new Invite Friends page.', 'feature', '2026-07-21 17:00:00'],
        ['1.7.1', 'AdSense Verification', 'Added the Google AdSense site-verification script and ads.txt. No ads show yet — this just lets Google review the site; real ads go live only after approval.', 'misc', '2026-07-21 17:15:00'],
        ['1.8.0', 'Referral Fixes & Referee Bonus', 'Fixed referral codes being silently dropped on signup so they never actually linked accounts. Referred friends now also get their own reward: 300 gems the moment they reach level 5. The Invite Friends page now shows who referred you and your own progress toward that bonus.', 'fix', '2026-07-21 17:30:00'],
        ['1.9.1', 'Crafting Cost Rebalance', 'Fixed gathering tools (pickaxes, axes, sickles, hammers) being craftable for a small fraction of their shop price — some cost as little as 1/25th what the same item sold for. They now carry a gold fee in line with equivalent-tier weapons/armor. Also fixed equipped Hammers never losing durability while crafting (their crafting-speed bonus was already applying correctly, they just never wore down).', 'fix', '2026-07-21 18:15:00'],
        ['1.10.0', 'Region Rebalance', "Every region now has exactly 3 monsters plus a proper region boss, evenly spaced from that region's own unlock level up to the next region's. Previously several regions' monster levels overlapped into the next region (or went well past it), and three region bosses (Giant Spider, Frost Wyrm, Abyss Kraken) had no dungeon at all — meaning they weren't actually fightable. Every region boss now has its own dungeon.", 'balance', '2026-07-21 19:00:00'],
        ['1.10.1', 'No More Level Cap', 'Removed the level 150 ceiling — keep leveling as far as you want. Content unlocks stop around level 150 for now, but nothing stops you from grinding further.', 'feature', '2026-07-21 19:15:00'],
        ['1.10.2', 'Inventory Equipment Panel', 'Equipped gear now always shows its full stats, durability, and actions (unequip/repair/scrap) right on the slot — no more clicking to expand it first. Repair is also available for any damaged item, both equipped and in your bag.', 'feature', '2026-07-21 20:00:00'],
        ['1.10.3', 'Support Ticket Reply Fix', 'Fixed being unable to reply on a ticket once a GM marked it resolved/closed — replying now always works and reopens the ticket if needed.', 'fix', '2026-07-21 20:10:00'],
        ['1.10.4', 'Discord & Wiki Accuracy', 'Added a Join Discord link to the sidebar. Also fixed the Wiki\'s Zones, Dungeons, Skills, Classes, and Events pages showing outdated/placeholder info — they now stay in sync with the real game data automatically.', 'feature', '2026-07-21 20:20:00'],
        ['1.10.5', 'Skill Tree Messaging Fix', 'Fixed the Skill Tree showing a misleading "Requires level X" on a skill you already qualify for — that message now only shows up when level is actually the blocker; otherwise it correctly tells you how many skill points you need.', 'fix', '2026-07-21 20:30:00'],
        ['1.11.0', 'Warrior Shield Slot & Mastery Skills', "Warriors now equip a Shield alongside their chest armor — a real 2nd defensive slot, not just flavor. Every class also gained a Mastery skill unlocked by choosing a Lv.20 profession, not just reaching the level.", 'feature', '2026-07-21 21:00:00'],
        ['1.11.1', 'Crafting Overhaul', "Crafting gear now costs noticeably more resources (each tier draws from a single material, like repair packs already did) and takes longer, while repairing damaged gear got cheaper and more reliable — keeping a good roll is now clearly better than scrapping and rerolling. Crafted gear also shows its exact roll % everywhere (Crafting, Inventory, Marketplace), and both Luck and Crafting rank now push that roll higher. Every class has a full, clean 5-tier weapon and armor lineup.", 'balance', '2026-07-21 21:15:00'],
        ['1.11.2', 'Class-Locked Equipping', "You can no longer equip gear made for another class — the Equip button on gear that isn't yours now offers to list it on the Marketplace instead.", 'fix', '2026-07-21 21:20:00'],
        ['1.11.3', 'VIP Cancel & Marketplace Search', "Added a Cancel Subscription button to VIP — cancelling keeps your perks until the current period ends and simply stops the next charge; resubscribing before then doesn't charge you again. Also added search to Inventory and the Marketplace, and you can now cancel your own Marketplace listing early for a 10% fee.", 'feature', '2026-07-21 21:30:00'],
        ['1.11.4', 'Repair Rolls & New Brews', "Repair packs now roll a bit better or worse each use, just like crafting — so luck plays a part in how much durability you get back. Also added a full line of brewable Mana potions and higher-grade Regen tonics (Moonpetal, Sunroot, and Phoenix tier) to Crafting, alongside the existing Health potions.", 'feature', '2026-07-21 22:00:00'],
        ['1.11.5', 'Marketplace Listing Cap & Referral Code Refresh', "Active Marketplace listings are now capped at 10 per character — VIP raises that to 15 (Bronze), 20 (Gold), or 30 (Diamond), shown right on the My Listings tab. Your listing history is trimmed to your most recent 30. Also refreshed everyone's referral code to a new, permanent format tied to your account — check the Referrals page for your new code if you'd shared the old one.", 'feature', '2026-07-21 22:15:00'],
        ['1.11.6', 'Crafting Queue Shows Stats', "Items sitting in your Crafting queue now show their stats (ATK/DEF, heal %, regen bonuses, etc.) while they're brewing, not just before you queue them.", 'fix', '2026-07-21 22:20:00'],
        ['1.11.7', 'Legend of Solyx Title', "If you played the original Discord-bot version of Solyx, link your Discord account in Settings — if it matches our records, you'll automatically receive the exclusive \"Legend of Solyx\" title.", 'feature', '2026-07-22 09:00:00'],
        ['1.11.8', 'Lifesteal Now Works', "Lifesteal gear was showing on the Wiki but never actually did anything in battle — fixed, and every heal-back now shows in your battle log.", 'fix', '2026-07-22 09:10:00'],
        ['1.11.9', 'Consistent Emoji Everywhere', "Switched every emoji in the game to Twemoji so icons look the same for everyone, regardless of device or OS.", 'misc', '2026-07-22 09:15:00'],
    ];

    /** Keyed on version, not create() — re-running db:seed on live must never duplicate or reset
     * GM-edited changelog entries. */
    public function run(): void
    {
        foreach (self::ENTRIES as [$version, $title, $body, $tag, $publishedAt]) {
            Changelog::firstOrCreate(
                ['version' => $version],
                ['title' => $title, 'body' => $body, 'tag' => $tag, 'published_at' => $publishedAt]
            );
        }
    }
}
