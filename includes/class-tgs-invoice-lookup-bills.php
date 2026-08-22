<?php

/**
 * LẤY DỮ LIỆU BILL — DÙNG LẠI ĐÚNG HÀM CỦA POS
 * =============================================================================
 *
 * Không tự dựng lại phép tính tiền ở đây. Toàn bộ số liệu lấy từ
 * TGS_POS_Ajax_Order::get_order_receipt_data() — đúng cái hàm màn Đơn hàng của
 * POS dùng để in lại bill.
 *
 * Vì sao phải dùng chung: bill khách cầm ở quầy và bill khách tự tra trên mạng
 * phải ra CÙNG một con số, cùng đơn vị tính, cùng cách làm tròn từng dòng. Chép
 * công thức sang đây là sớm muộn hai bên lệch nhau, mà lúc đó không ai biết bên
 * nào đúng. Luật tiền xem:
 *   - tgs_shop_management/docs/mo-hinh-tien-va-bang-local-ledger-item.md
 *   - tgs_pos/docs/GIA_VA_DON_VI_TINH.md
 *
 * ── PHIẾU CHA VÀ PHIẾU TÁCH (MÃ Z) ─────────────────────────────────────────
 *
 * Đơn có hàng khuyến mãi được tách làm hai phiếu: phiếu chính, và phiếu tách
 * mang mã cha + "Z". Trang tra cứu LUÔN hiện cả hai, dù khách nhập mã nào —
 * khách cầm một tờ bill có hai phần thì tra ra cũng phải thấy đủ hai phần.
 *
 * @package tra-cuu-hoa-don-dien-tu
 */

defined('ABSPATH') || exit;

class TGS_Invoice_Lookup_Bills
{
    /**
     * Tra một mã đơn ra danh sách bill để in.
     *
     * @param string $code    Mã đơn khách nhập.
     * @param int    $blog_id Ép tra ở đúng site này (0 = tự suy từ mã).
     * @return array {success, message, blog_id, store, bills[]}
     */
    public static function lookup($code, $blog_id = 0)
    {
        $code = TGS_Invoice_Lookup_Resolver::normalize_code($code);

        if ($code === '') {
            return self::fail('Vui lòng nhập mã đơn để tra cứu.');
        }

        if (!self::ensure_pos_loaded()) {
            return self::fail('Hệ thống bán hàng chưa sẵn sàng để tra cứu. Vui lòng thử lại sau.');
        }

        $candidates = $blog_id > 0
            ? [intval($blog_id)]
            : TGS_Invoice_Lookup_Resolver::candidate_blog_ids($code);

        if (empty($candidates)) {
            return self::fail('Không xác định được cửa hàng từ mã đơn. Vui lòng kiểm tra lại mã in trên hóa đơn.');
        }

        /*
         * Dò lần lượt từng site ứng viên. Gần như luôn trúng ngay site đầu; chỉ
         * mã CNTEST (site chưa khai mã shop) mới phải dò tiếp.
         */
        foreach ($candidates as $candidate) {
            $found = self::lookup_in_blog($candidate, $code);
            if (!empty($found['success'])) {
                return $found;
            }
        }

        return self::fail('Không tìm thấy hóa đơn với mã đã nhập.');
    }

