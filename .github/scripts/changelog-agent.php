#!/usr/bin/env php
<?php

$seederPath = __DIR__ . '/../../database/seeders/ChangelogSeeder.php';

$prTitle = trim(getenv('PR_TITLE') ?: '');
$prBody = trim(getenv('PR_BODY') ?: '');
$prLabels = strtolower(getenv('PR_LABELS') ?: '');
$prNumber = (int) (getenv('PR_NUMBER') ?: 0);
$prMergedAt = getenv('PR_MERGED_AT') ?: date('Y-m-d H:i:s');

if ($prTitle === '') {
    echo "No PR title found. Skipping changelog update.\n";
    exit(0);
}

function resolveTag(string $labels): string
{
    $map = [
        'bug' => 'fix',
        'fix' => 'fix',
        'hotfix' => 'fix',
        'feature' => 'feature',
        'feat' => 'feature',
        'enhancement' => 'feature',
        'balance' => 'balance',
        'rebalance' => 'balance',
        'misc' => 'misc',
        'chore' => 'misc',
        'docs' => 'misc',
        'refactor' => 'misc',
    ];

    foreach (explode(',', $labels) as $label) {
        $label = strtolower(trim($label));
        if (isset($map[$label])) {
            return $map[$label];
        }
    }

    return 'misc';
}

function resolveTitleTag(string $title): string
{
    $lower = strtolower($title);

    if (preg_match('/^fix[:(]/', $lower)) {
        return 'fix';
    }
    if (preg_match('/^feat[:(]/', $lower)) {
        return 'feature';
    }
    if (preg_match('/^feature[:(]/', $lower)) {
        return 'feature';
    }
    if (preg_match('/^balance[:(]/', $lower)) {
        return 'balance';
    }

    return '';
}

$tag = resolveTag($prLabels);
if ($tag === 'misc') {
    $fromTitle = resolveTitleTag($prTitle);
    if ($fromTitle !== '') {
        $tag = $fromTitle;
    }
}

function resolveVisibility(string $labels): string
{
    foreach (explode(',', $labels) as $label) {
        $label = strtolower(trim($label));
        if (in_array($label, ['gm-changelog', 'gm-only', 'gm'], true)) {
            return 'gm';
        }
        if (in_array($label, ['tester-changelog', 'tester-only', 'tester'], true)) {
            return 'tester';
        }
    }

    return 'player';
}

$visibility = resolveVisibility($prLabels);

$seederContent = file_get_contents($seederPath);
if ($seederContent === false) {
    echo "ERROR: Could not read {$seederPath}\n";
    exit(1);
}

preg_match_all("/\\['(\\d+\\.\\d+(?:\\.\\d+)?)',/", $seederContent, $matches);
$versions = $matches[1];

if (empty($versions)) {
    echo "ERROR: Could not parse existing versions from ChangelogSeeder.\n";
    exit(1);
}

usort($versions, 'version_compare');
$latest = end($versions);

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

if (in_array($newVersion, $versions, true)) {
    $base = $newVersion;
    $suffix = 1;
    while (in_array("{$base}.{$suffix}", $versions, true)) {
        $suffix++;
    }
    $newVersion = "{$base}.{$suffix}";
}

function extractBody(string $prBody, string $prTitle): string
{
    if ($prBody === '' || str_starts_with($prBody, '<!--')) {
        return $prTitle;
    }

    $lines = explode("\n", $prBody);
    $cleaned = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '<!--')) {
            continue;
        }
        if (preg_match('/^- \\[[ x]\\]/', $line)) {
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
$entryBody = extractBody($prBody, $prTitle);
$mergedAt = date('Y-m-d H:i:s', strtotime($prMergedAt));

function phpEscape(string $value): string
{
    return str_replace(
        ['\\', "'", "\n", "\r"],
        ['\\\\', "\\'", '\\n', ''],
        $value,
    );
}

$escapedTitle = phpEscape($entryTitle);
$escapedBody = phpEscape($entryBody);
$escapedVersion = phpEscape($newVersion);
$escapedTag = phpEscape($tag);
$escapedVisibility = phpEscape($visibility);

$newEntry = "        ['{$escapedVersion}', \"{$escapedTitle}\", \"{$escapedBody}\", '{$escapedTag}', '{$mergedAt}', '{$escapedVisibility}'],";

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

echo "✅ Changelog entry added: [{$newVersion}] \"{$entryTitle}\" ({$tag}, visibility: {$visibility}) — PR #{$prNumber}\n";
