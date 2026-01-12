<?php
/**
 * Plugin Name: Bulk Product Importer
 * Description: Import WooCommerce products in bulk from ZIP files containing Excel spreadsheets
 * Version:     1.0.0
 * Plugin URI:  https://pluxify.co/bulk-product-importer/
 * Author:      Al Imran Akash
 * Author URI:  https://profiles.wordpress.org/al-imran-akash/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * Text Domain: bulk-product-importer
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

// Load Composer autoloader
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Freemius SDK Integration
if ( ! function_exists( 'bpi_fs' ) ) {
    // Create a helper function for easy SDK access.
    function bpi_fs() {
        global $bpi_fs;

        if ( ! isset( $bpi_fs ) ) {
            // Include Freemius SDK.
            // SDK is auto-loaded through Composer

            $bpi_fs = fs_dynamic_init( array(
                'id'                  => '22874',
                'slug'                => 'bulk-product-importer',
                'type'                => 'plugin',
                'public_key'          => 'pk_a41ca9fd3d9fbb5ef876fd9daa190',
                'is_premium'          => true,
                'is_premium_only'     => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                // Automatically removed in the free version. If you're not using the
                // auto-generated free version, delete this line before uploading to wp.org.
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'trial'               => array(
                    'days'               => 7,
                    'is_require_payment' => true,
                ),
                'menu'                => array(
                    'slug'           => 'bulk-product-importer',
                    'support'        => false,
                ),
            ) );
        }

        return $bpi_fs;
    }

    // Init Freemius.
    bpi_fs();
    // Signal that SDK was initiated.
    do_action( 'bpi_fs_loaded' );
}

define('BPI_VERSION', '1.0.0');
define('BPI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BPI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BPI_UPLOAD_DIR', wp_upload_dir()['basedir'] . '/bulk-product-importer/');

final class Bulk_Product_Importer {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->check_dependencies();
        $this->includes();
        $this->init_hooks();
    }

    private function check_dependencies() {
        add_action('admin_init', function() {
            $errors = [];

            if (!class_exists('WooCommerce')) {
                $errors[] = 'WooCommerce must be installed and active.';
            }

            if (!class_exists('ZipArchive')) {
                $errors[] = 'PHP ZIP extension is required. Enable it in php.ini by uncommenting <code>extension=zip</code> and restart Apache.';
            }

            if (!empty($errors)) {
                add_action('admin_notices', function() use ($errors) {
                    echo '<div class="error"><p><strong>Bulk Product Importer</strong> - Missing requirements:</p><ul>';
                    foreach ($errors as $error) {
                        echo '<li>' . $error . '</li>';
                    }
                    echo '</ul></div>';
                });
            }
        });
    }

    private function includes() {
        require_once BPI_PLUGIN_DIR . 'includes/class-admin.php';
        require_once BPI_PLUGIN_DIR . 'includes/class-excel-reader.php';
        require_once BPI_PLUGIN_DIR . 'includes/class-importer.php';
        require_once BPI_PLUGIN_DIR . 'includes/class-scheduler.php';
        require_once BPI_PLUGIN_DIR . 'includes/class-ajax-handler.php';
    }

    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function init() {
        if (class_exists('WooCommerce')) {
            new BPI_Admin();
            new BPI_Scheduler();
            new BPI_Ajax_Handler();
        }
    }

    public function activate() {
        if (!file_exists(BPI_UPLOAD_DIR)) {
            wp_mkdir_p(BPI_UPLOAD_DIR);
        }
        file_put_contents(BPI_UPLOAD_DIR . '.htaccess', 'deny from all');
        file_put_contents(BPI_UPLOAD_DIR . 'index.php', '<?php // Silence is golden');
    }

    public function deactivate() {
        $this->delete_directory(BPI_UPLOAD_DIR);
    }

    private function delete_directory($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->delete_directory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

function BPI() {
    return Bulk_Product_Importer::instance();
}

BPI();
