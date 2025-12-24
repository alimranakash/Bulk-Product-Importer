<?php
defined('ABSPATH') || exit;

class BPI_Ajax_Handler {
    private $importer;

    public function __construct() {
        $this->importer = new BPI_Importer();
        add_action('wp_ajax_bpi_upload_zip', [$this, 'handle_upload']);
        add_action('wp_ajax_bpi_process_batch', [$this, 'process_batch']);
        add_action('wp_ajax_bpi_cleanup', [$this, 'cleanup']);
    }

    public function handle_upload() {
        check_ajax_referer('bpi_import_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        if (empty($_FILES['zip_file'])) {
            wp_send_json_error(['message' => 'No file uploaded']);
        }

        $file = $_FILES['zip_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Upload error: ' . $file['error']]);
        }

        $file_type = wp_check_filetype($file['name']);
        if ($file_type['ext'] !== 'zip') {
            wp_send_json_error(['message' => 'Invalid file type. Please upload a ZIP file.']);
        }

        if (!file_exists(BPI_UPLOAD_DIR)) {
            wp_mkdir_p(BPI_UPLOAD_DIR);
        }

        $zip_path = BPI_UPLOAD_DIR . 'import_' . time() . '.zip';
        if (!move_uploaded_file($file['tmp_name'], $zip_path)) {
            wp_send_json_error(['message' => 'Failed to save uploaded file']);
        }

        try {
            $extract_dir = $this->importer->extract_zip($zip_path);
            $excel_files = $this->importer->get_excel_files($extract_dir);
            
            if (empty($excel_files)) {
                $this->importer->cleanup($extract_dir);
                unlink($zip_path);
                wp_send_json_error(['message' => 'No Excel files found in the ZIP']);
            }

            $products = $this->importer->get_all_products($extract_dir);
            
            set_transient('bpi_import_session', [
                'extract_dir' => $extract_dir,
                'zip_path' => $zip_path,
                'products' => $products,
                'total' => count($products),
                'processed' => 0
            ], HOUR_IN_SECONDS);

            wp_send_json_success([
                'message' => 'ZIP extracted successfully',
                'total_products' => count($products),
                'excel_files' => count($excel_files)
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function process_batch() {
        check_ajax_referer('bpi_import_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $session = get_transient('bpi_import_session');
        if (!$session) {
            wp_send_json_error(['message' => 'Import session expired']);
        }

        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 25;
        $offset = $session['processed'];
        
        try {
            $results = $this->importer->process_batch($session['products'], $offset, $batch_size);
            
            $session['processed'] += $batch_size;
            set_transient('bpi_import_session', $session, HOUR_IN_SECONDS);

            $is_complete = $session['processed'] >= $session['total'];

            wp_send_json_success([
                'created' => $results['created'],
                'updated' => $results['updated'],
                'skipped' => $results['skipped'],
                'errors' => $results['errors'],
                'processed' => min($session['processed'], $session['total']),
                'total' => $session['total'],
                'complete' => $is_complete
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function cleanup() {
        check_ajax_referer('bpi_import_nonce', 'nonce');
        
        $session = get_transient('bpi_import_session');
        if ($session) {
            if (!empty($session['extract_dir'])) {
                $this->importer->cleanup($session['extract_dir']);
            }
            if (!empty($session['zip_path']) && file_exists($session['zip_path'])) {
                unlink($session['zip_path']);
            }
            delete_transient('bpi_import_session');
        }

        wp_send_json_success(['message' => 'Cleanup complete']);
    }
}