    /**
     * Đảm bảo có lớp dựng bill của POS.
     *
     * Trang tra cứu chạy trên site quản trị, còn tgs_pos thì có thể chỉ bật ở
     * các site shop — lúc đó lớp TGS_POS_Ajax_Order không được nạp và mọi lần
     * tra đều báo lỗi. Nên nạp bù đúng mấy file cần, theo THỨ TỰ như tgs_pos tự
     * nạp (xem Tmdpos_Init::tmdpos_load_file).
     *
     * Chỉ nạp file, KHÔNG nạp bizgpt-pos.php: file đó đăng ký hàng loạt hook, mà
     * hook của POS thì không việc gì phải chạy trên trang tra cứu công khai.
     */
    private static function ensure_pos_loaded()
    {
        if (class_exists('TGS_POS_Ajax_Order')
            && method_exists('TGS_POS_Ajax_Order', 'get_order_receipt_data')) {
            return true;
        }

        $pos_path = defined('TMDPOS_PLUGIN_PATH')
            ? TMDPOS_PLUGIN_PATH
            : (defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/tgs_pos/' : '');

        if ($pos_path === '' || !is_dir($pos_path)) {
            return false;
        }

        if (!defined('TMDPOS_PLUGIN_PATH')) {
            define('TMDPOS_PLUGIN_PATH', $pos_path);
        }
        if (!defined('TMDPOS_PLUGIN_URL')) {
            define('TMDPOS_PLUGIN_URL', plugins_url('/', $pos_path . 'bizgpt-pos.php'));
        }

        $files = [
            'functions/class-tgs-pos-price-list.php',
            'functions/class-tgs-pos-global-products.php',
            'functions/class-tgs-pos-money.php',
            'functions/ajax/class-tgs-pos-ajax-order.php',
        ];

        foreach ($files as $file) {
            $full = $pos_path . $file;
            if (file_exists($full)) {
                include_once $full;
            }
        }

        return class_exists('TGS_POS_Ajax_Order')
            && method_exists('TGS_POS_Ajax_Order', 'get_order_receipt_data');
    }

    /** Tra trong đúng một site. */
    private static function lookup_in_blog($blog_id, $code)
    {
        $blog_id = intval($blog_id);
        if ($blog_id <= 0) {
            return self::fail('Thiếu thông tin cửa hàng.');
        }

        $switched = false;
        if (get_current_blog_id() !== $blog_id) {
            switch_to_blog($blog_id);
            $switched = true;
        }

        try {
            $order_id = self::find_ledger_id_by_code($code);
            if ($order_id <= 0) {
                return self::fail('Không tìm thấy hóa đơn với mã đã nhập.');
            }

            $data = TGS_POS_Ajax_Order::get_order_receipt_data($order_id);
            if (is_wp_error($data)) {
                return self::fail($data->get_error_message());
            }

            /*
             * Khách nhập mã phiếu tách (đuôi Z) thì leo ngược lên phiếu cha rồi
             * dựng lại từ đó — để thứ tự in ra luôn là CHA TRƯỚC, TÁCH SAU, bất
             * kể khách cầm tờ nào trong tay.
             */
            if (!empty($data['parent_sale']['order_id'])) {
                $parent_id = intval($data['parent_sale']['order_id']);
                $parent_data = TGS_POS_Ajax_Order::get_order_receipt_data($parent_id);
                if (!is_wp_error($parent_data)) {
                    $data = $parent_data;
                }
            }

            $bills = [[
                'role' => 'parent',
                'label' => 'Phiếu bán hàng',
                'order' => $data,
            ]];

            // Phiếu tách hàng khuyến mãi — in ngay dưới phiếu cha, cùng một trang
            if (!empty($data['promo_split']['order_id'])) {
                $split_id = intval($data['promo_split']['order_id']);
                $split_data = TGS_POS_Ajax_Order::get_order_receipt_data($split_id);

                if (!is_wp_error($split_data)) {
                    $bills[] = [
                        'role' => 'split',
                        'label' => 'Phiếu tách hàng khuyến mãi',
                        'order' => $split_data,
                    ];
                }
            }

            return [
                'success' => true,
                'message' => '',
                'blog_id' => $blog_id,
                'store' => self::store_config(),
                'bills' => $bills,
            ];
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    /**
     * Tìm local_ledger_id từ mã đơn, trong site ĐANG đứng.
     *
     * Ưu tiên phiếu bán (SALE_ORDER): cùng một mã còn có phiếu xuất kho ngầm đi
     * kèm, mà bill phải dựng từ phiếu bán.
     */
    private static function find_ledger_id_by_code($code)
    {
        global $wpdb;

        $ledger_table = $wpdb->prefix . 'local_ledger';

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger_table));
        if ($exists !== $ledger_table) {
            return 0;
        }

        $type_sale_order = defined('TGS_LEDGER_TYPE_SALE_ORDER') ? intval(TGS_LEDGER_TYPE_SALE_ORDER) : 10;

        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT local_ledger_id FROM {$ledger_table}
             WHERE local_ledger_code = %s AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY (local_ledger_type = %d) DESC, local_ledger_id DESC
             LIMIT 1",
            $code,
            $type_sale_order
        )));
    }

    /**
     * Thông tin cửa hàng in ở đầu bill — đọc từ site ĐANG đứng (đã switch).
     *
     * Cùng bộ khoá option với màn bán hàng, nên bill tra cứu in ra đúng tên,
     * SĐT, giờ mở cửa của chính shop đã bán.
     */
    private static function store_config()
    {
        return [
            'name' => get_bloginfo('name'),
            'logo' => (string) get_option('tgs_shop_logo', ''),
            'defaultLogo' => content_url('plugins/tgs_pos/assets/images/logo.webp'),
            'address' => (string) get_option('tgs_shop_address', ''),
            'phone' => (string) get_option('tgs_shop_phone', ''),
            'website' => (string) get_option('tgs_shop_website', ''),
            'hours' => (string) get_option('tgs_shop_hours', ''),
        ];
    }

    private static function fail($message)
    {
        return [
            'success' => false,
            'message' => (string) $message,
            'blog_id' => 0,
            'store' => null,
            'bills' => [],
        ];
    }
}
