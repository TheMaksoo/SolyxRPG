#!/usr/bin/env php
<?php

/**
 * Changelog Agent
 *
 * Reads PR metadata from environment variables and appends a new entry to
 * database/seeders/ChangelogSeeder.php. Runs automatically on every PR merged
 * into the `dev` branch via the changelog-agent.yml workflow.
 *
 * Environment variables (set by GitHub Actions):
 *   PR_TITLE      — PR title (used as the changelog entry title)
 *   PR_BODY       — PR description (used as the changelog entry body)
 *   PR_LABELS     — Comma-separated list of PR label names
 *   PR_NUMBER     — PR number
 *   PR_MERGED_AT  — ISO 8601 timestamp of when the PR was merged
 */

$seederPath = __DIR__ . '/../../database/seeders/ChangelogSeeder.php';

// ── 1. Read PR metadata ───────────────────────────────────────────────────────

$prTitle    = trim(getenv('PR_TITLE') ?: '');
$prBody     = trim(getenv('PR_BODY') ?: '');
$prLabels   = strtolower(getenv('PR_LABELS') ?: '');
$prNumber   = (int) (getenv('PR_NUMBER') ?: 0);
$prMergedAt = getenv('PR_MERGED_AT') ?: date('Y-m-d H:i:s');

if ($prTitle === '') {
    echo "No PR title found. Skipping changelog update.\n";
    exit(0);
}

// ── 2. Determine tag from PR labels ──────────────────────────────────────────

function resolveTag(string $labels): string
{
    $map = [
        'bug'       => 'fix',
        'fix'       => 'fix',
        'hotfix'    => 'fix',
        'feature'   => 'feature',
        'feat'      => 'feature',
        'enhancement' => 'feature',
        'balance'   => 'balance',
        'rebalance' => 'balance',
        'misc'      => 'misc',
        'chore'     => 'misc',
        'docs'      => 'misc',
        'refactor'  => 'misc',
    ];

    foreach (explode(',', $labels) as $label) {
        $label = strtolower(trim($label));
        if (isset($map[$label])) {
            return $map[$label];
        }
    }

    // Fall back to parsing the PR title prefix (e.g. "fix: ...", "feat: ...")
    return 'misc';
}

function resolveTitleTag(string $title): string
{
    $lower = strtolower($title);
    if (preg_match('/^fix[:(]/', $lower))     return 'fix';
    if (preg_match('/^feat[:(]/', $lower))    return 'feature';
    if (preg_match('/^feature[:(]/', $lower)) return 'feature';
    if (preg_match('/^balance[:(]/', $lower)) return 'balance';
    return '';
}

$tag = resolveTag($prLabels);
if ($tag === 'misc') {
    $fromTitle = resolveTitleTag($prTitle);
    if ($fromTitle !== '') {
        $tag = $fromTitle;
    }
}

// ── 3. Derive next version number ────────────────────────────────────────────

$seederContent = file_get_contents($seederPath);
if ($seederContent === false) {
    echo "ERROR: Could not read {$seederPath}\n";
    exit(1);
}

// Find all existing version strings in ENTRIES
preg_match_all("/\['(\d+\.\d+(?:\.\d+)?)',/", $seederContent, $matches);
$versions = $matches[1];

if (empty($versions)) {
    echo "ERROR: Could not parse existing versions from ChangelogSeeder.\n";
    exit(1);
}

// Find the highest version
usort($versions, 'version_compare');
$latest = end($versions);

// Determine next version:
//  - 'feature' bumps minor and drops patch  (1.70 → 1.71)
//  - everything else bumps patch             (1.70 → 1.70.1, 1.70.1 → 1.70.2)
function nextVersion(string $current, string $tag): string
{
    $parts = explode('.', $current);
    $major = (int) ($parts[0] ?? 1);
    $minor = (int) ($parts[1] ?? 0);
    $patch = (int) ($parts[2] ?? 0);

    if ($tag === 'feature') {
        return "{$major}." . ($minor + 1);
    }

    return "{$major}.{$minor}." . ($patch + 1);
}

$newVersion = nextVersion($latest, $tag);

// Make sure the new version doesn't already exist (e.g. two feature PRs in one run)
if (in_array($newVersion, $versions, true)) {
    // Append .1 until we find a free slot
    $base = $newVersion;
    $suffix = 1;
    while (in_array("{$base}.{$suffix}", $versions, true)) {
        $suffix++;
    }
    $newVersion = "{$base}.{$suffix}";
}

// ── 4. Build the changelog body ───────────────────────────────────────────────

// Use the first non-empty line of the PR body as the description, falling back
// to the PR title itself if the body is empty or just a template placeholder.
function extractBody(string $prBody, string $prTitle): string
{
    if ($prBody === '' || str_starts_with($prBody, '<!--')) {
        return $prTitle;
    }

    // Strip markdown headings, HTML comments, and checkbox lines
    $lines = explode("\n", $prBody);
    $cleaned = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '<!--')) {
            continue;
        }
        if (preg_match('/^- \[[ x]\]/', $line)) {
            continue;
        }
        $cleaned[] = $line;
    }

    $body = implode(' ', $cleaned);
    $body = preg_replace('/\s+/', ' ', $body);
    $body = trim($body);

    return $body !== '' ? $body : $prTitle;
}

$entryTitle = $prTitle;
$entryBody  = extractBody($prBody, $prTitle);

// Normalise the merged_at timestamp to MySQL format
$mergedAt = date('Y-m-d H:i:s', strtotime($prMergedAt));

// ── 5. Escape values for PHP source injection ─────────────────────────────────

function phpEscape(string $value): string
{
    return str_replace(
        ['\\', "'", "\n", "\r"],
        ['\\\\', "\\'", '\\n', ''],
        $value,
    );
}

$escapedTitle   = phpEscape($entryTitle);
$escapedBody    = phpEscape($entryBody);
$escapedVersion = phpEscape($newVersion);
$escapedTag     = phpEscape($tag);

$newEntry = "        ['{$escapedVersion}', \"{$escapedTitle}\", \"{$escapedBody}\", '{$escapedTag}', '{$mergedAt}'],";

// ── 6. Inject the new entry before the closing bracket of ENTRIES ─────────────

// Find the last ]; in the ENTRIES array (the closing of the const array)
$insertMarker = "    ];\n";
$insertPos = strrpos($seederContent, $insertMarker);

if ($insertPos === false) {
    echo "ERROR: Could not find the closing of ENTRIES array.\n";
    exit(1);
}

$updatedContent = substr($seederContent, 0, $insertPos)
    . $newEntry . "\n"
    . substr($seederContent, $insertPos);

file_put_contents($seederPath, $updatedContent);

echo "✅ Changelog entry added: [{$newVersion}] \"{$entryTitle}\" ({$tag}) — PR #{$prNumber}\n";
