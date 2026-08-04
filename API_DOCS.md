# 🦅 Eagle Life Admin - API Documentation

Tài liệu cho Frontend Engineer: Auth/Roles (Phase 2–3), Users (Phase 4), Orders (Phase 5), Import eBay (Phase 6), Printify sync foundation (Phase 7).

> **Envelope chuẩn:** hầu hết endpoint protected trả về `{ "status": "success"|"error", "message": "...", "data": ... }`.

---

## 1. Môi trường (Environment)

| Tên                  | Giá trị                                                          | Ghi chú                        |
| -------------------- | ---------------------------------------------------------------- | ------------------------------ |
| **Base URL**         | `http://localhost:8000/api`                                      | Hoặc URL server tương ứng      |
| **Headers Mặc định** | `Accept: application/json` <br> `Content-Type: application/json` | Bắt buộc để tránh lỗi redirect |

> **Lưu ý Quan trọng:** Với các API cần xác thực tĩnh (Protected), cần truyền header `Authorization: Bearer <access_token>`.

---

## 2. API Endpoints

### 2.1. Đăng nhập (Login)

- Xác thực người dùng và trả về Token cùng Role (người dùng mặc định để test: `admin@eaglelife.com` / `12345678`).

* **Đường dẫn**: `POST /login`
* **Yêu cầu Xác thực**: Không (Public)

**Request Body:**

```json
{
    "email": "admin@eaglelife.com",
    "password": "12345678"
}
```

**Response (200 OK):**

```json
{
    "success": true,
    "message": "Đăng nhập thành công",
    "data": {
        "access_token": "1|abcdef123456789...",
        "user": {
            "id": 1,
            "employee_code": "ADMIN001",
            "username": "admin",
            "name": "System Admin",
            "email": "admin@eaglelife.com",
            "phone": "0987654321",
            "avatar": null,
            "status": 1,
            "roles": [
                {
                    "id": 1,
                    "name": "admin",
                    "permissions": [
                        { "name": "users.view" },
                        { "name": "orders.view" }
                        // ...
                    ]
                }
            ]
        }
    }
}
```

**Response (422 validation error):**
Trạng thái tài khoản sai mật khẩu hoặc bị vô hiệu hóa (`status = 0`).

```json
{
    "message": "Tài khoản hoặc mật khẩu không chính xác.",
    "errors": {
        "email": ["Tài khoản hoặc mật khẩu không chính xác."]
    }
}
```

---

### 2.2. Lấy thông tin cá nhân (Get Profile/Me)

- Lấy thông tin tài khoản đang đăng nhập kèm theo quyền hạn hiện tại, dùng để reload context Auth bên Frontend.

* **Đường dẫn**: `GET /me`
* **Yêu cầu Xác thực**: Có (Bearer Token)

**Response (200 OK):**

```json
{
    "success": true,
    "message": "Thao tác thành công",
    "data": {
        "id": 1,
        "employee_code": "ADMIN001",
        "username": "admin",
        "name": "System Admin"
        // ... (Thông tin chi tiết role & permissions như khi login)
    }
}
```

**Response (401 Unauthorized):** Token hết hạn hoặc không hợp lệ.

---

### 2.3. Lấy Danh sách Vai trò (List Roles)

- Lấy các danh sách role dùng cho việc hiển thị dropdown phân quyền (ví dụ khi Admin/Quản lý tạo Nhân sự mới).

* **Đường dẫn**: `GET /roles`
* **Yêu cầu Xác thực**: Có (Bearer Token)

**Response (200 OK):**

```json
{
    "success": true,
    "message": "Lấy danh sách vai trò thành công",
    "data": [
        {
            "id": 1,
            "name": "admin",
            "permissions": [...]
        },
        {
            "id": 2,
            "name": "manager",
            "permissions": [...]
        },
        {
            "id": 3,
            "name": "sale",
            "permissions": [...]
        },
        {
            "id": 4,
            "name": "support",
            "permissions": [...]
        }
    ]
}
```

---

### 2.4. Đăng xuất (Logout)

- Rút quyền access token hiện hành. Nên được gọi khi Frontend bấm logout.

* **Đường dẫn**: `POST /logout`
* **Yêu cầu Xác thực**: Có (Bearer Token)

**Response (200 OK):**

```json
{
    "success": true,
    "message": "Đăng xuất thành công",
    "data": null
}
```

---

### 📝 Xử lý mã lỗi Frontend cần quan tâm

1. **`422 Unprocessable Entity`**: Lỗi logic/dữ liệu hoặc Validate -> Quét `errors` object trên Form.
2. **`401 Unauthorized`**: Token sai/hết hạn. Frontend cần clear `localStorage` và redirect về trang `/login`.
3. **`403 Forbidden`**: User gọi tới API không có quyền hạn -> Báo lỗi "Bạn không có quyền..".
4. **`500 Internal Server Error`**: Lỗi Server, hiển thị alert Toast chung chung. Tốt nhất là báo Developer Backend hỗ trợ.

