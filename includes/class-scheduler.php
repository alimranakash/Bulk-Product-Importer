<?php
defined('ABSPATH') || exit;

// This entire code block will be removed from the free version.
if ( ! function_exists( 'bpi_fs' ) || ! bpi_fs()->can_use_premium_code__premium_only() ) {
    return;
}

class BPI_Scheduler {
    const PROCESS_ACTION = 'bpi_process_single_product';
    const BATCH_ACTION = 'bpi_process_batch';
    const FILE_ACTION = 'bpi_process_excel_file';
    const PROGRESS_OPTION = 'bpi_import_progress';

    /**
     * Maximum products per storage chunk to avoid MySQL packet limits
     */
    const STORAGE_CHUNK_SIZE = 200;

    public function __construct() {
        add_action(self::PROCESS_ACTION, [$this, 'process_single_product'], 10, 2);
        add_action(self::BATCH_ACTION, [$this, 'process_scheduled_batch'], 10, 1);
        add_action(self::FILE_ACTION, [$this, 'process_excel_file'], 10, 1);
    }

    /**
     * Schedule import by storing products in chunks and creating batch actions
     */
    public function schedule_import($products, $batch_size = 25) {
        $import_id = 'bpi_' . time() . '_' . wp_rand(1000, 9999);

        $this->init_progress($import_id, count($products));

        if ($batch_size === 'all') {
            $batch_size = count($products);
        }

        // Store products in small chunks to avoid MySQL packet limits
        $this->store_products_chunked($import_id, $products);

        // Schedule batches
        $total_batches = ceil(count($products) / $batch_size);

        for ($i = 0; $i < $total_batches; $i++) {
            as_schedule_single_action(
                time() + ($i * 2),
                self::BATCH_ACTION,
                [[
                    'import_id' => $import_id,
                    'batch_index' => $i,
                    'batch_size' => $batch_size
                ]],
                'bulk-product-importer'
            );
        }

        return $import_id;
    }

    /**
     * Schedule file-by-file import - processes each Excel file separately
     * This prevents memory issues with large imports
     */
    public function schedule_file_import($files, $batch_size = 25, $images_dir = '') {
        $import_id = 'bpi_' . time() . '_' . wp_rand(1000, 9999);

        // Initialize progress with 0 total (will be updated as files are processed)
        $this->init_progress($import_id, 0);

        // Mark as file-based import
        $progress = $this->get_progress($import_id);
        $progress['file_based'] = true;
        $progress['total_files'] = count($files);
        $progress['files_processed'] = 0;
        update_option(self::PROGRESS_OPTION . '_' . $import_id, $progress, false);

        // Store import configuration
        update_option("bpi_import_config_{$import_id}", [
            'batch_size' => $batch_size,
            'images_dir' => $images_dir,
            'files' => $files
        ], false);

        // Schedule each file for processing with staggered timing
        foreach ($files as $index => $file_info) {
            as_schedule_single_action(
                time() + ($index * 5), // 5 second delay between files
                self::FILE_ACTION,
                [[
                    'import_id' => $import_id,
                    'file_index' => $index,
                    'file_path' => $file_info['path'],
                    'file_name' => $file_info['name']
                ]],
                'bulk-product-importer'
            );
        }

        return $import_id;
    }

    /**
     * Process a single Excel file - reads products and schedules batch processing
     */
    public function process_excel_file($args) {
        $this->ensure_db_connection();

        $import_id = $args['import_id'];
        $file_index = $args['file_index'];
        $file_path = $args['file_path'];
        $file_name = $args['file_name'];

        // Get import configuration
        $config = get_option("bpi_import_config_{$import_id}");
        if (!$config) {
            error_log("BPI: Config not found for import {$import_id}");
            return;
        }

        $batch_size = $config['batch_size'];

        if (!file_exists($file_path)) {
            error_log("BPI: File not found: {$file_path}");
            $this->mark_file_processed($import_id, $file_index, 0);
            return;
        }

        try {
            // Read products from this Excel file
            $excel_reader = new BPI_Excel_Reader();
            $products = $excel_reader->read_file($file_path);

            if (empty($products)) {
                error_log("BPI: No products found in {$file_name}");
                $this->mark_file_processed($import_id, $file_index, 0);
                return;
            }

            // Sort products: simple first, then variable, then variations
            usort($products, function($a, $b) {
                $type_order = ['simple' => 0, 'variable' => 1, 'variation' => 2];
                $a_order = $type_order[strtolower($a['type'])] ?? 3;
                $b_order = $type_order[strtolower($b['type'])] ?? 3;
                return $a_order - $b_order;
            });

            $product_count = count($products);

            // Update total count in progress
            $progress = $this->get_progress($import_id);
            if ($progress) {
                $progress['total'] += $product_count;
                update_option(self::PROGRESS_OPTION . '_' . $import_id, $progress, false);
            }

            // Store products in chunks for this file
            $file_import_id = "{$import_id}_file_{$file_index}";
            $this->store_products_chunked($file_import_id, $products);

            // Calculate batches for this file
            if ($batch_size === 'all') {
                $batch_size = $product_count;
            }
            $total_batches = ceil($product_count / $batch_size);

            // Get number of already scheduled batches for proper timing
            $existing_batches = $this->get_pending_count();

            // Schedule batches for this file's products
            for ($i = 0; $i < $total_batches; $i++) {
                as_schedule_single_action(
                    time() + (($existing_batches + $i) * 2),
                    self::BATCH_ACTION,
                    [[
                        'import_id' => $import_id,
                        'file_import_id' => $file_import_id,
                        'batch_index' => $i,
                        'batch_size' => $batch_size
                    ]],
                    'bulk-product-importer'
                );
            }

            error_log("BPI: Scheduled {$total_batches} batches for {$file_name} ({$product_count} products)");
            $this->mark_file_processed($import_id, $file_index, $product_count);

        } catch (Exception $e) {
            error_log("BPI: Error processing {$file_name}: " . $e->getMessage());
            $this->mark_file_processed($import_id, $file_index, 0);
        }
    }

