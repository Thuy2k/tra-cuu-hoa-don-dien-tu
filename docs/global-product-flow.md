# Luồng sản phẩm global cho tra cứu hóa đơn điện tử

Tài liệu này ghi lại chuẩn đọc sản phẩm của plugin `tra-cuu-hoa-don-dien-tu`.

## Nguyên tắc

- Không query hoặc join bảng sản phẩm local `wp_local_product_name` / `{blog_prefix}_local_product_name`.
- Catalog sản phẩm lấy từ `TGS_Global_Product_Source` của plugin `tgs_shop_management`.
- REST/API chuẩn tham khảo thêm tại `wp-content/plugins/tgs_shop_management/docs/global-product-api.md`.
- Dòng hóa đơn vẫn lấy từ `{blog_prefix}_local_ledger_item` vì đây là chứng từ bán hàng đã phát sinh ở POS.
- `local_product_name_id` trong ledger item được hiểu là alias của `global_product_name_id`.
- `local_product_sku` trong ledger item là SKU snapshot, khớp `global_product_sku` và được ưu tiên khi hydrate sản phẩm.
- Các key `local_product_*` còn xuất hiện trong payload chỉ là alias tương thích với template hóa đơn POS cũ.

## File chính

`tra-cuu-hoa-don-dien-tu.php`

Luồng tra cứu:

1. Nhận `order_code` hoặc token `q`.
2. Suy ra `blog_id` từ token hoặc mã dạng `HD{blog_id}_...`.
3. `switch_to_blog($blog_id)` để đọc ledger của đúng shop.
4. Tìm đơn trong `{blog_prefix}_local_ledger`.
5. Nếu đơn là sale order thì tìm ledger bán đã duyệt tương ứng.
6. Đọc dòng hàng từ `{blog_prefix}_local_ledger_item`.
7. Gọi `TGS_Invoice_Lookup_Global_Products::enrich_ledger_items()` để bổ sung tên, SKU, đơn vị, barcode, thuế từ global product.
8. Render payload cho `TGSReceiptTemplate` của POS.

## Helper global

`includes/class-tgs-invoice-lookup-global-products.php`

Các hàm chính:

- `global_lot_table()`: trả bảng lot global `wp_global_product_lots`.
- `enrich_ledger_items(array $rows, $blog_id)`: nhận các dòng ledger item, gom ID/SKU rồi lấy catalog global một lần.
- `row_product_id(array $row)`: đọc `global_product_name_id`, nếu chưa có thì dùng alias `local_product_name_id`.
- `row_sku(array $row)`: ưu tiên `global_product_sku`, `local_product_sku`, `sku`, sau đó mới đọc `local_ledger_item_meta.sku`.

Quy tắc hydrate:

- Ưu tiên tìm sản phẩm bằng SKU snapshot của ledger item.
- Nếu SKU trống hoặc không tìm được thì tìm bằng ID global.
- Sau khi tìm được sản phẩm, helper gắn cả key `global_product_*` và alias `local_product_*` để template cũ chạy tiếp.
- Không fallback sang bảng sản phẩm local.

## Lot sản phẩm

Lot/mã định danh vẫn đọc từ bảng global lot:

```text
wp_global_product_lots
```

Khóa lot trong hóa đơn lấy từ `local_ledger_item.list_product_lots`, là danh sách `global_product_lot_id`.

## Checklist khi phát triển tiếp

- Không thêm `FROM local_product_name` hoặc `JOIN local_product_name`.
- Khi cần tên/SKU/barcode/đơn vị/thuế sản phẩm, dùng `TGS_Invoice_Lookup_Global_Products`.
- Khi cần tìm kiếm hoặc lấy danh sách sản phẩm đầy đủ, đọc `tgs_shop_management/docs/global-product-api.md` và dùng `TGS_Global_Product_Source`.
- Giữ `local_ledger_item` làm nguồn dòng hàng hóa đơn, nhưng không coi các cột `local_product_*` là catalog local.
