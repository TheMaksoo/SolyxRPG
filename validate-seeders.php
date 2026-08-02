#!/usr/bin/env php
<?php

/**
 * Seeder Validation Script
 * 
 * This script validates that all seeders in database/seeders/ use idempotent patterns
 * (updateOrCreate or firstOrCreate) instead of non-idempotent methods (create or insert).
 * 
 * Usage: php validate-seeders.php
 */

$seedersPath = __DIR__ . '/database/seeders';
$errors = [];
$warnings = [];
$seedersChecked = 0;

// Colors for terminal output
$red = "\033[31m";
$yellow = "\033[33m";
$green = "\033[32m";
$reset = "\033[0m";

echo "🔍 Validating seeders for idempotent patterns...\n\n";

// Get all PHP files in seeders directory
$seederFiles = glob($seedersPath . '/*.php');

// Non-idempotent patterns to detect
$badPatterns = [
    'Model::create(' => 'Use updateOrCreate() or firstOrCreate() instead',
    '::create([' => 'Use updateOrCreate() or firstOrCreate() instead',
    'DB::table' => 'Use Eloquent models with updateOrCreate() instead',
    '->insert(' => 'Use updateOrCreate() or firstOrCreate() instead',
];

// Good patterns to look for
$goodPatterns = [
    'updateOrCreate',
    'firstOrCreate',
    'upsert',
    'sync', // Services that handle syncing (e.g., WikiSyncService)
];

foreach ($seederFiles as $file) {
    $seedersChecked++;
    $filename = basename($file);
    
    // Skip special files
    if ($filename === 'DatabaseSeeder.php' || str_ends_with($filename, '.backup') || str_ends_with($filename, '_temp.php')) {
        echo "⏭️  Skipping {$filename} (special file)\n";
        continue;
    }
    
    $content = file_get_contents($file);
    
    // Check for bad patterns
    $foundBadPatterns = [];
    foreach ($badPatterns as $pattern => $reason) {
        if (stripos($content, $pattern) !== false) {
            $foundBadPatterns[] = [
                'pattern' => $pattern,
                'reason' => $reason,
            ];
        }
    }
    
    // Check for good patterns
    $hasGoodPattern = false;
    foreach ($goodPatterns as $pattern) {
        if (stripos($content, $pattern) !== false) {
            $hasGoodPattern = true;
            break;
        }
    }
    
    // Report results
    if (!empty($foundBadPatterns)) {
        echo "{$red}❌ {$filename}{$reset}\n";
        foreach ($foundBadPatterns as $bad) {
            echo "   Found: {$bad['pattern']}\n";
            echo "   → {$bad['reason']}\n";
        }
        $errors[] = $filename;
    } elseif (!$hasGoodPattern) {
        echo "{$yellow}⚠️  {$filename}{$reset}\n";
        echo "   No idempotent pattern detected (no updateOrCreate or firstOrCreate)\n";
        echo "   → Please verify this seeder is safe to run multiple times\n";
        $warnings[] = $filename;
    } else {
        echo "{$green}✅ {$filename}{$reset}\n";
    }
}

// Summary
echo "\n" . str_repeat("─", 60) . "\n";
echo "📊 Summary\n";
echo str_repeat("─", 60) . "\n";
echo "Seeders checked: {$seedersChecked}\n";
echo "{$green}✅ Passed: " . ($seedersChecked - count($errors) - count($warnings)) . "{$reset}\n";

if (!empty($warnings)) {
    echo "{$yellow}⚠️  Warnings: " . count($warnings) . "{$reset}\n";
    foreach ($warnings as $warning) {
        echo "   - {$warning}\n";
    }
}

if (!empty($errors)) {
    echo "{$red}❌ Errors: " . count($errors) . "{$reset}\n";
    foreach ($errors as $error) {
        echo "   - {$error}\n";
    }
    echo "\n{$red}🚨 Some seeders are NOT idempotent!{$reset}\n";
    echo "Please update them to use updateOrCreate() or firstOrCreate().\n";
    echo "See docs/DATABASE_SETUP.md for examples.\n";
    exit(1);
} else {
    echo "\n{$green}🎉 All seeders use idempotent patterns!{$reset}\n";
    echo "Safe to run: php artisan db:seed\n";
    exit(0);
}
