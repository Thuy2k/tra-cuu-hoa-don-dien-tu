# Bill ở quầy và trang tra cứu hóa đơn điện tử

Trang `/tra-cuu-hoa-don-dien-tu` in ra **đúng tờ bill khách cầm ở cửa hàng** — cùng
mẫu, cùng font, cùng con số, cùng cách làm tròn.

> ⚠ **SỬA BILL THÌ SỬA Ở `tgs_pos`, KHÔNG SỬA Ở PLUGIN NÀY.**
> Plugin này không có mẫu bill riêng. Sửa ở đây là bill trên mạng khác bill giấy
> khách đang cầm — mà khách thì so từng dòng.

---

## 1. Ai lo phần nào

| Việc | Nằm ở đâu |
|---|---|
| **Số liệu** (đơn giá, CK, thành tiền, ĐVT, quà tặng, thanh toán) | `tgs_pos` → `TGS_POS_Ajax_Order::get_order_receipt_data()` |
| **Mẫu bill + CSS** | `tgs_pos` → `assets/js/pos-receipt-template.js` (`generate()`, `getSkinStyles()`) |
| **Từ mã đơn ra đúng shop** | plugin này → `TGS_Invoice_Lookup_Resolver` |
| **Gom phiếu cha + phiếu tách** | plugin này → `TGS_Invoice_Lookup_Bills` |
| **Khung trang, ô nhập mã** | plugin này → `tra-cuu-hoa-don-dien-tu.php` |

Nói gọn: plugin này **chỉ** lo *tìm đúng phiếu của đúng shop* rồi đưa cho mẫu bill
của POS vẽ. Không có một phép nhân chia nào về tiền trong plugin này — có test
chặn việc lỡ tay thêm vào.

### Ba đường in bill, một nguồn duy nhất

```
Lúc bán (POS)          →  partials/pos-receipt-template.php  ┐
In lại (màn Đơn hàng)  →  TGSReceiptTemplate.generate()      ├─ cùng getSkinStyles()
Tra cứu (trang này)    →  TGSReceiptTemplate.generate()      ┘
```

Dữ liệu của hai đường sau lấy từ **cùng một hàm** `get_order_receipt_data()`.
Trước đây trang tra cứu tự dựng lại số liệu bằng công thức riêng — sớm muộn lệch
tiền với bill giấy, mà lúc đó không ai biết bên nào đúng.

---

## 2. Từ mã đơn ra đúng shop

Mỗi shop là một site, **mỗi site một bảng `local_ledger` riêng**. Nên bắt buộc
phải xác định shop trước, `switch_to_blog()`, rồi mới đọc phiếu.

```
26003 B 01 K 23 [Z]        26003 . B 0 M 12 [Z]   ← phiếu hoàn
└─┬─┘ │ └┬┘ │ └┬┘ └┬┘      └─┬─┘ │
  │   │  │  │  │   └── có Z = phiếu TÁCH hàng khuyến mãi của mã liền trước
  │   │  │  │  └────── số chạy, mã LUÔN kết thúc bằng chữ số
  │   │  │  └───────── chữ CHEN ngẫu nhiên, không bao giờ dính chữ B
  │   │  └──────────── số chạy
  │   └─────────────── chữ MỐC, luôn là B
  └─────────────────── mã shop = wp_blogs.tgs_site_code
```

Suy ngược: bỏ `Z`, **cắt tại chữ `B` đầu tiên** (với phiếu hoàn thì cắt tại dấu
chấm), phần bên trái tra trong `wp_blogs.tgs_site_code` ra `blog_id`. Mã shop
toàn số hoặc `CNTEST` nên không chứa chữ B — cắt kiểu này không nhầm được.

Thân mã luôn 11 ký tự, kèm `Z` là 12 — trần của phần mềm cũ khi đồng bộ ngược.
Quy luật đặt mã nằm ở `tgs_pos/includes/class-tgs-pos-sale-code.php` — **hai bên
phải đọc/ghi cùng một quy luật**, đổi một bên là bên kia tra không ra.

### Bốn trường hợp đặc biệt

| Mã | Xử lý |
|---|---|
| `26003AA01216` | Dạng cũ ({mã shop} + 2 chữ cái + 5 số) — vẫn tra được, sổ còn hàng nghìn phiếu như thế |
| `CNTESTB50M2` | `CNTEST` là tiền tố dùng chung cho **mọi** site chưa khai `tgs_site_code` → phải dò lần lượt các site đó cho tới khi tìm thấy phiếu |
| `HD7_G4GST` | Dạng cũ hơn nữa, mã tự nói ra `blog_id` — vẫn tra được, khách còn giữ bill cũ |
| Hai site khai trùng mã shop | Trả về nhiều ứng viên, dò lần lượt |