---

## 3. Quản lý Nhân sự (Users)

- Giao tiếp bằng Auth Bearer Token. Mã nhân viên được backend tự động khởi tạo.

### 3.1. Danh sách Nhân viên

- **Đường dẫn**: `GET /users`
- **Query Params**: `search={keyword}`, `status={0/1}`, `role={admin/sale/...}`, `page={1}`, `per_page={15}`
- **Response**: Trả về Paginated JSON.

### 3.2. Tạo Nhân viên

- **Đường dẫn**: `POST /users`
- **Yêu cầu Body JSON** (ví dụ chuẩn Payload từ FE):

```json
{
    "name": "Yến",
    "password": "12345678",
    "role": "",
    "email": "yen@gmail.com",
    "phone": "09796643194",
    "avatar": "https://api.dicebear.com/7.x/notionists/svg?seed=Bob&backgroundColor=c0aede"
}
```

_Lưu ý: Không cần gửi `employee_code` (hệ thống tự code). Trường `role` truyền text (vd: `"admin"`, `"sale"` hoặc rỗng `""`)._

### 3.3. Sửa Nhân viên

- **Đường dẫn**: `PUT /users/{id}`
- **Yêu cầu Body JSON tương tự Tạo** (Chỉ cập nhật những field được submit lên)

```json
{
    "name": "Yến Updated",
    "avatar": "https://api.dicebear.com/.../new",
    "role": "manager"
}
```

### 3.4. Khoá/Mở khoá Nhân viên

- **Đường dẫn**: `PATCH /users/{id}/status`
- **Yêu cầu Body JSON**:

```json
{
    "status": 1
}
```

_(Lưu ý: 1 = Active, 0 = Banned)_

- **Đường dẫn**: `DELETE /users/{id}`

---

## 4. Quản lý Đơn hàng (Orders) - Phase 5

> Tất cả endpoints đều yêu cầu `Authorization: Bearer <token>`.  
> Hiện tại routes orders CRUD chỉ cần đăng nhập (chưa gắn Spatie permission trên route). Domain là **đơn eBay**, không phải CRM `customer_*` / `status`.

### 4.1. Danh sách Đơn hàng

- **Đường dẫn**: `GET /orders`
- **Query Params (tùy chọn)**:

| Param         | Ý nghĩa                                         | Ví dụ                    |
| ------------- | ----------------------------------------------- | ------------------------ |
| `search`      | Tìm theo `ebay_order_id` hoặc `printify_order_id` | `search=13-14975`      |
| `seller_id`   | Lọc seller                                      | `seller_id=3`            |
| `buyer_id`    | Lọc buyer                                       | `buyer_id=5`             |
| `from_date`   | Từ ngày `ebay_created_at` (yyyy-mm-dd)          | `from_date=2026-01-01`   |
| `to_date`     | Đến ngày                                        | `to_date=2026-12-31`     |
| `no_printify` | `1`/`true` → chỉ đơn chưa có `printify_order_id` | `no_printify=1`         |
| `page`        | Trang                                           | `page=1`                 |
| `per_page`    | Số dòng/trang (mặc định 15)                     | `per_page=20`            |

**Response:** envelope `{ status, message, data }` với `data` là Laravel paginator (`data`, `links`, `meta`). Mỗi item gồm `ebay_order_id`, `ebay_order_number`, `printify_order_id`, `ebay_created_at`, nested `buyer` / `seller` (`id`, `name`, `employee_code`).

### 4.2. Chi tiết Đơn hàng

- **Đường dẫn**: `GET /orders/{id}`

Eager-load: `buyer`, `seller`, `fulfillmentAddress`, `lineItems`. Response kèm `ebay_export_rows`, `ebay_buyer_*`, và nested address/line items từ import CSV.

**Response (200 OK):**

