<?php
defined('ABSPATH') || exit;

// This entire code block will be removed from the free version.
if ( ! function_exists( 'bpi_fs' ) || ! bpi_fs()->can_use_premium_code__premium_only() ) {
    return;
}

require_once BPI_PLUGIN_DIR . 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Memory-efficient chunk reader for large Excel files
 */
class BPI_Chunk_Read_Filter implements IReadFilter {
    private $start_row;
    private $end_row;

    public function __construct($start_row = 1, $chunk_size = 1000) {
        $this->start_row = $start_row;
        $this->end_row = $start_row + $chunk_size - 1;
    }

    public function readCell($columnAddress, $row, $worksheetName = '') {
        // Always read row 1 (headers) and the chunk range
        return $row === 1 || ($row >= $this->start_row && $row <= $this->end_row);
    }
}

class BPI_Excel_Reader {
    private $column_map = [
        'Type' => 'type',
        'SKU' => 'sku',
        'Name' => 'name',
        'Published' => 'published',
        'Is featured?' => 'featured',
        'Visibility in catalog' => 'visibility',
        'Short description' => 'short_description',
        'Description' => 'description',
        'Regular price' => 'regular_price',
        'Sale price' => 'sale_price',
        'Categories' => 'categories',
        'Images' => 'images',
        'Attribute 1 name' => 'attribute_1_name',
        'Attribute 1 value(s)' => 'attribute_1_values',
        'Attribute 1 visible' => 'attribute_1_visible',
        'Attribute 1 global' => 'attribute_1_global',
        'Attribute 2 name' => 'attribute_2_name',
        'Attribute 2 value(s)' => 'attribute_2_values',
        'Attribute 2 visible' => 'attribute_2_visible',
        'Attribute 2 global' => 'attribute_2_global',
        'Parent' => 'parent'
    ];

    /**
     * Read file in chunks to prevent memory issues with large files
     */
    public function read_file($file_path, $chunk_size = 2000) {
        if (!file_exists($file_path)) {
            throw new Exception("File not found: {$file_path}");
        }

        $products = [];
        $reader = IOFactory::createReaderForFile($file_path);

        // First, get total row count efficiently
        $reader->setReadDataOnly(true);
        $info = $reader->listWorksheetInfo($file_path);
        $total_rows = $info[0]['totalRows'] ?? 0;

        if ($total_rows <= 1) {
            return [];
        }

        // Read headers first
        $filter = new BPI_Chunk_Read_Filter(1, 1);
        $reader->setReadFilter($filter);
        $spreadsheet = $reader->load($file_path);
        $worksheet = $spreadsheet->getActiveSheet();
        $headers = $worksheet->toArray()[0] ?? [];
        $header_keys = $this->map_headers($headers);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        // Process in chunks starting from row 2
        $start_row = 2;
        while ($start_row <= $total_rows) {
            // Clear memory between chunks
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $filter = new BPI_Chunk_Read_Filter($start_row, $chunk_size);
            $reader = IOFactory::createReaderForFile($file_path);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter($filter);

            $spreadsheet = $reader->load($file_path);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // Skip header row in result (always included by filter)
            array_shift($rows);

            foreach ($rows as $row) {
                if ($this->is_empty_row($row)) continue;

                $product = [];
                foreach ($row as $index => $value) {
                    if (isset($header_keys[$index])) {
                        $product[$header_keys[$index]] = trim($value ?? '');
                    }
                }

                if (!empty($product['sku']) || !empty($product['name'])) {
                    $products[] = $this->normalize_product($product);
                }
            }

            // Clean up to free memory
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $worksheet, $rows);

            $start_row += $chunk_size;
        }

        return $products;
    }

    /**
     * Legacy method for backwards compatibility - reads entire file at once
     */
    public function read_file_full($file_path) {
        if (!file_exists($file_path)) {
            throw new Exception("File not found: {$file_path}");
        }

        $spreadsheet = IOFactory::load($file_path);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (empty($rows)) {
            return [];
        }

        $headers = array_shift($rows);
        $header_keys = $this->map_headers($headers);
        $products = [];

        foreach ($rows as $row) {
            if ($this->is_empty_row($row)) continue;

            $product = [];
            foreach ($row as $index => $value) {
                if (isset($header_keys[$index])) {
                    $product[$header_keys[$index]] = trim($value ?? '');
                }
            }

            if (!empty($product['sku']) || !empty($product['name'])) {
                $products[] = $this->normalize_product($product);
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $products;
    }

    private function map_headers($headers) {
        $mapped = [];
        foreach ($headers as $index => $header) {
            $header = trim($header ?? '');
            if (isset($this->column_map[$header])) {
                $mapped[$index] = $this->column_map[$header];
            }
        }
        return $mapped;
    }

    private function is_empty_row($row) {
        foreach ($row as $cell) {
            if (!empty(trim($cell ?? ''))) return false;
        }
        return true;
    }

    private function normalize_product($product) {
        $defaults = [
            'type' => 'simple',
            'sku' => '',
            'name' => '',
            'published' => '1',
            'featured' => '0',
            'visibility' => 'visible',
            'short_description' => '',
            'description' => '',
            'regular_price' => '',
            'sale_price' => '',
            'categories' => '',
            'images' => '',
            'attribute_1_name' => '',
            'attribute_1_values' => '',
            'attribute_1_visible' => '1',
            'attribute_1_global' => '1',
            'attribute_2_name' => '',
            'attribute_2_values' => '',
            'attribute_2_visible' => '1',
            'attribute_2_global' => '1',
            'parent' => ''
        ];

        $product = array_merge($defaults, $product);
        
        $product['published'] = $this->to_bool($product['published']);
        $product['featured'] = $this->to_bool($product['featured']);
        $product['attribute_1_visible'] = $this->to_bool($product['attribute_1_visible']);
        $product['attribute_1_global'] = $this->to_bool($product['attribute_1_global']);
        $product['attribute_2_visible'] = $this->to_bool($product['attribute_2_visible']);
        $product['attribute_2_global'] = $this->to_bool($product['attribute_2_global']);
        
        return $product;
    }

    private function to_bool($value) {
        if (is_bool($value)) return $value;
        $value = strtolower(trim($value));
        return in_array($value, ['1', 'yes', 'true', 'on'], true);
    }
}