    /**
     * Mark a file as processed
     */
    private function mark_file_processed($import_id, $file_index, $product_count) {
        $progress = $this->get_progress($import_id);
        if ($progress) {
            $progress['files_processed'] = ($progress['files_processed'] ?? 0) + 1;
            update_option(self::PROGRESS_OPTION . '_' . $import_id, $progress, false);
        }
    }

    /**
     * Store products in multiple small chunks
     */
    private function store_products_chunked($import_id, $products) {
        $chunks = array_chunk($products, self::STORAGE_CHUNK_SIZE);
        $chunk_count = count($chunks);

        // Store metadata
        update_option("bpi_products_meta_{$import_id}", [
            'total' => count($products),
            'chunk_count' => $chunk_count,
            'chunk_size' => self::STORAGE_CHUNK_SIZE
        ], false);

        // Store each chunk
        foreach ($chunks as $index => $chunk) {
            update_option("bpi_products_{$import_id}_{$index}", $chunk, false);
        }
    }

    /**
     * Retrieve products for a specific batch by loading only needed chunks
     */
    private function get_products_for_batch($import_id, $batch_index, $batch_size) {
        $meta = get_option("bpi_products_meta_{$import_id}");
        if (!$meta) {
            return [];
        }

        $offset = $batch_index * $batch_size;
        $products_needed = [];

        // Calculate which chunks we need
        $start_chunk = floor($offset / self::STORAGE_CHUNK_SIZE);
        $end_offset = $offset + $batch_size;
        $end_chunk = floor(($end_offset - 1) / self::STORAGE_CHUNK_SIZE);

        // Load only the chunks we need
        $all_from_chunks = [];
        for ($i = $start_chunk; $i <= $end_chunk && $i < $meta['chunk_count']; $i++) {
            $chunk = get_option("bpi_products_{$import_id}_{$i}", []);
            if ($chunk) {
                $all_from_chunks = array_merge($all_from_chunks, $chunk);
            }
        }

        // Calculate offset within the loaded chunks
        $local_offset = $offset - ($start_chunk * self::STORAGE_CHUNK_SIZE);

        return array_slice($all_from_chunks, $local_offset, $batch_size);
    }

    /**
     * Clean up all product chunks for an import
     */
    private function cleanup_products($import_id) {
        $meta = get_option("bpi_products_meta_{$import_id}");
        if ($meta && isset($meta['chunk_count'])) {
            for ($i = 0; $i < $meta['chunk_count']; $i++) {
                delete_option("bpi_products_{$import_id}_{$i}");
            }
        }
        delete_option("bpi_products_meta_{$import_id}");
    }

    public function process_scheduled_batch($args) {
        $this->ensure_db_connection();

        $import_id = $args['import_id'];
        $batch_index = $args['batch_index'];
        $batch_size = $args['batch_size'];

        // For file-based imports, use file_import_id to get products
        $product_source_id = isset($args['file_import_id']) ? $args['file_import_id'] : $import_id;

        // Get only the products needed for this batch
        $batch = $this->get_products_for_batch($product_source_id, $batch_index, $batch_size);

        if (empty($batch)) {
            return;
        }

        $importer = new BPI_Importer();

        foreach ($batch as $product_data) {
            try {
                $result = $importer->import_single_product($product_data);
                $this->update_progress($import_id, $result);
            } catch (Exception $e) {
                $this->update_progress($import_id, 'error', $e->getMessage());
            }

            // Periodically clear object cache to free memory
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }

        $progress = $this->get_progress($import_id);
        if ($progress && $progress['processed'] >= $progress['total'] && $progress['total'] > 0) {
            // For file-based imports, also check if all files are processed
            if (!empty($progress['file_based'])) {
                $pending_file_actions = $this->get_pending_file_actions();
                $pending_batch_actions = $this->get_pending_count();
                // Complete only when no more actions pending (1 = current action)
                if ($pending_file_actions === 0 && $pending_batch_actions <= 1) {
                    $this->complete_import($import_id);
                }
            } else {
                $this->complete_import($import_id);
            }
        }
    }