Vì vậy `candidate_blog_ids()` trả về **mảng**, không phải một số.

---

## 3. Phiếu cha và phiếu tách (mã Z)

Đơn có hàng khuyến mãi được tách làm hai phiếu: phiếu chính, và phiếu tách mang
mã cha + `Z`.

Trang tra cứu **luôn hiện cả hai**, dù khách nhập mã nào:

- Nhập mã cha → hiện phiếu cha, phiếu tách nằm ngay bên dưới.
- Nhập mã `…Z` → leo ngược lên phiếu cha rồi dựng lại từ đó, thứ tự in ra vẫn là
  **cha trước, tách sau**.

Quan hệ cha ↔ con lấy từ chính `get_order_receipt_data()`: khoá `parent_sale` và
`promo_split`. Không tự dò `local_ledger_parent_id` ở đây.

---

## 4. Ba đường vào trang

```
/tra-cuu-hoa-don-dien-tu?order_code=26003B01K23
/tra-cuu-hoa-don-dien-tu?order_code=26003B01K23&blog_id=7   (ép đúng shop)
/tra-cuu-hoa-don-dien-tu?q=<payload>.<chữ ký>                (link có hạn dùng)
```

Nút **TRA CỨU HÓA ĐƠN** trên tin Zalo ZNS đi vào đường thứ nhất: template ZNS có
tham số `hoadon` mang giá trị `order_code=…`, do
`TGS_Zalo_Hooks` dựng từ field `hoadon_query`.

---

## 5. Sửa gì thì sửa ở đâu

| Muốn đổi | Sửa ở |
|---|---|
| Thêm/bớt dòng trên bill, đổi thứ tự cột | `tgs_pos/assets/js/pos-receipt-template.js` → `generate()` |
| Cỡ chữ, độ đậm, khổ giấy, khoảng cách | cùng file → `getSkinStyles()` |
| Cách tính tiền, làm tròn, ĐVT | `tgs_pos` → `get_order_receipt_data()`, theo đúng hai tài liệu luật tiền bên dưới |
| Bill lúc bán (bản Alpine.js) | `tgs_pos/templates/front/partials/pos-receipt-template.php` — **nhớ sửa cả `generate()`**, hai bản phải khớp nhau |
| Quy luật mã đơn | `tgs_pos/includes/class-tgs-pos-sale-code.php` **và** `TGS_Invoice_Lookup_Resolver` |
| Khung trang tra cứu, ô nhập, thông báo lỗi | `tra-cuu-hoa-don-dien-tu.php` |

### Luật tiền — hai tài liệu bắt buộc đọc

- `tgs_shop_management/docs/mo-hinh-tien-va-bang-local-ledger-item.md`
  → thành tiền = làm tròn đồng (tiền hàng sau CK + **tiền thuế đã lưu**), làm
  tròn **từng dòng** rồi mới cộng.
- `tgs_pos/docs/GIA_VA_DON_VI_TINH.md`
  → đơn giá và số lượng in trên bill theo **ĐVT lúc bán**, không quy về ĐVT gốc.

---

## 6. Vì sao skin CSS bị đổi selector

`getSkinStyles()` bên POS bọc mọi rule trong `#receipt-content`, vì ở màn POS chỉ
có **một** tờ bill trên màn hình. Trang tra cứu in cả phiếu cha lẫn phiếu tách nên
phải có nhiều tờ, mà `id` thì không được trùng.

Nên trang này lấy nguyên chuỗi CSS đó rồi đổi `#receipt-content` →
`.tgs-receipt-paper`. Vẫn là **một nguồn CSS duy nhất**: sửa bill bên POS là ở đây
đổi theo, không phải chép lại.

---

## 7. Test

`test-invoice-lookup.php` (thư mục scratchpad) soi:

- đọc mã đơn: mã shop, đuôi Z, mã cũ `HD{blog_id}_`, mã sai dạng;
- ra đúng shop: khớp `tgs_site_code`, dò `CNTEST`, bảng chưa có cột;
- **plugin này không được có công thức tiền nào** — không `round(`, không đụng
  `tax_amount` / `discount_amount`;
- hàm dùng chung bên POS còn nguyên và không đòi nonce (trang công khai gọi vào).
