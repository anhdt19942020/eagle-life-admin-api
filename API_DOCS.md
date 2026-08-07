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

- Xác thực người dùng bằng **username** (không dùng email) và trả về Token cùng Role (người dùng mặc định để test: `admin` / `12345678`).

> **Breaking (FE):** request body đổi từ `email` → `username`. Email chỉ còn cột thông tin profile trên user.

* **Đường dẫn**: `POST /login`
* **Yêu cầu Xác thực**: Không (Public)

**Request Body:**

```json
{
    "username": "admin",
    "password": "12345678"
}
```

**Response (200 OK):**

```json
{
    "status": "success",
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
Sai mật khẩu / username không tồn tại, hoặc tài khoản bị vô hiệu hóa (`status = 0`). Envelope dùng `data` (không phải `errors`).

```json
{
    "status": "error",
    "message": "Tài khoản hoặc mật khẩu không chính xác.",
    "data": {
        "username": ["Tài khoản hoặc mật khẩu không chính xác."]
    }
}
```

Tài khoản bị khóa:

```json
{
    "status": "error",
    "message": "Tài khoản của bạn đã bị khóa.",
    "data": {
        "username": ["Tài khoản của bạn đã bị khóa."]
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

- Lấy danh sách role dùng cho dropdown phân quyền khi Admin tạo nhân sự.
- Roles hiện tại: `admin`, `seller`, `group_leader`.
- Yêu cầu permission: `roles.view` (chỉ admin được seed quyền này).

* **Đường dẫn**: `GET /roles`
* **Yêu cầu Xác thực**: Có (Bearer Token) + `roles.view`

**Response (200 OK):**

```json
{
    "status": "success",
    "message": "Lấy danh sách vai trò thành công",
    "data": [
        {
            "id": 1,
            "name": "admin",
            "permissions": [...]
        },
        {
            "id": 2,
            "name": "seller",
            "permissions": [...]
        },
        {
            "id": 3,
            "name": "group_leader",
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

1. **`422 Unprocessable Entity`**: Lỗi logic/dữ liệu hoặc Validate → đọc field errors trong `data` (object `{ field: [messages] }`), không phải key `errors`. Ví dụ: `data.username[0]`.
2. **`401 Unauthorized`**: Token sai/hết hạn. Frontend cần clear `localStorage` và redirect về trang `/login`.
3. **`403 Forbidden`**: User gọi tới API không có quyền hạn -> Báo lỗi "Bạn không có quyền..".
4. **`500 Internal Server Error`**: Lỗi Server, hiển thị alert Toast chung chung. Tốt nhất là báo Developer Backend hỗ trợ.

---

## 3. Quản lý Nhân sự (Users)

- Giao tiếp bằng Auth Bearer Token. Mã nhân viên được backend tự động khởi tạo.
- Permissions: `users.view` | `users.create` | `users.update` | `users.delete` (chỉ admin được seed).
- `seller` / `group_leader` **bắt buộc** `sales_group_id`. `admin` **không** gắn nhóm (backend clear `sales_group_id` nếu gửi kèm).

### 3.1. Danh sách Nhân viên

- **Đường dẫn**: `GET /users`
- **Query Params**: `search={keyword}`, `status={0/1}`, `role={admin|seller|group_leader}`, `sales_group_id={id}`, `page={1}`, `per_page={15}`
- **Response**: Paginated JSON; mỗi user kèm `roles` và `salesGroup`.

### 3.2. Tạo Nhân viên

- **Đường dẫn**: `POST /users`
- **Permission**: `users.create`
- **Yêu cầu Body JSON**:

```json
{
    "name": "Yến",
    "username": "yen",
    "password": "12345678",
    "role": "seller",
    "sales_group_id": 1,
    "email": "yen@gmail.com",
    "phone": "09796643194",
    "avatar": "https://api.dicebear.com/7.x/notionists/svg?seed=Bob&backgroundColor=c0aede"
}
```

_Lưu ý: `username` **bắt buộc** (dùng để login). Không gửi `employee_code` (hệ thống tự sinh). `email` là thông tin liên hệ, không dùng login. `role` ∈ `admin` | `seller` | `group_leader`. Seller/leader thiếu `sales_group_id` → 422._

### 3.3. Sửa Nhân viên

- **Đường dẫn**: `PUT /users/{id}` (hoặc `PATCH`)
- **Permission**: `users.update`

```json
{
    "name": "Yến Updated",
    "avatar": "https://api.dicebear.com/.../new",
    "role": "group_leader",
    "sales_group_id": 2
}
```

### 3.4. Khoá/Mở khoá Nhân viên

- **Đường dẫn**: `PATCH /users/{id}/status`
- **Permission**: `users.update`
- **Yêu cầu Body JSON**:

```json
{
    "status": 1
}
```

_(Lưu ý: 1 = Active, 0 = Banned)_

- **Đường dẫn**: `DELETE /users/{id}` — permission `users.delete` (không xoá được tài khoản `admin`)

---

## 3b. Nhóm bán hàng (Sales Groups)

Nhóm vận hành theo sàn (`ebay` | `tiktok` | `amazon`). Chỉ admin (permissions `sales-groups.*`).

### 3b.1. Danh sách

- **Đường dẫn**: `GET /sales-groups`
- **Query**: `platform`, `status`, `search`, `per_page`
- **Permission**: `sales-groups.view`

### 3b.2. Tạo

- **Đường dẫn**: `POST /sales-groups`
- **Permission**: `sales-groups.create`

```json
{
    "name": "eBay Team A",
    "platform": "ebay",
    "code": "EBAY-A",
    "status": true
}
```

### 3b.3. Chi tiết / Sửa / Xoá

- `GET /sales-groups/{id}` — `sales-groups.view` (kèm `users_count` + danh sách member)
- `PUT|PATCH /sales-groups/{id}` — `sales-groups.update`
- `DELETE /sales-groups/{id}` — `sales-groups.delete` (422 nếu còn thành viên)

---

## 4. Quản lý Đơn hàng (Orders) - Phase 5

> Tất cả endpoints đều yêu cầu `Authorization: Bearer <token>`. Domain là **đơn eBay**, không phải CRM `customer_*` / `status`.

### Dual-layer auth (FE + API)

| Lớp | Dùng cho | Cơ chế |
|-----|----------|--------|
| Role + row scope | Menu/list Orders, `GET/PUT` đơn trong phạm vi | Role `seller` / `group_leader` / `admin` + `scopeVisibleTo` — **không** cần permission để vào màn Orders |
| Spatie permission | Menu admin & hành động nhạy cảm | `users.*`, `sales-groups.*`, `printify.accounts.*`, `orders.delete`, `orders.import`, … — ẩn menu/nút theo `roles[].permissions[]` từ login/`/me`; API 403 nếu gọi thẳng |

**Gợi ý ẩn/hiện menu FE**

| Menu / action | Show khi |
|---------------|----------|
| Orders | Role `seller` / `group_leader` / `admin` |
| Xoá đơn (UI) | `orders.delete` |
| Users | `users.view` |
| Sales groups | `sales-groups.view` |
| Printify accounts | `printify.accounts.view` |
| Printify catalog/shops | `printify.catalog.view` |
| Import | `orders.import` |
| Printify create trên đơn | `printify.order.create` |

`GET`/`PUT` orders vẫn chỉ cần auth + visibility (chưa gắn `permission:orders.view` / `orders.update` trên route — intentional).

### Visibility (row-level)

| Role | Đơn được thấy |
|------|----------------|
| `admin` | Tất cả |
| `seller` | `seller_id = auth.id` |
| `group_leader` (có `sales_group_id`) | Đơn của seller cùng nhóm **hoặc** `seller_id = auth.id` |
| `group_leader` (null group) | Chỉ `seller_id = auth.id` |
| Không có role seller/leader/admin | Không thấy đơn nào |
| Đơn `seller_id = null` | **Chỉ admin** — CSV import gán `seller_id = user đang import` (create hoặc khi đang null; không ghi đè assignment đã có) |

Query filters (`seller_id`, `search`, …) **AND** với visibility — không dùng filter để mở rộng phạm vi.  
`GET/PUT/DELETE /orders/{id}` và Printify preview/create ngoài phạm vi → **404** (không lộ tồn tại). Non-admin **không** được đổi `seller_id` khi update (422).

Residual: import vẫn có thể ghi `seller_code` cross-group (chưa siết trong scope visibility/delete).

### 4.1. Danh sách Đơn hàng

- **Đường dẫn**: `GET /orders`
- **Query Params (tùy chọn)**:

| Param         | Ý nghĩa                                         | Ví dụ                    |
| ------------- | ----------------------------------------------- | ------------------------ |
| `search`      | Tìm theo `ebay_order_id` hoặc `printify_order_id` | `search=13-14975`      |
| `seller_id`   | Lọc seller (bị cắt bởi visibility)              | `seller_id=3`            |
| `buyer_id`    | Lọc buyer                                       | `buyer_id=5`             |
| `from_date`   | Từ ngày `ebay_created_at` (yyyy-mm-dd)          | `from_date=2026-01-01`   |
| `to_date`     | Đến ngày                                        | `to_date=2026-12-31`     |
| `no_printify` | `1`/`true` → chỉ đơn chưa có `printify_order_id` | `no_printify=1`         |
| `page`        | Trang                                           | `page=1`                 |
| `per_page`    | Số dòng/trang (mặc định 25)                     | `per_page=20`            |

**Response:** envelope `{ status, message, data }` với `data` là Laravel paginator (`data`, `links`, `meta`) **cộng** `seller_stats`. Mỗi item gồm `ebay_order_id`, `ebay_order_number`, `printify_order_id`, `ebay_created_at`, nested `buyer` / `seller` (`id`, `name`, `employee_code`) và `line_items` chỉ lấy `id`, `order_id`, `title` để hiển thị thông tin sản phẩm trong danh sách. Mỗi item cũng có sẵn `ebay_export_rows` (nguyên payload CSV) — FE dùng `My Item Note` / `Buyer Country` từ đây cho 2 cột trong bảng Mapping.

**`seller_stats`** — tổng số đơn theo từng seller trong phạm vi visibility + các filter hiện tại **trừ** `seller_id` (để FE vẫn hiện đủ option lọc khi đang chọn 1 seller). Sắp xếp `orders_count` giảm dần. Seller chưa gán → `seller_id: null`, `seller: null`.

```json
"seller_stats": [
    {
        "seller_id": 3,
        "orders_count": 12,
        "seller": { "id": 3, "name": "Alice", "employee_code": "NV0001" }
    },
    {
        "seller_id": null,
        "orders_count": 2,
        "seller": null
    }
]
```


### 4.2. Chi tiết Đơn hàng

- **Đường dẫn**: `GET /orders/{id}`
- Ngoài phạm vi visibility → `404`.

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
- **Permission**: `orders.delete` (seed: `admin`, `group_leader` — **không** cấp cho `seller`; thiếu quyền → **403**)
- **Hành vi**: **soft delete** — set `deleted_at` + `deleted_by` (user đang xoá), không hard-delete row. List/show/Printify mặc định **không** thấy đơn đã xoá (404). Ngoài visibility → 404 sau khi đã có permission.
- Line items / địa chỉ vẫn gắn order (không soft-delete riêng).

### 4.5. Thùng rác & khôi phục (admin)

- **Danh sách đã xoá**: `GET /orders?trashed=only` — **chỉ admin** (non-admin → 403). Item có `deleted_at`, `deleted_by`, relation `deletedBy`.
- **Khôi phục**: `POST /orders/{id}/restore` — **chỉ admin**; clear `deleted_at` + `deleted_by`. Non-admin → 403. Id không ở trash → 404.

**CSV re-import:** cùng `Order Number` với đơn soft-deleted → **revive** đúng `id` (không insert mới), đếm `updated`, clear `deleted_by`.

---

## 5. Import Đơn hàng eBay - Phase 6

> Yêu cầu `Authorization: Bearer <token>`.  
> `POST /orders/import` và `POST /orders/import-csv` cần permission `orders.import` (seed: `admin`, `group_leader`, `seller`).  
> **Ưu tiên hiện tại:** đưa đơn eBay vào hệ thống. Sync/lấy order từ Printify để bước sau.  
> **FE production:** import CSV eBay dùng `POST /orders/import-csv` (multipart), không parse rồi đẩy JSON vào `/orders/import`.

### 5.0. Chính sách trùng đơn / re-import (as implemented)

Hai endpoint **không** dùng chung một rule. Không giả định JSON và CSV xử lý trùng giống nhau.

| Endpoint | Khóa trùng | Khi gặp đơn đã có | Response khi trùng | HTTP |
|----------|------------|-------------------|--------------------|------|
| `POST /orders/import` | `ebay_order_id` | **Skip** item đó — không update | `failed++`, message trong `errors` (batch vẫn chạy tiếp) | `200` |
| `POST /orders/import-csv` | `Order Number` → `ebay_order_number` | **Upsert** order + line items | `created` / `updated` tăng tương ứng; batch item `outcome` = `created`\|`updated` | `200` |

**CSV line-item identity (idempotent):** ưu tiên `Transaction ID`; nếu trống thì fallback hash từ `Item Number` + `Custom Label` + `Variation Details` + `Sold For` (USD). Re-import cùng identity → cập nhật dòng đó, không nhân đôi.

**Verified by**
- JSON skip: `tests/Feature/EbayImportHttpTest.php` → `test_json_import_counts_duplicate_as_failed`
- CSV idempotent (không có Transaction ID): `tests/Feature/EbayCsvImportTest.php` → `test_fallback_line_items_are_idempotent_when_transaction_id_is_absent`
- CSV re-import (có Transaction ID): `tests/Feature/EbayCsvImportTest.php` → `test_reimport_with_transaction_identity_keeps_a_single_line_item`
- CSV re-import overwrite raw/buyer: `tests/Feature/EbayCsvImportTest.php` → `test_it_persists_full_csv_payload_and_buyer_fields`

**Evidence (code):** `OrderImportService::importFromArray`, `persistCsvOrder`, `upsertLineItem` / `lineItemKey` / `fallbackIdentity`.

**Operator notes (as implemented)**
- Re-export từ eBay Seller Hub → upload lại qua `POST /orders/import-csv` (FE production cũng dùng path này).
- `POST /orders/import` (JSON) chỉ **tạo mới**; trùng `ebay_order_id` bị skip, không refresh address/line/raw.
- Re-import CSV refresh: order metadata, buyer fields, `ebay_export_rows`, fulfillment address (`updateOrCreate`), và line items khớp identity (kèm `ebay_raw`).
- Re-import CSV **không prune** line item cũ: dòng có trong DB nhưng không còn trong file lần sau vẫn giữ nguyên (chỉ upsert các dòng có trong file).

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

> Trùng đơn: xem [§5.0](#50-chính-sách-trùng-đơn--re-import-as-implemented) — JSON **skip**, không update.

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

**Multi-item eBay:** dòng đầu có thể có Ship To nhưng trống `Item Number`; dòng tiếp theo có `Item Number` nhưng trống Ship To. Parser inherit Ship To/buyer trong cùng `Order Number`, bỏ dòng summary không có `Item Number`, và default `Shipping And Handling` / `Total Price` trống trên continuation.

**Seller attribution:** `seller_id` = user đang import khi tạo mới hoặc khi đơn đang `seller_id = null`. Re-import **không** ghi đè seller đã gán.

**Soft-delete revive:** nếu `ebay_order_number` trùng đơn đang soft-deleted → restore cùng `id`, clear `deleted_by`, rồi upsert như updated (không tạo row mới).

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
Nếu `Custom Label` trống → để `null`. Khi preview/create Printify: resolve `Custom Label` = `printify_product_variants.sku` trong shop đã chọn; nếu trống/không khớp → fallback `printify_shops.default_sku` của shop đó (set qua UI/API). Ambiguous SKU → `ready=false` + `errors`.

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

> Validate toàn bộ file trước khi persist. Lỗi header/date/money → `422`, batch `failed`, không tạo order. Persist từng order trong transaction riêng (không phải một outer transaction cho cả file). Trùng / re-import: xem [§5.0](#50-chính-sách-trùng-đơn--re-import-as-implemented) — CSV **upsert**.

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
| `printify.sync`                   | Sync shops (HTTP + Artisan)         | ✅ `POST /printify/shops/sync` |
| `printify.order.create`           | Preview/tạo đơn Printify            | ✅ preview + create |
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
- **Query**: `active_only` (default `true` nếu không gửi), `open_only` (optional). Quản lý shop: mặc định chỉ `is_active`. Picker tạo đơn: `active_only=1&open_only=1`.
- **Sort**: shop **đã gán user** trước, rồi `title` A→Z trong từng nhóm.

**Response:** `{ status, message, data }` với `data` là **paginator**. Item gồm `id` (local), `printify_shop_id`, `title`, `default_sku`, `is_active`, `is_open`, `open_state_changed_at`, `orders_sync_state`, `orders_sync_completed_at`, `manual_approval_confirmed_at`, `synced_at`, `ready_for_creation`.

`is_active` = còn trên Printify (sync). `is_open` = admin cho phép chọn khi tạo đơn (độc lập với sync). `default_sku` = SKU mặc định khi tạo đơn trên shop này (local; sync không ghi đè).

`ready_for_creation` = `is_active` + account active + `is_open` + **có `default_sku`** + manual approval + không conflict order. `orders_sync_state` vẫn trả về để theo dõi đồng bộ nhưng **không** chặn tạo đơn nháp. Thiếu default → không ready; picker tạo đơn chỉ hiện shop ready.

Mỗi item còn có `readiness_issues`: mảng mã blocker ổn định (`shop_inactive`, `account_inactive`, `shop_closed`, `missing_default_sku`, `manual_approval_required`, `order_conflicts`). UI quản lý shop dùng field này để tô trạng thái và hiển thị lý do chưa sẵn sàng.

### 6.3.0. Sync 1 sản phẩm mặc định (per shop)

- **Đường dẫn**: `POST /printify/shops/{shop}/ensure-default-sku`
- **Permission**: `printify.shop-readiness.confirm`
- **Path param**: `shop` = local `printify_shops.id`
- **Body** (optional): `{ "force": true|false }` — mặc định `false`.
- Không `force`: dùng variant enabled unique đã sync local nếu có; nếu không, gọi Printify sync tối đa **1 product** rồi gán `default_sku` khi tìm được đúng 1 SKU enabled unique. **Không** ghi đè `default_sku` đã có (`code=default_sku_already_set`).
- `force=true`: luôn sync lại tối đa **1 product** từ Printify, ưu tiên SKU enabled unique của product vừa sync, rồi **ghi đè** `default_sku` (`code=default_sku_refreshed`). Dùng khi product/SKU cũ đã bị xóa trên Printify.
- **Không** tự mở shop, confirm manual approval, hoàn tất orders sync, hay xóa conflict.
- Shop/account inactive → `422` (`printify_shop_not_ready` / `printify_account_inactive`). Không unique SKU sau sync → `422` `default_sku_not_resolved`. Lỗi Printify bất ngờ → `502` `default_sku_sync_failed` (chi tiết chỉ ở log).
- **Success `data`:** `{ result: { code, status, sku, reason }, shop: PrintifyShopResource }` với `code` ∈ `default_sku_set` | `default_sku_already_set` | `default_sku_refreshed`.

### 6.3.1. Cập nhật default SKU của shop

- **Đường dẫn**: `PATCH /printify/shops/{shop}`
- **Permission**: `printify.shop-readiness.confirm`
- **Body:** `{ "default_sku": "..." | null }` — null/empty xóa default. Non-empty phải khớp đúng 1 enabled variant đã sync của shop; 0 hoặc >1 → `422`.
- Sync shops **không** ghi đè `default_sku`.

### 6.3.2. Mở / đóng shop (selectable)

- **Đường dẫn**: `POST /printify/shops/{shop}/open` · `POST /printify/shops/{shop}/close`
- **Permission**: `printify.shop-readiness.confirm`
- **Path param**: `shop` = local `printify_shops.id`
- Sync shops **không** ghi đè `is_open`.

### 6.3.3. Sync shops từ Printify (manual)

- **Đường dẫn**: `POST /printify/shops/sync`
- **Permission**: `printify.sync`
- **Body**: `{ "account_id": number }` (bắt buộc; account phải `is_active`)
- Enqueue `SyncPrintifyShopsJob` (chạy sau HTTP response). Job gọi Printify `GET /shops.json`, **upsert** theo `printify_shop_id` (unique) — không tạo bản ghi trùng. Shop không còn trên Printify → `is_active=false`. Giữ `is_open` local.
- Sau khi shop sync **thành công**, hệ thống enqueue `EnsurePrintifyAccountDefaultSkusJob` cho cùng `account_id`: với mỗi shop active **thiếu** `default_sku`, seed tối đa **1 product** rồi gán `default_sku` nếu có đúng 1 unique enabled SKU. **Không** ghi đè `default_sku` đã có; SKU ambiguous → bỏ qua (admin set tay). Sync shops **không** kéo full catalog.
- **Response `data`:** `{ queued: true, account_id: number }` — message báo đang đồng bộ (có thể mất vài phút).
- **Tạo account** (`POST /printify/accounts`) cũng enqueue cùng shop-sync job (và do đó ensure default SKU sau sync) để shop sẵn sàng gán user.

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
- Shop **đóng** (`is_open=false`) → `422` `Printify shop is closed for order creation.`

**Body:**

```json
{
  "shop_id": 1,
  "line_mappings": [
    { "line_item_id": 10, "variant_id": 9991 }
  ]
}
```

`line_mappings` optional. Không có mapping thủ công → resolve theo `Custom Label` = `printify_product_variants.sku` trong shop đã chọn; nếu trống/không khớp → fallback `shop.default_sku`. Ambiguous SKU → `ready=false` + `errors`.

**Response `data`:** `ready`, `errors`, `line_mappings[]`, `payload` (null nếu chưa ready). `payload` khớp shape create-order Printify (`external_id`, `line_items`, `address_to`, …).

> `line_items[].product_id` và `line_mappings[].printify_product_id` là **string** Printify (thường hex ObjectId, vd `5bfd0b66a342bcc9b5563216`). Không được cast sang integer — PHP `(int)` sẽ cắt hex thành số ngắn và Printify reject. `variant_id` / `printify_variant_id` vẫn là integer.

### 6.7. Create Printify order (outbound)

- **Đường dẫn**: `POST /orders/{order}/printify-create`
- **Permission**: `printify.order.create`
- **Body:** giống `printify-preview` (`shop_id` bắt buộc, `line_mappings` optional). Shop phải `is_open` và ready.

Luồng (as implemented):

1. Nếu đã có `printify_orders` cùng `printify_shop_id` + `ebay_order_number` → **không** gọi Printify; `created=false`, trả bản ghi hiện có.
2. Build payload qua cùng logic preview; shop đóng / chưa `ready` → `422`.
3. `POST /shops/{remoteShopId}/orders.json` với payload; lưu `printify_orders` (`intent_state=created`) và gắn `orders.printify_order_id`.

**Response `data`:** `created` (bool), `printify_order`, `remote` (JSON Printify hoặc `[]` nếu đã tồn tại), `preview` (object preview hoặc `[]`).

### 6.8. Sync jobs (Ops — để sau khi ưu tiên import xong)

| Artisan command | Mô tả |
| --- | --- |
| `php artisan printify:sync-shops` | Sync shops (mỗi account thành công → queue ensure default SKU) |
| `php artisan printify:sync-products {--shop-id=} {--product-id=} {--limit-pages=} {--max-products=}` | Sync products. Prefer `--product-id=` (1 product) hoặc `--max-products=1` khi chỉ cần seed default SKU — tránh full catalog shop lớn. **Không** chạy hourly (đã tắt schedule). |
| `php artisan printify:ensure-default-sku {--shop-id=} {--open-only} {--seed-product} {--dry-run}` | Backfill thủ công `default_sku` từ unique enabled variant; optional seed 1 product. Post-sync job đã auto-seed; command vẫn dùng cho ops/dry-run. |
| `php artisan printify:sync-orders {--shop-id=} {--limit-pages=}` | Sync orders inbound |
| `php artisan printify:sync-uploads {--limit-pages=}` | Sync uploads |

`--shop-id` ở command = **remote** `printify_shop_id`.
