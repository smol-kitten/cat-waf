#!/usr/bin/env php
<?php
// Run database migrations

require_once __DIR__ . '/config/database.php';

try {
    echo "Running database migrations...\n";
    
    $db = getDB();
    
    // Run create_jobs_table migration
    $sql = file_get_contents(__DIR__ . '/migrations/create_jobs_table.sql');
    $db->exec($sql);
    echo "✓ Created jobs table\n";
    
    echo "\nMigrations completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
