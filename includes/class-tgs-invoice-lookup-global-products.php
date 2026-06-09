<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hydrate sản phẩm global cho trang tra cứu hóa đơn điện tử.
 *
 * Dòng hóa đơn vẫn đọc từ local_ledger_item của shop vì đó là chứng từ bán hàng.
 * Catalog sản phẩm tuyệt đối không đọc bảng local_product_name; helper này chỉ gọi
 * TGS_Global_Product_Source của tgs_shop_management, cùng chuẩn với REST API global.
 */
class TGS_Invoice_Lookup_Global_Products
{
    private static function ensure_global_constants()
    {
        global $wpdb;

        if (!defined('TGS_TABLE_GLOBAL_PRODUCT_NAME')) {
            define('TGS_TABLE_GLOBAL_PRODUCT_NAME', $wpdb->base_prefix . 'global_product_name');
        }

        if (!defined('TGS_TABLE_GLOBAL_PRODUCT_LOTS')) {
            define('TGS_TABLE_GLOBAL_PRODUCT_LOTS', $wpdb->base_prefix . 'global_product_lots');
        }
    }

    private static function ensure_source()
    {
        self::ensure_global_constants();

        if (class_exists('TGS_Global_Product_Source')) {
            return true;
        }

        $plugin_root = defined('WP_PLUGIN_DIR')
            ? WP_PLUGIN_DIR
            : dirname(__DIR__, 2);

        $candidates = [
            trailingslashit($plugin_root) . 'tgs_shop_management/functions/class-tgs-global-product-source.php',
            trailingslashit(dirname(__DIR__, 2)) . 'tgs_shop_management/functions/class-tgs-global-product-source.php',
        ];

        foreach ($candidates as $file) {
            if (is_readable($file)) {
                require_once $file;
                break;
            }
        }

        return class_exists('TGS_Global_Product_Source');
    }

    public static function is_available()
    {
        return self::ensure_source();
    }

    public static function global_lot_table()
    {
        self::ensure_global_constants();

        return TGS_TABLE_GLOBAL_PRODUCT_LOTS;
    }

    public static function enrich_ledger_items(array $rows, $blog_id = 0)
    {
        $ids = [];
        $skus = [];

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (array) $row;

            $product_id = self::row_product_id($row);
            if ($product_id > 0) {
                $ids[] = $product_id;
            }

            $sku = self::row_sku($row);
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        $products_by_id = [];
        foreach (self::get_products_by_ids($ids, $blog_id) as $product) {
            $id = (int) ($product['global_product_name_id'] ?? 0);
            if ($id > 0) {
                $products_by_id[$id] = $product;
            }
        }

        $products_by_sku = [];
        foreach (self::get_products_by_skus($skus, $blog_id) as $product) {
            $sku = trim((string) ($product['global_product_sku'] ?? ''));
            if ($sku !== '') {
                $products_by_sku[$sku] = $product;
            }
        }

        $enriched = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $meta = self::row_meta($row);

            $product_id = self::row_product_id($row);
            $row_sku = self::row_sku($row);

            // Ưu tiên SKU snapshot của ledger vì đây là khóa tồn/vận hành giữa các plugin.
            $product = ($row_sku !== '' && isset($products_by_sku[$row_sku]))
                ? $products_by_sku[$row_sku]
                : null;

            if (!$product && $product_id > 0 && isset($products_by_id[$product_id])) {
                $product = $products_by_id[$product_id];
            }

            $global_id = $product ? (int) ($product['global_product_name_id'] ?? 0) : $product_id;
            $sku = $row_sku !== ''
                ? $row_sku
                : (string) ($product['global_product_sku'] ?? ($meta['sku'] ?? ''));
            $name = (string) ($product['global_product_name'] ?? '');
            $unit = (string) ($product['global_product_unit'] ?? ($meta['unit'] ?? ''));
            $barcode = (string) ($product['global_product_barcode_main'] ?? '');
            $tax = $product['global_product_tax'] ?? ($row['local_ledger_item_tax_percent'] ?? 0);
            $price = $product['global_product_price'] ?? 0;
            $price_after_tax = $product['global_product_price_after_tax'] ?? 0;

            $row['global_product_name_id'] = $global_id;
            $row['global_product_sku'] = $sku;
            $row['global_product_name'] = $name;
            $row['global_product_unit'] = $unit;
            $row['global_product_barcode_main'] = $barcode;
            $row['global_product_tax'] = $tax;
            $row['global_product_price'] = $price;
            $row['global_product_price_after_tax'] = $price_after_tax;

            // Alias legacy để template POS cũ dùng tiếp, dữ liệu gốc vẫn từ global product.
            $row['local_product_name_id'] = $global_id;
            $row['local_product_sku'] = $sku;
            $row['local_product_name'] = $name;
            $row['local_product_unit'] = $unit;
            $row['local_product_barcode_main'] = $barcode;
            $row['local_product_tax'] = $tax;
            $row['local_product_price'] = $price;
            $row['local_product_price_after_tax'] = $price_after_tax;

            $row['product_name'] = $name;
            $row['barcode_main'] = $barcode;
            $row['tax'] = $tax;

            $enriched[] = $row;
        }

        return $enriched;
    }

    private static function get_products_by_ids(array $ids, $blog_id = 0)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids) || !self::ensure_source()) {
            return [];
        }

        $result = TGS_Global_Product_Source::query_products([
            'ids' => $ids,
            'per_page' => count($ids),
            'parent_only' => false,
            'with_local_aliases' => true,
            'status_filter' => 'all',
            'blog_id' => (int) $blog_id > 0 ? (int) $blog_id : null,
        ]);

        return is_array($result['items'] ?? null) ? $result['items'] : [];
    }

    private static function get_products_by_skus(array $skus, $blog_id = 0)
    {
        $skus = array_values(array_unique(array_filter(array_map(static function ($sku) {
            return trim((string) $sku);
        }, $skus))));

        if (empty($skus) || !self::ensure_source()) {
            return [];
        }

        $result = TGS_Global_Product_Source::query_products([
            'skus' => $skus,
            'per_page' => count($skus),
            'parent_only' => false,
            'with_local_aliases' => true,
            'status_filter' => 'all',
            'blog_id' => (int) $blog_id > 0 ? (int) $blog_id : null,
        ]);

        return is_array($result['items'] ?? null) ? $result['items'] : [];
    }

    public static function row_product_id(array $row)
    {
        $global_id = (int) ($row['global_product_name_id'] ?? 0);
        if ($global_id > 0) {
            return $global_id;
        }

        return (int) ($row['local_product_name_id'] ?? 0);
    }

    public static function row_sku(array $row)
    {
        foreach (['global_product_sku', 'local_product_sku', 'sku'] as $key) {
            $sku = trim((string) ($row[$key] ?? ''));
            if ($sku !== '') {
                return $sku;
            }
        }

        $meta = self::row_meta($row);
        return trim((string) ($meta['sku'] ?? ''));
    }

    private static function row_meta(array $row)
    {
        if (empty($row['local_ledger_item_meta'])) {
            return [];
        }

        $decoded = json_decode((string) $row['local_ledger_item_meta'], true);
        return is_array($decoded) ? $decoded : [];
    }
}
