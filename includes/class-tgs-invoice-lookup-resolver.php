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
 * DẠNG ĐANG DÙNG (từ 09/2026) — cắt tại mốc là ra mã shop:
 *
 *     2001 AA 000641      phiếu bán   (mốc "AA")
 *     2001 Z  000641 Z    bill Z      (mốc "Z" + đuôi "Z")
 *     02011 . A 00107     phiếu hoàn  (dấu "." + mốc "A")
 *     02011 . Z 0107 Z    hoàn bill Z (dấu "." + "Z" + đuôi "Z")
 *
 * DẠNG CŨ vẫn phải tra được — khách còn giữ những tờ bill đó:
 *
 *     26003 B 01 K 23 [Z]    phiếu bán cũ  (mốc B + chữ chen)
 *     26003 . B 0 M 12 [Z]   phiếu hoàn cũ (dấu "." + mốc B)
 *     29001 AA 06404 [Z]     nhóm 2 chữ cái + 5 số
 *     HD{blog_id}_{chuỗi}    mã tự nói ra blog_id
 *
 * Tra mã shop trong wp_blogs ra blog_id. Xem TGS_POS_Sale_Code bên tgs_pos —
 * hai bên phải đọc/ghi cùng một quy luật; bên đó đổi dạng mã là phải sửa cả
 * đây, nếu không quét QR trên bill Zalo sẽ không ra shop nào.
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
     * Chữ mốc ngăn giữa mã shop và phần số — trùng với
     * TGS_POS_Sale_Code::MARKER_LETTER. Hai bên phải giống nhau tuyệt đối.
     */
    const MARKER_LETTER = 'B';

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
     * Mã shop nằm ở đầu mã đơn. Trả về '' nếu mã không đọc được dạng nào.
     *
     * Thứ tự dò: phiếu hoàn (có dấu chấm) → phiếu bán dạng đang dùng (chữ mốc
     * B) → dạng cũ (2 chữ cái + 5 số). Mã shop toàn số hoặc CNTEST nên không
     * chứa chữ B, vì thế cắt tại chữ B đầu tiên là đúng mã shop.
     */
    public static function site_prefix_of($code)
    {
        $code = self::parent_code_of(self::normalize_code($code));

        // Phiếu bán DẠNG ĐANG DÙNG: {mã shop}AA{số}
        if (preg_match('/^([A-Z0-9]+?)AA\d+$/', $code, $m)) {
            return $m[1];
        }

        // Bill Z dạng đang dùng, sau khi parent_code_of() đã bỏ 1 "Z" cuối:
        // {mã shop}Z{số}
        if (preg_match('/^([A-Z0-9]+?)Z\d+$/', $code, $m)) {
            return $m[1];
        }

        // Phiếu hoàn DẠNG ĐANG DÙNG: {mã shop}.A{số}
        if (preg_match('/^([A-Z0-9]+?)\.A\d+$/', $code, $m)) {
            return $m[1];
        }

        // Hoàn của bill Z dạng đang dùng, sau khi parent_code_of() bỏ 1 "Z" cuối:
        // {mã shop}.Z{số}
        if (preg_match('/^([A-Z0-9]+?)\.Z\d+$/', $code, $m)) {
            return $m[1];
        }

        // Phiếu hoàn DẠNG CŨ: {mã shop}.B{số}[chữ chen]{số}
        if (preg_match('/^([A-Z0-9]+)\.' . self::MARKER_LETTER . '\d+(?:[A-Z]\d+)?$/', $code, $m)) {
            return $m[1];
        }

        // Phiếu bán DẠNG CŨ: {mã shop}B{số}[chữ chen]{số}
        if (preg_match('/^([A-Z0-9]+?)' . self::MARKER_LETTER . '\d+(?:[A-Z]\d+)?$/', $code, $m)) {
            return $m[1];
        }

        // Dạng cũ: {mã shop}{2 chữ cái}{5 số}
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