```json
{
    "status": "success",
    "message": "Lấy chi tiết đơn hàng thành công",
    "data": {
        "id": 1,
        "ebay_order_id": "13-14975-00010",
        "ebay_order_number": "13-14975-00010",
        "ebay_export_rows": [
            {
                "Order Number": "13-14975-00010",
                "Buyer Email": "buyer@members.ebay.com",
                "Ship To Country": "US"
            }
        ],
        "ebay_buyer_username": "harharrlind",
        "ebay_buyer_name": "Lindsey Harris",
        "ebay_buyer_email": "buyer@members.ebay.com",
        "printify_order_id": null,
        "ebay_created_at": "2026-08-02T12:00:00.000000Z",
        "buyer": null,
        "seller": {
            "id": 3,
            "name": "Tran Seller",
            "employee_code": "NV0001"
        },
        "fulfillment_address": {
            "first_name": "Lindsey",
            "last_name": "Harris",
            "email": "buyer@members.ebay.com",
            "phone": "+1 479-692-3507",
            "address_line1": "4168 SR 326",
            "city": "Russellville",
            "region": "AR",
            "postal_code": "72802-1427",
            "country_code": "US",
            "country": "US"
        },
        "line_items": [
            {
                "item_number": "123",
                "title": "Shirt",
                "quantity": 1,
                "unit_price": "10.00",
                "ebay_raw": {
                    "Item Title": "Shirt"
                }
            }
        ],
        "created_at": "2026-08-04T00:00:00.000000Z",
        "updated_at": "2026-08-04T00:00:00.000000Z"
    }
}
```

### 4.3. Cập nhật Đơn hàng

- **Đường dẫn**: `PUT /orders/{id}` / `PATCH /orders/{id}`
- **Body JSON** (chỉ các field được chấp nhận):

```json
{
    "seller_id": 3,
    "buyer_id": 5
}
```

### 4.4. Xoá Đơn hàng

- **Đường dẫn**: `DELETE /orders/{id}`

---

## 5. Import Đơn hàng eBay - Phase 6

> Yêu cầu `Authorization: Bearer <token>`.  
> `POST /orders/import` và `POST /orders/import-csv` cần permission `orders.import`.  
> **Ưu tiên hiện tại:** đưa đơn eBay vào hệ thống. Sync/lấy order từ Printify để bước sau.

### 5.1. Import JSON (đơn giản)

- **Đường dẫn**: `POST /orders/import`
- **Content-Type**: `application/json`

**Request Body:**

```json
{
    "orders": [
        {
            "ebay_order_id": "13-14975-00010",
            "ebay_created_at": "2026-08-02 12:00:00",
            "buyer_code": "NV0002",
            "seller_code": "NV0001"
        }
    ]
}
```

| Field             | Bắt buộc | Mô tả                                       |
| ----------------- | -------- | ------------------------------------------- |
| `ebay_order_id`   | ✅       | Mã đơn eBay (unique)                        |
| `ebay_created_at` | ✅       | Ngày tạo trên eBay                          |
| `buyer_code`      | ❌       | `employee_code` buyer (map sang `buyer_id`) |
| `seller_code`     | ❌       | `employee_code` seller                      |

**Response (200 OK):**

```json
{
    "status": "success",
    "message": "Import hoàn tất: 1/1 thành công",
    "data": {
        "total": 1,
        "success": 1,
        "failed": 0,
        "errors": []
    }
}
```

> Bản ghi trùng `ebay_order_id` sẽ bị bỏ qua và ghi vào `errors` (vẫn HTTP 200).

### 5.2. Import CSV eBay (Sold Orders)

- **Đường dẫn**: `POST /orders/import-csv`
- **Content-Type**: `multipart/form-data`
- **Form field**: `file` (`.csv` / text, tối đa 10MB)
- **Permission**: `orders.import`

File export từ eBay; parser bỏ dòng rỗng trước header, group theo `Order Number`, tạo/cập nhật:

- `orders` (+ `ebay_order_number`)
- `order_line_items`
- `order_fulfillment_addresses`
- `order_import_batches` / `order_import_batch_items`

**Cột bắt buộc** — cùng list trong `OrderImportService::REQUIRED_CSV_HEADERS`:

| Cột                     | Mô tả                              |
| ----------------------- | ---------------------------------- |
| `Order Number`          | Khóa nhóm đơn                      |
| `Sale Date`             | `M-d-y` (vd `Aug-02-26`)           |
| `Item Number`           | SKU/item eBay                      |
| `Quantity`              | Số nguyên ≥ 1                      |
| `Sold For`              | Tiền, vd `$10.00`                  |
| `Shipping And Handling` | Tiền                               |
| `Total Price`           | Tiền                               |
| `Ship To Name`          | Tên nhận hàng                      |
| `Ship To Address 1`     | Địa chỉ                            |
| `Ship To City`          | Thành phố                          |
| `Ship To Zip`           | ZIP                                |
| `Ship To Country`       | Country code                       |

**Cột tùy chọn:** `Transaction ID`, `Item Title`, `Custom Label`, `Variation Details`, `Ship To Phone`, `Ship To Address 2`, `Ship To State`.

**Response success (200):**

```json
{
    "status": "success",
    "message": "Import CSV hoàn tất",
    "data": {
        "batch_id": 1,
        "rows": 2,
        "orders": 1,
        "created": 1,
        "updated": 0,
        "failed": 0,
        "errors": []
    }
}
```

