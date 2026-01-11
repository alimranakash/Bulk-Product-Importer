<?php
defined('ABSPATH') || exit;

class BPI_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu_page() {
        add_menu_page(
            'Bulk Product Importer',           // Page title
            'Bulk Importer',                   // Menu title
            'manage_woocommerce',              // Capability
            'bulk-product-importer',           // Menu slug
            [$this, 'render_page'],            // Callback function
            'dashicons-database-import',       // Icon (database import dashicon)
            56                                 // Position (after WooCommerce which is 55)
        );
    }

    public function enqueue_assets($hook) {
        if ('toplevel_page_bulk-product-importer' !== $hook) return;
        
        wp_enqueue_style('bpi-admin', BPI_PLUGIN_URL . 'assets/css/admin.css', [], BPI_VERSION);
        wp_enqueue_script('bpi-admin', BPI_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], BPI_VERSION, true);
        wp_localize_script('bpi-admin', 'bpiData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bpi_import_nonce'),
            'uploadDir' => BPI_UPLOAD_DIR
        ]);
    }

    public function render_page() {
        ?>
        <div class="bpi-wrap">
            <div class="bpi-container">
                <div class="bpi-header">
                    <h1 class="bpi-title">Bulk Product Importer</h1>
                    <p class="bpi-subtitle">Import WooCommerce products from ZIP or Excel files</p>
                </div>

                <div class="bpi-card">
                    <div class="bpi-card-header">
                        <h2>Upload File</h2>
                    </div>
                    <div class="bpi-card-body">
                        <form id="bpi-upload-form" enctype="multipart/form-data">

                            <div class="bpi-upload-type">
                                <label class="bpi-radio-label">
                                    <input type="radio" name="upload_type" value="zip" id="bpi-type-zip" checked>
                                    <span class="bpi-radio-box"></span>
                                    <span class="bpi-radio-content">
                                        <span class="bpi-radio-title">📦 ZIP File</span>
                                        <span class="bpi-radio-desc">Excel files + images in a ZIP archive</span>
                                    </span>
                                </label>
                                <label class="bpi-radio-label">
                                    <input type="radio" name="upload_type" value="excel" id="bpi-type-excel">
                                    <span class="bpi-radio-box"></span>
                                    <span class="bpi-radio-content">
                                        <span class="bpi-radio-title">📊 Excel File</span>
                                        <span class="bpi-radio-desc">Single Excel file (no images)</span>
                                    </span>
                                </label>
                            </div>

                            <div class="bpi-upload-area" id="bpi-drop-zone">
                                <div class="bpi-upload-icon" id="bpi-upload-icon">📦</div>
                                <p class="bpi-upload-text" id="bpi-upload-text">Drag and drop your ZIP file here</p>
                                <p class="bpi-upload-subtext">or click to browse</p>
                                <input type="file" id="bpi-file-input" name="import_file" accept=".zip" class="bpi-file-input">
                            </div>
                            <div id="bpi-file-info" class="bpi-file-info hidden"></div>

                            <div class="bpi-options">
                                <label class="bpi-label">
                                    Batch Size:
                                    <select id="bpi-batch-size" class="bpi-select">
                                        <option value="10">10 products</option>
                                        <option value="25" selected>25 products</option>
                                        <option value="50">50 products</option>
                                        <option value="100">100 products</option>
                                    </select>
                                </label>
                                <p class="bpi-help-text">Uses WooCommerce Action Scheduler for reliable background processing. Each Excel file is processed separately.</p>
                            </div>

                            <div class="bpi-btn-group">
                                <button type="submit" id="bpi-submit-btn" class="bpi-btn bpi-btn-primary" disabled>
                                    Start Import
                                </button>
                                <button type="button" id="bpi-cancel-btn" class="bpi-btn bpi-btn-danger hidden">
                                    Cancel Import
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="bpi-progress-section" class="bpi-card hidden">
                    <div class="bpi-card-header">
                        <h2>Import Progress</h2>
                        <span class="bpi-badge">Background Processing</span>
                    </div>
                    <div class="bpi-card-body">
                        <div class="bpi-progress-bar">
                            <div id="bpi-progress-fill" class="bpi-progress-fill"></div>
                        </div>
                        <div class="bpi-progress-stats">
                            <span id="bpi-progress-text">0%</span>
                            <span id="bpi-progress-count">0 / 0 products</span>
                        </div>
                        <div id="bpi-current-action" class="bpi-current-action"></div>
                        <p class="bpi-async-note">Import runs in the background. You can close this page and check back later.</p>
                    </div>
                </div>

                <div id="bpi-results-section" class="bpi-card hidden">
                    <div class="bpi-card-header">
                        <h2>Import Results</h2>
                    </div>
                    <div class="bpi-card-body">
                        <div id="bpi-results-summary" class="bpi-results-summary"></div>
                        <div id="bpi-results-log" class="bpi-results-log"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

