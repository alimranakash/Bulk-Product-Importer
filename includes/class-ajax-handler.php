<?php
defined('ABSPATH') || exit;

// This entire code block will be removed from the free version.
if ( ! function_exists( 'bpi_fs' ) || ! bpi_fs()->can_use_premium_code__premium_only() ) {
    return;
}

class BPI_Ajax_Handler {
    private $importer;
    private $scheduler;

    /**
     * Maximum products to store per chunk to avoid MySQL packet issues
     */
    const CHUNK_SIZE = 500;

    public function __construct() {
        $this->importer = new BPI_Importer();
        $this->scheduler = new BPI_Scheduler();

        // New unified upload handler
        add_action('wp_ajax_bpi_upload_file', [$this, 'handle_upload']);
        // Legacy support
        add_action('wp_ajax_bpi_upload_zip', [$this, 'handle_upload']);

        add_action('wp_ajax_bpi_start_import', [$this, 'start_import']);
        add_action('wp_ajax_bpi_get_progress', [$this, 'get_progress']);
        add_action('wp_ajax_bpi_cancel_import', [$this, 'cancel_import']);
        add_action('wp_ajax_bpi_cleanup', [$this, 'cleanup']);
    }

    /**
     * Refresh MySQL connection to prevent "packets out of order" errors
     * This is needed after long-running operations like Excel parsing
     */
    private function refresh_db_connection() {
        global $wpdb;

        // Close existing connection
        if ($wpdb->dbh) {
            if ($wpdb->use_mysqli) {
                mysqli_close($wpdb->dbh);
            } else {
                mysql_close($wpdb->dbh);
            }
            $wpdb->dbh = null;
        }

        // Re-establish connection
        $wpdb->check_connection(false);
    }

    /**
     * Store products in chunks to avoid large database writes
     */
    private function store_products_chunked($products) {
        $total = count($products);
        $chunks = array_chunk($products, self::CHUNK_SIZE);
        $chunk_count = count($chunks);

        // Clear any existing chunks first
        $this->clear_product_chunks();

        // Store chunk metadata
        update_option('bpi_product_chunks_meta', [
            'total' => $total,
            'chunk_count' => $chunk_count,
            'chunk_size' => self::CHUNK_SIZE
        ], false);

        // Store each chunk separately
        foreach ($chunks as $index => $chunk) {
            update_option('bpi_product_chunk_' . $index, $chunk, false);
        }

        return $total;
    }

    /**
     * Retrieve all products from chunks
     */
    private function get_products_from_chunks() {
        $meta = get_option('bpi_product_chunks_meta');
        if (!$meta) {
            return null;
        }

        $products = [];
        for ($i = 0; $i < $meta['chunk_count']; $i++) {
            $chunk = get_option('bpi_product_chunk_' . $i);
            if ($chunk && is_array($chunk)) {
                $products = array_merge($products, $chunk);
            }
        }

        return empty($products) ? null : $products;
    }

    /**
     * Clear all product chunks from database
     */
    private function clear_product_chunks() {
        $meta = get_option('bpi_product_chunks_meta');
        if ($meta && isset($meta['chunk_count'])) {
            for ($i = 0; $i < $meta['chunk_count']; $i++) {
                delete_option('bpi_product_chunk_' . $i);
            }
        }
        delete_option('bpi_product_chunks_meta');
    }

