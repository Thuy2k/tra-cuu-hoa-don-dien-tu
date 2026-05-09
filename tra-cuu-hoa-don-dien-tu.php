<?php
/**
 * Plugin Name: Tra Cuu Hoa Don Dien Tu
 * Description: Trang tra cuu hoa don dien tu cong khai theo blog_id va order_code, tai su dung template hoa don tu plugin tgs_pos.
 * Version: 1.0.0
 * Author: TGS
 * Network: true
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TGS_Invoice_Lookup_Public
{
    private const LOOKUP_SLUG = 'tra-cuu-hoa-don-dien-tu';

    public static function init()
    {
        add_action('template_redirect', [__CLASS__, 'handle_template_redirect'], 0);
    }

    public static function handle_template_redirect()
    {
        if (is_admin()) {
            return;
        }

        if (!self::is_lookup_request()) {
            return;
        }

        self::render_page();
        exit;
    }

    private static function is_lookup_request()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = trim((string) parse_url($uri, PHP_URL_PATH), '/');
        if ($path === '') {
            return false;
        }

        $segments = explode('/', $path);
        $last_segment = strtolower(trim((string) end($segments)));

        return $last_segment === self::LOOKUP_SLUG;
    }

    private static function base64url_decode($input)
    {
        $input = strtr((string) $input, '-_', '+/');
        $padding = strlen($input) % 4;
        if ($padding > 0) {
            $input .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($input, true);
    }

    private static function parse_request()
    {
        $blog_id = isset($_GET['blog_id']) ? intval($_GET['blog_id']) : 0;
        $order_code = isset($_GET['order_code']) ? sanitize_text_field(wp_unslash($_GET['order_code'])) : '';
        $token = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $token_error = '';

        if ($token !== '') {
            $parts = explode('.', $token, 2);
            if (count($parts) !== 2) {
                $token_error = 'Token tra cứu không hợp lệ (thiếu chữ ký).';
            } else {
                $payload_b64 = $parts[0];
                $provided_sig = $parts[1];
                $expected_sig = hash_hmac('sha256', $payload_b64, wp_salt('auth'));

                if (!hash_equals($expected_sig, $provided_sig)) {
                    $token_error = 'Token tra cứu không hợp lệ (sai chữ ký).';
                } else {
                    $payload_json = self::base64url_decode($payload_b64);
                    $payload = $payload_json ? json_decode($payload_json, true) : null;

                    if (!is_array($payload)) {
                        $token_error = 'Token tra cứu không hợp lệ (payload lỗi).';
                    } else {
                        if (!empty($payload['exp']) && intval($payload['exp']) < time()) {
                            $token_error = 'Link tra cứu đã hết hạn.';
                        } else {
                            $blog_id = intval($payload['blog_id'] ?? 0);
                            $order_code = sanitize_text_field((string) ($payload['order_code'] ?? ''));
                        }
                    }
                }
            }
        }

        return [
            'blog_id' => $blog_id,
            'order_code' => strtoupper($order_code),
            'token_error' => $token_error,
            'has_query' => ($blog_id > 0 || $order_code !== '' || $token !== ''),
        ];
    }

    private static function table_exists($table_name)
    {
        global $wpdb;

        $table_name = (string) $table_name;
        if ($table_name === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));

        return $found === $table_name;
    }

    private static function payment_label($method, $note = '')
    {
        $method = sanitize_text_field((string) $method);
        $note = sanitize_text_field((string) $note);

        if ($note !== '' && preg_match('/QR Payment \\((.*?)\\)/', $note, $matches)) {
            return strtoupper(trim($matches[1]));
        }

        if (strpos($method, 'ttck_up_') === 0) {
            return strtoupper($method);
        }

        $labels = [
            'cash' => 'Tiền mặt',
            'transfer' => 'Chuyển khoản',
            'bank_transfer' => 'Chuyển khoản',
            'card' => 'Thẻ',
            'qr' => 'QR CODE',
            'split' => 'Hỗn hợp',
        ];

        return isset($labels[$method]) ? $labels[$method] : strtoupper($method ?: 'cash');
    }

    private static function get_order_data($blog_id, $order_code)
    {
        global $wpdb;

        $blog_id = intval($blog_id);
        $order_code = strtoupper(trim((string) $order_code));

        if ($blog_id <= 0) {
            return ['success' => false, 'message' => 'Thiếu blog_id để tra cứu.'];
        }
        if ($order_code === '') {
            return ['success' => false, 'message' => 'Thiếu mã đơn để tra cứu.'];
        }

        $switched = false;
        if (get_current_blog_id() !== $blog_id) {
            switch_to_blog($blog_id);
            $switched = true;
        }

        try {
            $ledger_table = $wpdb->prefix . 'local_ledger';
            $item_table = $wpdb->prefix . 'local_ledger_item';
            $person_table = $wpdb->prefix . 'local_ledger_person';
            $product_table = $wpdb->prefix . 'local_product_name';
            $meta_table = $wpdb->prefix . 'local_ledger_meta';
            $lot_table = defined('TGS_TABLE_GLOBAL_PRODUCT_LOTS') ? TGS_TABLE_GLOBAL_PRODUCT_LOTS : 'wp_global_product_lots';

            if (!self::table_exists($ledger_table)) {
                return ['success' => false, 'message' => 'Cửa hàng chưa có bảng đơn hàng POS.'];
            }

            $type_sale_order = defined('TGS_LEDGER_TYPE_SALE_ORDER') ? intval(TGS_LEDGER_TYPE_SALE_ORDER) : 10;
            $type_sale = defined('TGS_LEDGER_TYPE_SALE') ? intval(TGS_LEDGER_TYPE_SALE) : 12;

            $order = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT l.*, p.local_ledger_person_name, p.local_ledger_person_phone, p.user_wp_id
                     FROM $ledger_table l
                     LEFT JOIN $person_table p ON l.local_ledger_person_id = p.local_ledger_person_id
                     WHERE l.local_ledger_code = %s AND (l.is_deleted = 0 OR l.is_deleted IS NULL)
                     ORDER BY (l.local_ledger_type = %d) DESC, l.local_ledger_id DESC
                     LIMIT 1",
                    $order_code,
                    $type_sale_order
                )
            );

            if (!$order) {
                return ['success' => false, 'message' => 'Không tìm thấy đơn hàng với mã đã nhập.'];
            }

            $items_ledger_id = intval($order->local_ledger_id);
            if (intval($order->local_ledger_type) === $type_sale_order) {
                $child_ledger = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT local_ledger_id FROM $ledger_table
                         WHERE local_ledger_parent_id = %d
                           AND local_ledger_type = %d
                           AND (is_deleted = 0 OR is_deleted IS NULL)
                         ORDER BY local_ledger_id DESC
                         LIMIT 1",
                        $items_ledger_id,
                        $type_sale
                    )
                );
                if ($child_ledger) {
                    $items_ledger_id = intval($child_ledger->local_ledger_id);
                }
            }

            $items = [];
            if (self::table_exists($item_table) && self::table_exists($product_table)) {
                $items = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT li.*, pn.local_product_name AS product_name, pn.local_product_barcode_main AS barcode_main,
                                pn.local_product_tax AS tax, pn.local_product_price, pn.local_product_price_after_tax
                         FROM $item_table li
                         LEFT JOIN $product_table pn ON li.local_product_name_id = pn.local_product_name_id
                         WHERE li.local_ledger_id = %d AND (li.is_deleted = 0 OR li.is_deleted IS NULL)",
                        $items_ledger_id
                    ),
                    ARRAY_A
                );
            }

            $result_items = [];
            $gift_products = [];

            foreach ((array) $items as $item) {
                $lot_barcodes = [];
                $lot_details = [];
                $item_meta = [];

                if (!empty($item['local_ledger_item_meta'])) {
                    $decoded_meta = json_decode($item['local_ledger_item_meta'], true);
                    if (is_array($decoded_meta)) {
                        $item_meta = $decoded_meta;
                    }
                }

                if (!empty($item['list_product_lots']) && self::table_exists($lot_table)) {
                    $lot_ids = json_decode($item['list_product_lots'], true);
                    if (is_array($lot_ids) && !empty($lot_ids)) {
                        $lot_ids = array_filter(array_map('intval', $lot_ids));
                        if (!empty($lot_ids)) {
                            $lot_ids_str = implode(',', $lot_ids);
                            $lots = $wpdb->get_results(
                                "SELECT global_product_lot_barcode AS lot_barcode, lot_code, exp_date, mfg_date
                                 FROM $lot_table
                                 WHERE global_product_lot_id IN ($lot_ids_str)",
                                ARRAY_A
                            );

                            foreach ((array) $lots as $lot) {
                                $barcode = (string) ($lot['lot_barcode'] ?? '');
                                $lot_code = (string) ($lot['lot_code'] ?? '');
                                $exp_date = !empty($lot['exp_date']) ? date('d/m/Y', strtotime((string) $lot['exp_date'])) : null;
                                $mfg_date = !empty($lot['mfg_date']) ? date('d/m/Y', strtotime((string) $lot['mfg_date'])) : null;

                                $lot_barcodes[] = $barcode;
                                $lot_details[] = [
                                    'barcode' => $barcode,
                                    'lot_code' => $lot_code,
                                    'exp_date' => $exp_date,
                                    'mfg_date' => $mfg_date,
                                ];
                            }
                        }
                    }
                }

                $price_before_tax = floatval($item['local_product_price'] ?? $item['price'] ?? 0);
                $price_after_tax = floatval($item['local_product_price_after_tax'] ?? $price_before_tax);
                $tax = floatval($item['tax'] ?? 0);
                $item_note = (string) ($item['local_ledger_item_note'] ?? '');
                $item_qty = intval($item['local_ledger_item_qty'] ?? $item['quantity'] ?? 1);

                $is_gift = false;
                if (!empty($item_meta['is_gift'])) {
                    $is_gift = true;
                }
                if (stripos($item_note, 'Quà tặng KM') !== false) {
                    $is_gift = true;
                }

                if ($is_gift) {
                    $gift_products[] = [
                        'productName' => (string) ($item['product_name'] ?? $item['local_product_name'] ?? 'Sản phẩm tặng'),
                        'productId' => intval($item['local_product_name_id'] ?? 0),
                        'quantity' => $item_qty,
                        'lotDetails' => $lot_details,
                        'note' => $item_note,
                    ];
                } else {
                    $result_items[] = [
                        'name' => (string) ($item['product_name'] ?? $item['local_product_name'] ?? 'Sản phẩm'),
                        'qty' => $item_qty,
                        'price' => $price_after_tax,
                        'price_before_tax' => $price_before_tax,
                        'tax' => $tax,
                        'barcode_main' => (string) ($item['barcode_main'] ?? ''),
                        'lot_barcodes' => $lot_barcodes,
                        'lot_details' => $lot_details,
                        'sku' => (string) ($item_meta['sku'] ?? ''),
                        'unit' => (string) ($item_meta['unit'] ?? ''),
                    ];
                }
            }

            $cashier_name = '';
            if (!empty($order->user_id)) {
                $cashier = get_userdata(intval($order->user_id));
                if ($cashier) {
                    $cashier_name = $cashier->display_name ? $cashier->display_name : $cashier->user_login;
                }
            }

            $customer_user_login = '';
            if (!empty($order->user_wp_id)) {
                $customer_user = get_userdata(intval($order->user_wp_id));
                if ($customer_user) {
                    $customer_user_login = (string) $customer_user->user_login;
                }
            }

            $applied_promotions = [];
            $loyalty_points_earned = 0;
            $discount = 0;
            $points_used = 0;
            $selected_rewards = [];
            $issued_vouchers = [];
            $loyalty_points_after = 0;
            $payment_method = 'cash';
            $payment_method_label = self::payment_label($payment_method);
            $payment_receipts = [];

            if (!empty($order->local_ledger_meta_id) && self::table_exists($meta_table)) {
                $meta_value = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT local_ledger_meta_value FROM $meta_table WHERE local_ledger_meta_id = %d",
                        intval($order->local_ledger_meta_id)
                    )
                );

                if ($meta_value) {
                    $meta_data = json_decode($meta_value, true);
                    if (is_array($meta_data)) {
                        $applied_promotions = is_array($meta_data['applied_promotions'] ?? null) ? $meta_data['applied_promotions'] : [];
                        $discount = floatval($meta_data['discount'] ?? 0);
                        $points_used = intval($meta_data['points_used'] ?? 0);
                        $selected_rewards = is_array($meta_data['selected_rewards'] ?? null) ? $meta_data['selected_rewards'] : [];
                        $issued_vouchers = is_array($meta_data['issued_vouchers'] ?? null) ? $meta_data['issued_vouchers'] : [];
                        $loyalty_points_earned = intval($meta_data['points_earned'] ?? 0);
                        $loyalty_points_after = floatval($meta_data['loyalty_points_after'] ?? 0);

                        $payment_method = sanitize_text_field((string) ($meta_data['payment_method'] ?? 'cash'));
                        $payment_method_label = sanitize_text_field((string) ($meta_data['payment_method_label'] ?? self::payment_label($payment_method)));

                        if (is_array($meta_data['payment_receipts'] ?? null)) {
                            foreach ($meta_data['payment_receipts'] as $receipt) {
                                if (!is_array($receipt)) {
                                    continue;
                                }
                                $row_method = sanitize_text_field((string) ($receipt['method'] ?? 'cash'));
                                $row_note = sanitize_textarea_field((string) ($receipt['note'] ?? ''));
                                $payment_receipts[] = [
                                    'method' => $row_method,
                                    'label' => sanitize_text_field((string) ($receipt['label'] ?? self::payment_label($row_method, $row_note))),
                                    'amount' => floatval($receipt['amount'] ?? 0),
                                    'note' => $row_note,
                                ];
                            }
                        }

                        if ($loyalty_points_earned <= 0 && !empty($applied_promotions)) {
                            foreach ($applied_promotions as $promo) {
                                if (($promo['type'] ?? '') === 'loyalty_points') {
                                    $loyalty_points_earned += intval($promo['points_earned'] ?? ($promo['calculated_result']['points_earned'] ?? 0));
                                }
                            }
                        }
                    }
                }
            }

            if (empty($payment_receipts)) {
                $payment_receipts[] = [
                    'method' => $payment_method,
                    'label' => $payment_method_label,
                    'amount' => floatval($order->local_ledger_total_amount ?? 0),
                    'note' => '',
                ];
            }

            $store = [
                'name' => get_bloginfo('name'),
                'logo' => (string) get_option('tgs_shop_logo', ''),
                'defaultLogo' => content_url('plugins/tgs_pos/assets/images/logo.webp'),
                'phone' => (string) get_option('tgs_shop_phone', '1900636605'),
                'website' => (string) get_option('tgs_shop_website', 'www.thegioisua.com'),
                'hours' => (string) get_option('tgs_shop_hours', '7h - 21h30'),
            ];

            return [
                'success' => true,
                'order' => [
                    'id' => intval($order->local_ledger_id),
                    'code' => (string) ($order->local_ledger_code ?? ''),
                    'customer_name' => (string) ($order->local_ledger_person_name ?? 'Khách lẻ'),
                    'customer_phone' => (string) ($order->local_ledger_person_phone ?? ''),
                    'user_login' => $customer_user_login,
                    'total' => floatval($order->local_ledger_total_amount ?? 0),
                    'discount' => $discount,
                    'created_at' => (string) ($order->created_at ?? ''),
                    'items' => $result_items,
                    'gift_products' => $gift_products,
                    'applied_promotions' => $applied_promotions,
                    'loyalty_points_earned' => $loyalty_points_earned,
                    'points_used' => $points_used,
                    'selected_rewards' => $selected_rewards,
                    'issued_vouchers' => $issued_vouchers,
                    'loyalty_points_after' => $loyalty_points_after,
                    'cashier_name' => $cashier_name,
                    'payment_method' => $payment_method,
                    'payment_method_label' => $payment_method_label,
                    'payment_receipts' => $payment_receipts,
                    'sale_ledger_id' => $items_ledger_id,
                ],
                'store' => $store,
            ];
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private static function render_page()
    {
        $params = self::parse_request();
        $lookup = [
            'success' => false,
            'message' => '',
            'order' => null,
            'store' => null,
        ];

        if ($params['token_error'] !== '') {
            $lookup['message'] = $params['token_error'];
        } elseif ($params['blog_id'] > 0 && $params['order_code'] !== '') {
            $lookup = self::get_order_data($params['blog_id'], $params['order_code']);
            if (empty($lookup['success']) && empty($lookup['message'])) {
                $lookup['message'] = 'Không thể tra cứu hóa đơn vào lúc này.';
            }
        } elseif ($params['has_query']) {
            $lookup['message'] = 'Vui lòng cung cấp đầy đủ blog_id và order_code.';
        }

        $payload = [
            'params' => [
                'blog_id' => intval($params['blog_id']),
                'order_code' => (string) $params['order_code'],
            ],
            'success' => !empty($lookup['success']),
            'message' => (string) ($lookup['message'] ?? ''),
            'order' => !empty($lookup['success']) ? ($lookup['order'] ?? null) : null,
            'store' => !empty($lookup['success']) ? ($lookup['store'] ?? null) : null,
        ];

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Tra cứu hóa đơn điện tử</title>
  <link rel="stylesheet" href="<?php echo esc_url(content_url('plugins/tgs_pos/assets/css/tailwind.min.css')); ?>" />
  <link rel="stylesheet" href="<?php echo esc_url(content_url('plugins/tgs_pos/assets/css/pos-tailwind.css')); ?>" />
  <link rel="stylesheet" href="<?php echo esc_url(content_url('plugins/tgs_pos/assets/css/pos-tailwind-utilities.css')); ?>" />
  <style>
    body {
      margin: 0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      background: #f3f4f6;
      color: #111827;
    }
    .tgs-invoice-lookup-wrap {
      max-width: 960px;
      margin: 0 auto;
      padding: 16px;
    }
    .tgs-invoice-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
    }
    #receipt-content {
      border-radius: 14px;
      border: 1px solid #d1d5db;
      background: #fff;
    }
    @media (max-width: 640px) {
      .tgs-invoice-lookup-wrap {
        padding: 10px;
      }
    }
  </style>
</head>
<body>
  <div class="tgs-invoice-lookup-wrap">
    <div class="tgs-invoice-card p-4 sm:p-6">
      <div class="mb-4">
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 mb-1">Tra cứu hóa đơn điện tử</h1>
      </div>

      <form method="get" action="" class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <div>
          <label for="blog_id" class="block text-xs font-semibold text-slate-600 mb-1">Blog ID</label>
          <input id="blog_id" name="blog_id" type="number" min="1" value="<?php echo esc_attr($params['blog_id']); ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Ví dụ: 7" required />
        </div>
        <div>
          <label for="order_code" class="block text-xs font-semibold text-slate-600 mb-1">Mã đơn</label>
          <input id="order_code" name="order_code" type="text" value="<?php echo esc_attr($params['order_code']); ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Ví dụ: HD0355" required />
        </div>
        <div class="flex items-end">
          <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
            Tra cứu
          </button>
        </div>
      </form>

      <div id="lookup-message" class="mb-4 text-sm"></div>

      <div id="receipt-host" class="bg-gray-50 rounded-xl p-3 sm:p-4 min-h-[180px]">
        <div class="text-sm text-slate-500">Nhập thông tin hoặc mở link có sẵn tham số để xem hóa đơn.</div>
      </div>
    </div>
  </div>

  <script src="<?php echo esc_url(content_url('plugins/tgs_pos/assets/js/pos-receipt-template.js')); ?>"></script>
  <script>
    (function () {
      var payload = <?php echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      var host = document.getElementById('receipt-host');
      var message = document.getElementById('lookup-message');

      function setMessage(text, type) {
        message.textContent = text || '';
        if (!text) {
          message.className = 'mb-4 text-sm';
          return;
        }
        if (type === 'error') {
          message.className = 'mb-4 text-sm rounded-lg border border-red-300 bg-red-50 text-red-700 px-3 py-2';
          return;
        }
        message.className = 'mb-4 text-sm rounded-lg border border-emerald-300 bg-emerald-50 text-emerald-700 px-3 py-2';
      }

      if (!payload || !payload.success) {
        if (payload && payload.message) {
          setMessage(payload.message, 'error');
          host.innerHTML = '<div class="text-sm text-slate-500">Không có dữ liệu hóa đơn để hiển thị.</div>';
        }
        return;
      }

      if (!window.TGSReceiptTemplate || typeof window.TGSReceiptTemplate.generate !== 'function') {
        setMessage('Không tải được module mẫu hóa đơn từ POS.', 'error');
        host.innerHTML = '<div class="text-sm text-red-600">Thiếu TGSReceiptTemplate.</div>';
        return;
      }

      try {
        var html = window.TGSReceiptTemplate.generate(payload.order || {}, payload.store || {});
        host.innerHTML = html;
        setMessage('Đã tra cứu thành công hóa đơn ' + (payload.order.code || ''), 'success');
      } catch (err) {
        setMessage((err && err.message) ? err.message : 'Lỗi khi render hóa đơn.', 'error');
        host.innerHTML = '<div class="text-sm text-red-600">Không thể hiển thị hóa đơn.</div>';
      }
    })();
  </script>
</body>
</html>
<?php
    }
}

TGS_Invoice_Lookup_Public::init();
