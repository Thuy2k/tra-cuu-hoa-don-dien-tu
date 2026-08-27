<?php
/**
 * Plugin Name: Tra Cuu Hoa Don Dien Tu
 * Description: Trang tra cuu hoa don dien tu cong khai theo ma don. Suy ra dung shop tu ma (wp_blogs.tgs_site_code), va dung lai nguyen mau bill cua tgs_pos.
 * Version: 2.0.0
 * Author: TGS
 * Network: true
 *
 * =============================================================================
 * TRANG NÀY IN RA BILL Y HỆT BILL GIẤY Ở QUẦY
 *
 * Không có mẫu bill riêng ở đây. Cả dữ liệu lẫn hình thức đều mượn của tgs_pos:
 *
 *   - Số liệu : TGS_POS_Ajax_Order::get_order_receipt_data()
 *               (đúng hàm màn Đơn hàng dùng để in lại bill)
 *   - Hình thức: window.TGSReceiptTemplate.generate() + getSkinStyles()
 *               (đúng module dựng bill lúc bán)
 *
 * ⚠ SỬA BILL Ở ĐÂU: sửa trong tgs_pos, KHÔNG sửa ở plugin này. Sửa ở đây là
 * bill trên mạng khác bill khách cầm trong tay. Xem docs/bill-va-tra-cuu.md.
 *
 * ── MỖI SHOP MỘT BẢNG local_ledger ─────────────────────────────────────────
 *
 * Đây là mạng nhiều site, mỗi shop một bộ bảng riêng. Nên phải suy ra shop từ
 * mã đơn rồi switch_to_blog() mới đọc đúng phiếu — xem
 * TGS_Invoice_Lookup_Resolver.
 * =============================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-tgs-invoice-lookup-resolver.php';
require_once __DIR__ . '/includes/class-tgs-invoice-lookup-bills.php';

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

    /**
     * Đọc tham số tra cứu.
     *
     * Ba đường vào, đều dẫn tới cùng một chỗ:
     *   ?order_code=...          — khách tự gõ, hoặc nút "TRA CỨU HÓA ĐƠN" trên
     *                              tin Zalo ZNS (tham số `hoadon` của template)
     *   ?order_code=...&blog_id= — ép đúng shop, khỏi phải suy từ mã
     *   ?q=<token>               — link có chữ ký, dùng khi cần giới hạn hạn dùng
     */
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
                    } elseif (!empty($payload['exp']) && intval($payload['exp']) < time()) {
                        $token_error = 'Link tra cứu đã hết hạn.';
                    } else {
                        $order_code = sanitize_text_field((string) ($payload['order_code'] ?? ''));
                        $blog_id = intval($payload['blog_id'] ?? 0);
                    }
                }
            }
        }

        return [
            'blog_id' => $blog_id,
            'order_code' => TGS_Invoice_Lookup_Resolver::normalize_code($order_code),
            'token_error' => $token_error,
            'has_query' => ($blog_id > 0 || $order_code !== '' || $token !== ''),
        ];
    }

    private static function render_page()
    {
        $params = self::parse_request();

        $lookup = [
            'success' => false,
            'message' => '',
            'bills' => [],
            'store' => null,
        ];

        if ($params['token_error'] !== '') {
            $lookup['message'] = $params['token_error'];
        } elseif ($params['order_code'] !== '') {
            $lookup = TGS_Invoice_Lookup_Bills::lookup($params['order_code'], $params['blog_id']);
        } elseif ($params['has_query']) {
            $lookup['message'] = 'Vui lòng nhập mã đơn để tra cứu.';
        }

        $payload = [
            'params' => [
                'blog_id' => intval($params['blog_id']),
                'order_code' => (string) $params['order_code'],
            ],
            'success' => !empty($lookup['success']),
            'message' => (string) ($lookup['message'] ?? ''),
            'bills' => !empty($lookup['success']) ? array_values((array) $lookup['bills']) : [],
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

  <?php
  /*
   * Manrope là font của bill in ở quầy (xem getSkinStyles trong
   * pos-receipt-template.js). Không nạp thì trang tra cứu vẫn chạy nhưng chữ
   * nhảy sang font hệ thống — nhìn đã ra một tờ bill khác.
   */
  ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" />

  <style>
    body {
      margin: 0;
      font-family: "Manrope", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      background: #f1f5f9;
      color: #0f172a;
    }

    .tgs-lookup-wrap {
      max-width: 760px;
      margin: 0 auto;
      padding: 20px 14px 48px;
    }

    .tgs-lookup-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
      padding: 18px;
    }

    .tgs-lookup-title {
      margin: 0 0 4px;
      font-size: 20px;
      font-weight: 800;
    }

    .tgs-lookup-sub {
      margin: 0 0 16px;
      font-size: 13px;
      color: #64748b;
    }

    .tgs-lookup-form {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .tgs-lookup-form input {
      flex: 1 1 240px;
      min-width: 0;
      padding: 11px 12px;
      font-size: 15px;
      font-family: inherit;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      /* Mã in hoa, khách gõ thường cũng ra đúng */
      text-transform: uppercase;
    }

    .tgs-lookup-form button {
      flex: 0 0 auto;
      padding: 11px 26px;
      font-size: 15px;
      font-weight: 700;
      font-family: inherit;
      color: #fff;
      background: #2563eb;
      border: 0;
      border-radius: 10px;
      cursor: pointer;
    }

    .tgs-lookup-form button:hover { background: #1d4ed8; }

    .tgs-lookup-msg {
      margin-top: 14px;
      padding: 11px 13px;
      font-size: 14px;
      border-radius: 10px;
      border: 1px solid transparent;
    }

    .tgs-lookup-msg--error {
      background: #fef2f2;
      border-color: #fecaca;
      color: #b91c1c;
    }

    .tgs-lookup-msg--ok {
      background: #ecfdf5;
      border-color: #a7f3d0;
      color: #047857;
    }

    /* ── Tờ bill ────────────────────────────────────────────────────────── */

    .tgs-bill {
      margin-top: 18px;
    }

    .tgs-bill__label {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-bottom: 8px;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: #475569;
    }

    .tgs-bill__label span {
      padding: 3px 12px;
      border-radius: 999px;
      background: #e2e8f0;
    }

    .tgs-bill--split .tgs-bill__label span {
      background: #fef3c7;
      color: #92400e;
    }

    /*
     * Khổ giấy: nền trắng, bóng đổ nhẹ cho ra dáng tờ giấy. Bề ngang do chính
     * skin của POS quy định (302px = 80mm), ở đây chỉ căn giữa.
     */
    .tgs-receipt-paper {
      margin: 0 auto;
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.12);
      border-radius: 10px;
    }

    .tgs-lookup-note {
      margin-top: 22px;
      font-size: 12.5px;
      line-height: 1.6;
      color: #64748b;
      text-align: center;
    }

    @media print {
      .tgs-lookup-card,
      .tgs-lookup-note,
      .tgs-bill__label { display: none; }

      body { background: #fff; }
      .tgs-receipt-paper { box-shadow: none; border-radius: 0; }
    }
  </style>
</head>
<body>
  <div class="tgs-lookup-wrap">
    <div class="tgs-lookup-card">
      <h1 class="tgs-lookup-title">Tra cứu hóa đơn điện tử</h1>
      <p class="tgs-lookup-sub">Nhập mã đơn in trên hóa đơn giấy hoặc trong tin nhắn Zalo của cửa hàng.</p>

      <form method="get" action="" class="tgs-lookup-form">
        <input id="order_code" name="order_code" type="text" autocomplete="off"
               value="<?php echo esc_attr($params['order_code']); ?>"
               placeholder="Ví dụ: 26003B01K23" required />
        <button type="submit">Tra cứu</button>
      </form>

      <div id="lookup-message"></div>
    </div>

    <div id="bills-host"></div>

    <p class="tgs-lookup-note">
      Hóa đơn hiển thị đúng như bản in tại cửa hàng.<br>
      Cần hỗ trợ, vui lòng liên hệ tổng đài <strong>1900 636605</strong>.
    </p>
  </div>

  <?php
  /*
   * Luật "khách lẻ ⇒ Bán cho người tiêu dùng" — phải nạp TRƯỚC file dựng bill,
   * vì pos-receipt-template.js gọi window.tgsBuyerDisplayName() khi vẽ dòng
   * "Khách hàng". Thiếu file này thì bill vẫn chạy nhưng ghi lại "Khách lẻ" —
   * khác với bản in ở quầy và khác với hoá đơn đã gửi cơ quan thuế.
   */
  ?>
  <script src="<?php echo esc_url(content_url('plugins/tgs_pos/assets/js/tgs-retail-buyer.js')); ?>"></script>
  <script src="<?php echo esc_url(content_url('plugins/tgs_pos/assets/js/pos-receipt-template.js')); ?>"></script>
  <script>
    (function () {
      var payload = <?php echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      var host = document.getElementById('bills-host');
      var messageBox = document.getElementById('lookup-message');

      function setMessage(text, kind) {
        if (!text) {
          messageBox.className = '';
          messageBox.textContent = '';
          return;
        }
        messageBox.className = 'tgs-lookup-msg tgs-lookup-msg--' + (kind === 'error' ? 'error' : 'ok');
        messageBox.textContent = text;
      }

      /*
       * "Da" của bill lấy nguyên từ POS, chỉ đổi selector.
       *
       * Skin bên POS bọc trong #receipt-content vì ở đó chỉ có MỘT tờ bill trên
       * màn hình. Trang này in cả phiếu cha lẫn phiếu tách nên phải có nhiều tờ,
       * mà id thì không được trùng — đổi sang class .tgs-receipt-paper. Vẫn là
       * một nguồn CSS duy nhất: sửa bill bên POS là ở đây đổi theo.
       */
      function injectSkin() {
        if (!window.TGSReceiptTemplate || typeof window.TGSReceiptTemplate.getSkinStyles !== 'function') {
          return false;
        }
        if (document.getElementById('tgs-lookup-receipt-skin')) {
          return true;
        }

        var style = document.createElement('style');
        style.id = 'tgs-lookup-receipt-skin';
        style.textContent = window.TGSReceiptTemplate
          .getSkinStyles()
          .replace(/#receipt-content/g, '.tgs-receipt-paper');
        document.head.appendChild(style);

        return true;
      }

      if (!payload || !payload.success) {
        if (payload && payload.message) {
          setMessage(payload.message, 'error');
        }
        return;
      }

      if (!injectSkin() || typeof window.TGSReceiptTemplate.generate !== 'function') {
        setMessage('Không tải được mẫu hóa đơn. Vui lòng thử lại sau.', 'error');
        return;
      }

      var bills = Array.isArray(payload.bills) ? payload.bills : [];
      var rendered = 0;

      bills.forEach(function (bill) {
        if (!bill || !bill.order) {
          return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'tgs-bill' + (bill.role === 'split' ? ' tgs-bill--split' : '');

        var label = document.createElement('div');
        label.className = 'tgs-bill__label';
        var chip = document.createElement('span');
        chip.textContent = (bill.label || 'Hóa đơn') + ' · ' + (bill.order.code || '');
        label.appendChild(chip);
        wrap.appendChild(label);

        var paper = document.createElement('div');
        paper.className = 'tgs-receipt-paper';

        try {
          paper.innerHTML = window.TGSReceiptTemplate.generate(bill.order, payload.store || {});
        } catch (err) {
          paper.textContent = 'Không hiển thị được hóa đơn này.';
        }

        wrap.appendChild(paper);
        host.appendChild(wrap);
        rendered += 1;
      });

      if (rendered === 0) {
        setMessage('Không có dữ liệu hóa đơn để hiển thị.', 'error');
        return;
      }

      setMessage(
        rendered > 1
          ? 'Đơn hàng này gồm ' + rendered + ' phiếu (phiếu bán hàng và phiếu tách hàng khuyến mãi).'
          : 'Đã tìm thấy hóa đơn.',
        'ok'
      );
    })();
  </script>
</body>
</html>
<?php
    }
}

TGS_Invoice_Lookup_Public::init();
