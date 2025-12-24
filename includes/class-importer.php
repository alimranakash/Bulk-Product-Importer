<?php
defined('ABSPATH') || exit;

class BPI_Importer {
    private $excel_reader;
    private $results = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    private $parent_map = [];

    public function __construct() {
        $this->excel_reader = new BPI_Excel_Reader();
    }

    public function extract_zip($zip_path) {
        $extract_dir = BPI_UPLOAD_DIR . 'extracted_' . time() . '/';
        wp_mkdir_p($extract_dir);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        $result = unzip_file($zip_path, $extract_dir);

        if (is_wp_error($result)) {
            throw new Exception('Failed to extract ZIP: ' . $result->get_error_message());
        }

        return $extract_dir;
    }

    public function get_excel_files($directory) {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (strtolower($file->getExtension()) === 'xlsx' && strpos($file->getFilename(), '~$') !== 0) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function get_all_products($extract_dir) {
        $excel_files = $this->get_excel_files($extract_dir);
        $all_products = [];

        foreach ($excel_files as $file) {
            $products = $this->excel_reader->read_file($file);
            $all_products = array_merge($all_products, $products);
        }

        usort($all_products, function($a, $b) {
            $type_order = ['simple' => 0, 'variable' => 1, 'variation' => 2];
            $a_order = $type_order[strtolower($a['type'])] ?? 3;
            $b_order = $type_order[strtolower($b['type'])] ?? 3;
            return $a_order - $b_order;
        });

        return $all_products;
    }

    public function process_batch($products, $offset, $batch_size) {
        $batch = array_slice($products, $offset, $batch_size);
        $this->results = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($batch as $product_data) {
            try {
                $this->import_product($product_data);
            } catch (Exception $e) {
                $this->results['errors'][] = "SKU {$product_data['sku']}: {$e->getMessage()}";
                $this->results['skipped']++;
            }
        }

        return $this->results;
    }

    private function import_product($data) {
        $type = strtolower($data['type']);
        $sku = sanitize_text_field($data['sku']);
        
        if (empty($sku) && $type !== 'variation') {
            throw new Exception('SKU is required');
        }

        $existing_id = $this->get_product_by_sku($sku);
        
        switch ($type) {
            case 'variable':
                $product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Variable();
                $this->set_product_data($product, $data);
                $product_id = $product->save();
                $this->parent_map[$sku] = $product_id;
                break;

            case 'variation':
                $parent_sku = $data['parent'];
                $parent_id = $this->parent_map[$parent_sku] ?? $this->get_product_by_sku($parent_sku);
                if (!$parent_id) {
                    throw new Exception("Parent product not found: {$parent_sku}");
                }
                $product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Variation();
                $product->set_parent_id($parent_id);
                $this->set_variation_data($product, $data);
                $product->save();
                break;

            default:
                $product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Simple();
                $this->set_product_data($product, $data);
                $product->save();
        }

        $existing_id ? $this->results['updated']++ : $this->results['created']++;
    }

    private function get_product_by_sku($sku) {
        if (empty($sku)) return false;
        return wc_get_product_id_by_sku($sku);
    }

    private function set_product_data($product, $data) {
        $product->set_sku($data['sku']);
        $product->set_name(sanitize_text_field($data['name']));
        $product->set_status($data['published'] ? 'publish' : 'draft');
        $product->set_featured($data['featured']);
        $product->set_catalog_visibility($this->get_visibility($data['visibility']));
        $product->set_short_description(wp_kses_post($data['short_description']));
        $product->set_description(wp_kses_post($data['description']));

        if (!empty($data['regular_price'])) {
            $product->set_regular_price($data['regular_price']);
        }
        if (!empty($data['sale_price'])) {
            $product->set_sale_price($data['sale_price']);
        }

        $this->set_categories($product, $data['categories']);
        $this->set_images($product, $data['images']);
        $this->set_attributes($product, $data);
    }

    private function set_variation_data($variation, $data) {
        if (!empty($data['sku'])) {
            $variation->set_sku($data['sku']);
        }
        $variation->set_status($data['published'] ? 'publish' : 'private');

        if (!empty($data['regular_price'])) {
            $variation->set_regular_price($data['regular_price']);
        }
        if (!empty($data['sale_price'])) {
            $variation->set_sale_price($data['sale_price']);
        }

        $this->set_images($variation, $data['images']);

        $attributes = [];
        if (!empty($data['attribute_1_name']) && !empty($data['attribute_1_values'])) {
            $attr_name = strtolower(sanitize_title($data['attribute_1_name']));
            $attributes[$attr_name] = $data['attribute_1_values'];
        }
        if (!empty($data['attribute_2_name']) && !empty($data['attribute_2_values'])) {
            $attr_name = strtolower(sanitize_title($data['attribute_2_name']));
            $attributes[$attr_name] = $data['attribute_2_values'];
        }
        $variation->set_attributes($attributes);
    }

    private function get_visibility($visibility) {
        $valid = ['visible', 'catalog', 'search', 'hidden'];
        $visibility = strtolower($visibility);
        return in_array($visibility, $valid) ? $visibility : 'visible';
    }

    private function set_categories($product, $categories_str) {
        if (empty($categories_str)) return;

        $categories = array_map('trim', explode(',', $categories_str));
        $category_ids = [];

        foreach ($categories as $category_path) {
            $parts = array_map('trim', explode('>', $category_path));
            $parent_id = 0;

            foreach ($parts as $cat_name) {
                $term = term_exists($cat_name, 'product_cat', $parent_id);
                if (!$term) {
                    $term = wp_insert_term($cat_name, 'product_cat', ['parent' => $parent_id]);
                }
                if (!is_wp_error($term)) {
                    $parent_id = is_array($term) ? $term['term_id'] : $term;
                }
            }

            if ($parent_id) {
                $category_ids[] = (int) $parent_id;
            }
        }

        if (!empty($category_ids)) {
            $product->set_category_ids($category_ids);
        }
    }

    private function set_images($product, $images_str) {
        if (empty($images_str)) return;

        $image_urls = array_map('trim', explode(',', $images_str));
        $image_ids = [];

        foreach ($image_urls as $url) {
            if (empty($url)) continue;
            $attachment_id = $this->download_image($url);
            if ($attachment_id) {
                $image_ids[] = $attachment_id;
            }
        }

        if (!empty($image_ids)) {
            $product->set_image_id(array_shift($image_ids));
            if (!empty($image_ids) && method_exists($product, 'set_gallery_image_ids')) {
                $product->set_gallery_image_ids($image_ids);
            }
        }
    }

    private function download_image($url) {
        $existing = $this->get_attachment_by_url($url);
        if ($existing) return $existing;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $temp_file = download_url($url, 30);
        if (is_wp_error($temp_file)) return false;

        $file_array = [
            'name' => basename(parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $temp_file
        ];

        $attachment_id = media_handle_sideload($file_array, 0);
        if (is_wp_error($attachment_id)) {
            @unlink($temp_file);
            return false;
        }

        update_post_meta($attachment_id, '_bpi_source_url', $url);
        return $attachment_id;
    }

    private function get_attachment_by_url($url) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bpi_source_url' AND meta_value = %s LIMIT 1",
            $url
        ));
    }

    private function set_attributes($product, $data) {
        $attributes = [];

        for ($i = 1; $i <= 2; $i++) {
            $name_key = "attribute_{$i}_name";
            $values_key = "attribute_{$i}_values";
            $visible_key = "attribute_{$i}_visible";
            $global_key = "attribute_{$i}_global";

            if (empty($data[$name_key])) continue;

            $attr_name = sanitize_text_field($data[$name_key]);
            $attr_values = array_map('trim', explode('|', $data[$values_key]));
            $is_visible = $data[$visible_key];
            $is_global = $data[$global_key];

            if ($is_global) {
                $taxonomy = wc_attribute_taxonomy_name($attr_name);
                $this->ensure_attribute_taxonomy($attr_name, $taxonomy);
                $this->ensure_attribute_terms($taxonomy, $attr_values);

                $attribute = new WC_Product_Attribute();
                $attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
                $attribute->set_name($taxonomy);
                $attribute->set_options($attr_values);
                $attribute->set_visible($is_visible);
                $attribute->set_variation(strtolower($data['type']) === 'variable');
            } else {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name($attr_name);
                $attribute->set_options($attr_values);
                $attribute->set_visible($is_visible);
                $attribute->set_variation(strtolower($data['type']) === 'variable');
            }

            $attributes[] = $attribute;
        }

        if (!empty($attributes)) {
            $product->set_attributes($attributes);
        }
    }

    private function ensure_attribute_taxonomy($name, $taxonomy) {
        if (taxonomy_exists($taxonomy)) return;

        wc_create_attribute([
            'name' => $name,
            'slug' => sanitize_title($name),
            'type' => 'select',
            'order_by' => 'menu_order'
        ]);

        register_taxonomy($taxonomy, ['product'], [
            'hierarchical' => false,
            'labels' => ['name' => $name],
            'show_ui' => false
        ]);
    }

    private function ensure_attribute_terms($taxonomy, $terms) {
        foreach ($terms as $term) {
            if (!term_exists($term, $taxonomy)) {
                wp_insert_term($term, $taxonomy);
            }
        }
    }

    public function cleanup($directory) {
        if (!is_dir($directory)) return;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($directory);
    }
}