**Response validation failure (422):**

```json
{
    "status": "error",
    "message": "CSV thiếu cột Sale Date.",
    "data": null
}
```

> Validate toàn bộ file trước khi persist. Lỗi header/date/money → `422`, batch `failed`, không tạo order. Persist từng order trong transaction riêng (không phải một outer transaction cho cả file). Re-import cùng `Order Number` → `updated` (idempotent theo `Transaction ID` hoặc fallback identity).

### 5.3. Tải CSV mẫu

- **Đường dẫn**: `GET /orders/import/template`
- **Auth**: Bearer token (đăng nhập); không cần `orders.import`
- **Response**: file `ebay_order_import_template.csv` (`Content-Type: text/csv`) — header đủ cột bắt buộc + tùy chọn + 1 dòng ví dụ

---

## 6. Printify Catalog & Sync Foundation - Phase 7 (deferred / sau import)

> **Trạng thái:** foundation (catalog + sync commands) đã có trên codebase.  
> **Ưu tiên hiện tại:** hoàn thiện import eBay trước.  
> **Để sau:** lấy/sync order từ Printify về, link `printify_orders.order_id`, outbound `printify.order.create`, reconcile.

### 6.1. Permissions liên quan

| Permission                        | Dùng cho                            | Đã có route? |
| --------------------------------- | ----------------------------------- | ------------ |
| `printify.catalog.view`           | List shops / products               | ✅           |
| `printify.shop-readiness.confirm` | Confirm Manual approval trên shop   | ✅           |
| `printify.sync`                   | Reserved (Artisan/schedule)         | ❌ HTTP      |
| `printify.order.create`           | Preview/tạo đơn Printify            | ✅ preview   |
| `printify.reconcile`              | Đối soát (sau)                      | ❌           |

### 6.2. Env backend (không expose ra FE)

| Key                          | Ví dụ / mặc định              |
| ---------------------------- | ----------------------------- |
| `PRINTIFY_TOKEN`             | Personal Access Token         |
| `PRINTIFY_BASE_URL`          | `https://api.printify.com/v1` |
| `PRINTIFY_TIMEOUT`           | `15`                          |
| `PRINTIFY_RETRY_TIMES`       | `3`                           |
| `PRINTIFY_RETRY_SLEEP_MS`    | `500`                         |
| `PRINTIFY_SYNC_LOCK_SECONDS` | `900`                         |

### 6.3. Danh sách Shop

- **Đường dẫn**: `GET /printify/shops`
- **Permission**: `printify.catalog.view`

**Response:** `{ status, message, data }` với `data` là **paginator** (không phải mảng phẳng). Item gồm `id` (local), `printify_shop_id`, `title`, `is_active`, `orders_sync_state`, `orders_sync_completed_at`, `manual_approval_confirmed_at`, `synced_at`, `ready_for_creation`.

### 6.4. Xác nhận Manual Approval

- **Đường dẫn**: `POST /printify/shops/{shop}/confirm-manual-approval`
- **Permission**: `printify.shop-readiness.confirm`
- **Path param**: `shop` = local `printify_shops.id`

### 6.5. Danh sách Product (theo shop)

- **Đường dẫn**: `GET /printify/products?shop_id={localShopId}`
- **Permission**: `printify.catalog.view`
- Thiếu `shop_id` → `422`. `data` là paginator + `variants` khi load.

### 6.6. Dry-run Printify order payload (scaffold)

- **Đường dẫn**: `POST /orders/{order}/printify-preview`
- **Permission**: `printify.order.create`
- **Không gọi** Printify API — chỉ build payload.

**Body:**

```json
{
  "shop_id": 1,
  "line_mappings": [
    { "line_item_id": 10, "variant_id": 9991 }
  ]
}
```

`line_mappings` optional. Không có mapping thủ công → resolve theo `Custom Label` = `printify_product_variants.sku` trong shop. Ambiguous / missing SKU → `ready=false` + `errors`.

**Response `data`:** `ready`, `errors`, `line_mappings[]`, `payload` (null nếu chưa ready). `payload` khớp shape create-order Printify (`external_id`, `line_items`, `address_to`, …).

### 6.7. Sync jobs (Ops — để sau khi ưu tiên import xong)

| Artisan command | Mô tả |
| --- | --- |
| `php artisan printify:sync-shops` | Sync shops |
| `php artisan printify:sync-products {--shop-id=} {--limit-pages=}` | Sync products |
| `php artisan printify:sync-orders {--shop-id=} {--limit-pages=}` | Sync orders inbound |
| `php artisan printify:sync-uploads {--limit-pages=}` | Sync uploads |

`--shop-id` ở command = **remote** `printify_shop_id`.
