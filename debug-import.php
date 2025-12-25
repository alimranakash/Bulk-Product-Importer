<?php
/**
 * Debug/Cleanup script for bulk product importer
 * Run: php debug-import.php
 * Run: php debug-import.php cleanup  (to cleanup old data)
 */
require_once dirname(__DIR__, 3) . '/wp-load.php';

$action = $argv[1] ?? 'debug';

if ($action === 'cleanup') {
    echo "=== Cleaning up old import data ===\n\n";

    global $wpdb;

    // Cancel all pending BPI actions
    as_unschedule_all_actions('bpi_process_batch', null, 'bulk-product-importer');
    as_unschedule_all_actions('bpi_process_excel_file', null, 'bulk-product-importer');
    echo "Cancelled all pending BPI actions\n";

    // Delete all BPI options
    $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE 'bpi_products%'");
    $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE 'bpi_import%'");
    $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE 'bpi_product_chunk%'");
    delete_option('bpi_current_import');
    delete_option('bpi_parent_map');
    delete_transient('bpi_pending_products');
    echo "Deleted all BPI options and transients\n";

    echo "\n=== Cleanup Complete ===\n";
    exit;
}

echo "=== Bulk Product Importer Debug ===\n\n";

// Check Action Scheduler
echo "1. Action Scheduler available: " . (function_exists('as_schedule_single_action') ? 'YES' : 'NO') . "\n\n";

// Check pending actions
global $wpdb;
$pending = $wpdb->get_results(
    "SELECT action_id, hook, status, scheduled_date_gmt
     FROM {$wpdb->prefix}actionscheduler_actions
     WHERE hook LIKE 'bpi_%' AND status = 'pending'
     ORDER BY scheduled_date_gmt ASC
     LIMIT 20"
);
echo "2. Pending BPI Actions:\n";
if (empty($pending)) {
    echo "   No pending BPI actions found\n";
} else {
    foreach ($pending as $action) {
        echo "   - ID: {$action->action_id}, Hook: {$action->hook}, Scheduled: {$action->scheduled_date_gmt}\n";
    }
}

// Check current import
echo "\n3. Current import ID: " . get_option('bpi_current_import', 'none') . "\n";

// Check for import files
$files = get_option('bpi_import_files');
echo "\n4. Import Files Stored:\n";
if ($files && is_array($files)) {
    foreach ($files as $file) {
        echo "   - {$file['name']}\n";
    }
} else {
    echo "   No import files stored\n";
}

// Check for products stored by scheduler (chunked)
$options = $wpdb->get_results(
    "SELECT option_name, LENGTH(option_value) as size
     FROM {$wpdb->prefix}options
     WHERE option_name LIKE 'bpi_products_%' OR option_name LIKE 'bpi_import_%'
     ORDER BY option_name
     LIMIT 30"
);
echo "\n5. BPI Options in database:\n";
if (empty($options)) {
    echo "   No BPI options found\n";
} else {
    foreach ($options as $opt) {
        echo "   - {$opt->option_name}: " . number_format($opt->size) . " bytes\n";
    }
}

// Check import progress
$current_import = get_option('bpi_current_import');
if ($current_import) {
    $progress = get_option('bpi_import_progress_' . $current_import);
    echo "\n6. Import Progress for {$current_import}:\n";
    if ($progress) {
        echo "   Status: {$progress['status']}\n";
        if (!empty($progress['file_based'])) {
            echo "   Type: File-by-file processing\n";
            echo "   Files: {$progress['files_processed']}/{$progress['total_files']}\n";
        }
        echo "   Products: {$progress['processed']}/{$progress['total']}\n";
        echo "   Created: {$progress['created']}, Updated: {$progress['updated']}, Skipped: {$progress['skipped']}\n";
        echo "   Errors: " . count($progress['errors']) . "\n";
    } else {
        echo "   No progress data found\n";
    }
}

echo "\n=== End Debug ===\n";
echo "\nRun 'php debug-import.php cleanup' to clean up old data\n";

