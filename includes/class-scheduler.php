<?php
defined('ABSPATH') || exit;

class BPI_Scheduler {
    const PROCESS_ACTION = 'bpi_process_single_product';
    const BATCH_ACTION = 'bpi_process_batch';
    const PROGRESS_OPTION = 'bpi_import_progress';

    /**
     * Maximum products per storage chunk to avoid MySQL packet limits
     */
    const STORAGE_CHUNK_SIZE = 200;

    public function __construct() {
        add_action(self::PROCESS_ACTION, [$this, 'process_single_product'], 10, 2);
        add_action(self::BATCH_ACTION, [$this, 'process_scheduled_batch'], 10, 1);
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

        // Get only the products needed for this batch
        $batch = $this->get_products_for_batch($import_id, $batch_index, $batch_size);

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
        if ($progress && $progress['processed'] >= $progress['total']) {
            $this->complete_import($import_id);
        }
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
        $this->cleanup_products($import_id);
        delete_option('bpi_current_import');
    }

    public function cancel_import($import_id) {
        as_unschedule_all_actions(self::BATCH_ACTION, null, 'bulk-product-importer');

        $progress = $this->get_progress($import_id);
        if ($progress) {
            $progress['status'] = 'cancelled';
            update_option(self::PROGRESS_OPTION . '_' . $import_id, $progress, false);
        }
        $this->cleanup_products($import_id);
        delete_option('bpi_current_import');
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