    public function handle_upload() {
        check_ajax_referer('bpi_import_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        // Support both old 'zip_file' and new 'import_file' parameter names
        $file = null;
        if (!empty($_FILES['import_file'])) {
            $file = $_FILES['import_file'];
        } elseif (!empty($_FILES['zip_file'])) {
            $file = $_FILES['zip_file'];
        }

        if (!$file) {
            wp_send_json_error(['message' => 'No file uploaded']);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Upload error: ' . $file['error']]);
        }

        $upload_type = isset($_POST['upload_type']) ? sanitize_text_field($_POST['upload_type']) : 'zip';
        $file_type = wp_check_filetype($file['name']);
        $ext = strtolower($file_type['ext']);

        // Validate file type
        if ($upload_type === 'zip' && $ext !== 'zip') {
            wp_send_json_error(['message' => 'Invalid file type. Please upload a ZIP file.']);
        }
        if ($upload_type === 'excel' && !in_array($ext, ['xlsx', 'xls'])) {
            wp_send_json_error(['message' => 'Invalid file type. Please upload an Excel file (.xlsx or .xls).']);
        }

        if (!file_exists(BPI_UPLOAD_DIR)) {
            wp_mkdir_p(BPI_UPLOAD_DIR);
        }

        try {
            if ($upload_type === 'excel') {
                // Single Excel file upload
                $this->handle_excel_upload($file);
            } else {
                // ZIP file upload with file-by-file processing
                $this->handle_zip_upload($file);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle single Excel file upload
     */
    private function handle_excel_upload($file) {
        $timestamp = time();
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $excel_path = BPI_UPLOAD_DIR . 'import_' . $timestamp . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $excel_path)) {
            wp_send_json_error(['message' => 'Failed to save uploaded file']);
        }

        // Store file info for file-by-file processing
        update_option('bpi_import_files', [
            [
                'path' => $excel_path,
                'name' => $file['name']
            ]
        ], false);

        // No images directory for single Excel
        update_option('bpi_import_images_dir', '', false);

        wp_send_json_success([
            'message' => 'Excel file uploaded successfully',
            'excel_files' => 1
        ]);
    }

    /**
     * Handle ZIP file upload with file-by-file processing
     */
    private function handle_zip_upload($file) {
        $zip_path = BPI_UPLOAD_DIR . 'import_' . time() . '.zip';

        if (!move_uploaded_file($file['tmp_name'], $zip_path)) {
            wp_send_json_error(['message' => 'Failed to save uploaded file']);
        }

        $extract_dir = $this->importer->extract_zip($zip_path);
        $excel_files = $this->importer->get_excel_files($extract_dir);

        // Clean up ZIP file, keep extracted directory
        unlink($zip_path);

        if (empty($excel_files)) {
            $this->importer->cleanup($extract_dir);
            wp_send_json_error(['message' => 'No Excel files found in the ZIP']);
        }

        // Store file paths for file-by-file processing (NOT products!)
        $file_list = [];
        foreach ($excel_files as $path) {
            $file_list[] = [
                'path' => $path,
                'name' => basename($path)
            ];
        }

        update_option('bpi_import_files', $file_list, false);
        update_option('bpi_import_extract_dir', $extract_dir, false);

        // Store images directory for later use
        $images_dir = $extract_dir . 'images' . DIRECTORY_SEPARATOR;
        if (!is_dir($images_dir)) {
            $images_dir = $extract_dir . 'Images' . DIRECTORY_SEPARATOR;
        }
        update_option('bpi_import_images_dir', is_dir($images_dir) ? $images_dir : '', false);

        wp_send_json_success([
            'message' => 'ZIP extracted successfully',
            'excel_files' => count($excel_files)
        ]);
    }

    public function start_import() {
        check_ajax_referer('bpi_import_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        // Get file list for file-by-file processing
        $files = get_option('bpi_import_files');

        if (empty($files)) {
            // Fallback: Check legacy chunked storage for backwards compatibility
            $products = $this->get_products_from_chunks();
            if (!$products) {
                wp_send_json_error(['message' => 'No files to import. Please upload a file first.']);
            }

            // Use legacy method
            return $this->start_import_legacy($products);
        }

        $batch_size = isset($_POST['batch_size']) ? sanitize_text_field($_POST['batch_size']) : 25;
        if ($batch_size !== 'all') {
            $batch_size = absint($batch_size);
        }

        try {
            delete_option('bpi_parent_map');

            $images_dir = get_option('bpi_import_images_dir', '');

            // Schedule file-by-file import
            $import_id = $this->scheduler->schedule_file_import($files, $batch_size, $images_dir);

            wp_send_json_success([
                'message' => 'Import scheduled successfully',
                'import_id' => $import_id,
                'files' => count($files)
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Legacy import method for backwards compatibility
     */
    private function start_import_legacy($products) {
        $batch_size = isset($_POST['batch_size']) ? sanitize_text_field($_POST['batch_size']) : 25;
        if ($batch_size !== 'all') {
            $batch_size = absint($batch_size);
        }

        try {
            delete_option('bpi_parent_map');

            $import_id = $this->scheduler->schedule_import($products, $batch_size);

            // Clear chunked storage after scheduling
            $this->clear_product_chunks();

            wp_send_json_success([
                'message' => 'Import scheduled successfully',
                'import_id' => $import_id,
                'total' => count($products)
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function get_progress() {
        check_ajax_referer('bpi_import_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $import_id = isset($_POST['import_id']) ? sanitize_text_field($_POST['import_id']) : '';

        if (empty($import_id)) {
            $import_id = get_option('bpi_current_import', '');
        }

        if (empty($import_id)) {
            wp_send_json_error(['message' => 'No active import']);
        }

        $progress = $this->scheduler->get_progress($import_id);

        if (!$progress) {
            wp_send_json_error(['message' => 'Import not found']);
        }

        $pending = $this->scheduler->get_pending_count();

        wp_send_json_success([
            'import_id' => $import_id,
            'total' => $progress['total'],
            'processed' => $progress['processed'],
            'created' => $progress['created'],
            'updated' => $progress['updated'],
            'skipped' => $progress['skipped'],
            'errors' => array_slice($progress['errors'], -10),
            'status' => $progress['status'],
            'pending_actions' => $pending,
            'complete' => $progress['status'] === 'complete'
        ]);
    }

    public function cancel_import() {
        check_ajax_referer('bpi_import_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $import_id = isset($_POST['import_id']) ? sanitize_text_field($_POST['import_id']) : '';

        if (empty($import_id)) {
            $import_id = get_option('bpi_current_import', '');
        }

        if (!empty($import_id)) {
            $this->scheduler->cancel_import($import_id);
        }

        wp_send_json_success(['message' => 'Import cancelled']);
    }

    public function cleanup() {
        check_ajax_referer('bpi_import_nonce', 'nonce');

        // Clear legacy transient storage
        delete_transient('bpi_pending_products');

        // Clear chunked product storage
        $this->clear_product_chunks();

        // Clear file-based storage
        delete_option('bpi_import_files');
        delete_option('bpi_import_images_dir');

        // Cleanup extract directory if exists
        $extract_dir = get_option('bpi_import_extract_dir');
        if ($extract_dir && is_dir($extract_dir)) {
            $this->importer->cleanup($extract_dir);
        }
        delete_option('bpi_import_extract_dir');

        delete_option('bpi_parent_map');

        wp_send_json_success(['message' => 'Cleanup complete']);
    }
}
