<?php

/**
 * TỪ MÃ ĐƠN → ĐÚNG CỬA HÀNG → ĐÚNG PHIẾU
 * =============================================================================
 *
 * Mỗi shop là một site trong mạng, và MỖI SITE MỘT BẢNG local_ledger riêng. Nên
 * tra cứu bắt buộc phải hai bước: xác định shop trước, switch_to_blog(), rồi mới
 * đọc phiếu. Đọc thẳng ở site đang đứng là ra phiếu của shop khác hoặc không ra
 * gì cả.
 *
 * ── ĐỌC MÃ ĐƠN ─────────────────────────────────────────────────────────────
 *
 *     29001 AA 06404 [Z]
 *     └─┬─┘ └┬┘ └─┬─┘ └┬┘
 *       │    │    │    └── có Z = phiếu TÁCH hàng khuyến mãi của mã liền trước
 *       │    │    └─────── số chạy 5 chữ số
 *       │    └──────────── nhóm 2 chữ cái (AA→AB→…)
 *       └───────────────── mã shop = wp_blogs.tgs_site_code
 *
 * Suy ngược: cắt bỏ Z, cắt bỏ 2 chữ cái + 5 số ở đuôi, phần còn lại là mã shop.
 * Tra mã đó trong wp_blogs ra blog_id. Xem TGS_POS_Sale_Code bên tgs_pos — hai
 * bên phải đọc/ghi cùng một quy luật.
 *
 * Dạng cũ HD{blog_id}_{chuỗi} vẫn tra được: phiếu bán từ trước vẫn còn trong sổ,
 * và khách vẫn giữ những tờ bill đó.
 *
 * @package tra-cuu-hoa-don-dien-tu
 */

defined('ABSPATH') || exit;

class TGS_Invoice_Lookup_Resolver
{
    /** Tiền tố dùng cho site chưa khai tgs_site_code — trùng với TGS_POS_Sale_Code. */
    const FALLBACK_SITE_PREFIX = 'CNTEST';

    /** Đuôi của phiếu tách hàng khuyến mãi. */
    const SPLIT_SUFFIX = 'Z';

    /**
     * Chuẩn hoá mã khách nhập: bỏ khoảng trắng, viết hoa.
     *
     * Khách hay chép mã từ tin Zalo nên dính khoảng trắng đầu/cuối; và mã in
     * trên bill là chữ hoa.
     */
    public static function normalize_code($code)
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $code));
    }

    /** Mã này có phải phiếu tách (đuôi Z) không. */
    public static function is_split_code($code)
    {
        $code = self::normalize_code($code);

        return $code !== '' && substr($code, -1) === self::SPLIT_SUFFIX;
    }

    /** Mã phiếu cha của một mã tách: bỏ đúng chữ Z ở cuối. */
    public static function parent_code_of($code)
    {
        $code = self::normalize_code($code);

        return self::is_split_code($code) ? substr($code, 0, -1) : $code;
    }

    /**
     * Mã shop nằm ở đầu mã đơn. Trả về '' nếu mã không đúng dạng mới.
     */
    public static function site_prefix_of($code)
    {
        $code = self::parent_code_of(self::normalize_code($code));

        if (preg_match('/^([A-Z0-9]+?)[A-Z]{2}\d{5}$/', $code, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Các blog_id có thể chứa mã này, xếp theo độ chắc chắn giảm dần.
     *
     * Trả về MẢNG chứ không phải một số, vì có hai trường hợp không quyết định
     * được ngay:
     *
     *   - Mã mở đầu bằng CNTEST: đó là tiền tố dùng chung cho MỌI site chưa khai
     *     tgs_site_code, nên phải dò từng site cho tới khi tìm thấy phiếu.
     *   - Hai site lỡ khai trùng tgs_site_code (sai dữ liệu, nhưng có thật).
     *
     * @return int[]
     */
    public static function candidate_blog_ids($code)
    {
        global $wpdb;

        $code = self::normalize_code($code);
        if ($code === '') {
            return [];
        }

        // Dạng cũ HD{blog_id}_… — mã tự nói ra blog_id
        if (preg_match('/^HD(\d+)_[A-Z0-9]+$/', $code, $m)) {
            return [intval($m[1])];
        }

        $prefix = self::site_prefix_of($code);
        if ($prefix === '') {
            return [];
        }

        $blogs_table = $wpdb->base_prefix . 'blogs';

        if (!self::blogs_has_site_code_column()) {
            // Chưa có cột thì mọi site đều đang dùng tiền tố dự phòng
            return $prefix === self::FALLBACK_SITE_PREFIX ? self::all_blog_ids() : [];
        }

        $matched = $wpdb->get_col($wpdb->prepare(
            "SELECT blog_id FROM {$blogs_table}
             WHERE tgs_site_code = %s AND (deleted = 0 OR deleted IS NULL)
             ORDER BY blog_id ASC",
            $prefix
        ));
        $matched = array_map('intval', (array) $matched);

        /*
         * CNTEST là tiền tố của MỌI site chưa khai mã, nên phải dò tiếp các site
         * đó. Không dò thì shop chưa khai mã tra cứu là báo "không tìm thấy",
         * trong khi phiếu vẫn nằm nguyên trong sổ của họ.
         */
        if ($prefix === self::FALLBACK_SITE_PREFIX) {
            $unset_code = $wpdb->get_col(
                "SELECT blog_id FROM {$blogs_table}
                 WHERE (tgs_site_code IS NULL OR tgs_site_code = '')
                   AND (deleted = 0 OR deleted IS NULL)
                 ORDER BY blog_id ASC"
            );

            foreach ((array) $unset_code as $blog_id) {
                $blog_id = intval($blog_id);
                if ($blog_id > 0 && !in_array($blog_id, $matched, true)) {
                    $matched[] = $blog_id;
                }
            }
        }

        return $matched;
    }

    /**
     * Bảng blogs đã có cột mã shop chưa (site cũ chưa chạy migration).
     *
     * KHÔNG nhớ kết quả vào biến static: một lần tra chỉ gọi hàm này một hai
     * lần, tiết kiệm được đúng một câu SHOW COLUMNS, mà đổi lại là không test
     * được nhánh "chưa có cột" — lần chạy đầu đã chốt cứng câu trả lời cho cả
     * tiến trình.
     */
    private static function blogs_has_site_code_column()
    {
        global $wpdb;

        $table = $wpdb->base_prefix . 'blogs';

        return (bool) $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'tgs_site_code'");
    }

    /** @return int[] */
    private static function all_blog_ids()
    {
        global $wpdb;

        $blogs_table = $wpdb->base_prefix . 'blogs';
        $ids = $wpdb->get_col(
            "SELECT blog_id FROM {$blogs_table}
             WHERE (deleted = 0 OR deleted IS NULL)
             ORDER BY blog_id ASC"
        );

        return array_map('intval', (array) $ids);
    }
}