    /**
     * Get count of pending file processing actions
     */
    private function get_pending_file_actions() {
        if (!function_exists('as_get_scheduled_actions')) {
            return 0;
        }
        return count(as_get_scheduled_actions([
            'hook' => self::FILE_ACTION,
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'group' => 'bulk-product-importer'
        ]));
    }

    public function process_single_product($import_id, $product_data) {
        $this->ensure_db_connection();

        $importer = new BPI_Importer();

        try {
            $result = $importer->import_single_product($product_data);
            $this->update_progress($import_id, $result);
        } catch (Exception $e) {
            $this->update_progress($import_id, 'error', $e->getMessage());
        }

        $progress = $this->get_progress($import_id);
        if ($progress['processed'] >= $progress['total']) {
            $this->complete_import($import_id);
        }
    }

    /**
     * Ensure database connection is healthy before processing
     */
    private function ensure_db_connection() {
        global $wpdb;

        // Check if connection is alive
        if (!$wpdb->check_connection(false)) {
            // Force reconnection
            if ($wpdb->dbh) {
                if ($wpdb->use_mysqli) {
                    @mysqli_close($wpdb->dbh);
                }
                $wpdb->dbh = null;
            }
            $wpdb->check_connection(true);
        }
    }

    private function init_progress($import_id, $total) {
        $progress = [
            'import_id' => $import_id,
            'total' => $total,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'status' => 'processing',
            'started_at' => current_time('mysql')
        ];
        update_option(self::PROGRESS_OPTION . '_' . $import_id, $progress, false);
        update_option('bpi_current_import', $import_id, false);
        return $progress;
    }

    private function update_progress($import_id, $result, $error_message = '') {
        $progress = $this->get_progress($import_id);
        if (!$progress) return;

        $progress['processed']++;
        
        if ($result === 'created') {
            $progress['created']++;
        } elseif ($result === 'updated') {
            $progress['updated']++;
        } elseif ($result === 'error') {
            $progress['skipped']++;
            if ($error_message) {
                $progress['errors'][] = $error_message;
            }
        }
        
        update_option(self::PROGRESS_OPTION . '_' . $import_id, $progress, false);
    }

    public function get_progress($import_id) {
        return get_option(self::PROGRESS_OPTION . '_' . $import_id, false);
    }

    private function complete_import($import_id) {
        $progress = $this->get_progress($import_id);
        if ($progress) {
            $progress['status'] = 'complete';
            $progress['completed_at'] = current_time('mysql');
            update_option(self::PROGRESS_OPTION . '_' . $import_id, $progress, false);
        }

        // Cleanup all product storage
        $this->cleanup_all_import_data($import_id);
        delete_option('bpi_current_import');
    }

    public function cancel_import($import_id) {
        // Cancel all scheduled actions
        as_unschedule_all_actions(self::BATCH_ACTION, null, 'bulk-product-importer');
        as_unschedule_all_actions(self::FILE_ACTION, null, 'bulk-product-importer');

        $progress = $this->get_progress($import_id);
        if ($progress) {
            $progress['status'] = 'cancelled';
            update_option(self::PROGRESS_OPTION . '_' . $import_id, $progress, false);
        }

        // Cleanup all product storage
        $this->cleanup_all_import_data($import_id);
        delete_option('bpi_current_import');
    }

    /**
     * Cleanup all import data including file-based chunks
     */
    private function cleanup_all_import_data($import_id) {
        // Cleanup main import products
        $this->cleanup_products($import_id);

        // Cleanup file-based import products
        $config = get_option("bpi_import_config_{$import_id}");
        if ($config && !empty($config['files'])) {
            foreach ($config['files'] as $index => $file_info) {
                $file_import_id = "{$import_id}_file_{$index}";
                $this->cleanup_products($file_import_id);
            }
        }
        delete_option("bpi_import_config_{$import_id}");

        // Cleanup file storage options
        delete_option('bpi_import_files');
        delete_option('bpi_import_images_dir');

        // Cleanup extract directory
        $extract_dir = get_option('bpi_import_extract_dir');
        if ($extract_dir && is_dir($extract_dir)) {
            $importer = new BPI_Importer();
            $importer->cleanup($extract_dir);
        }
        delete_option('bpi_import_extract_dir');
    }

    public function get_pending_count() {
        if (!function_exists('as_get_scheduled_actions')) {
            return 0;
        }
        return count(as_get_scheduled_actions([
            'hook' => self::BATCH_ACTION,
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'group' => 'bulk-product-importer'
        ]));
    }
}

