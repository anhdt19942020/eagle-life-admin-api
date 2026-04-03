# 🦅 Eagle Life Admin - API Documentation (Phase 3)

Tài liệu này bao gồm các thông tin cho Frontend Engineer để tích hợp luồng Xác thực (Authentication) và Quản lý Phân quyền (Roles) vào giao diện.

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

### 4.1. Danh sách Đơn hàng

- **Đường dẫn**: `GET /orders`
- **Query Params (tùy chọn)**:

| Param       | Ý nghĩa                            | Ví dụ                  |
| ----------- | ---------------------------------- | ---------------------- |
| `search`    | Tìm theo mã, tên, SĐT, email khách | `search=Nguyễn`        |
| `status`    | Lọc theo trạng thái                | `status=pending`       |
| `sale_id`   | Lọc theo Sale phụ trách            | `sale_id=3`            |
| `from_date` | Từ ngày (yyyy-mm-dd)               | `from_date=2026-01-01` |
| `to_date`   | Đến ngày                           | `to_date=2026-12-31`   |
| `page`      | Trang                              | `page=1`               |
| `per_page`  | Số dòng/trang                      | `per_page=20`          |

**Status hợp lệ**: `pending` | `processing` | `completed` | `canceled`

### 4.2. Chi tiết Đơn hàng

- **Đường dẫn**: `GET /orders/{id}`

**Response (200 OK):**

```json
{
    "success": true,
    "message": "Lấy chi tiết đơn hàng thành công",
    "data": {
        "id": 1,
        "order_code": "DH000001",
        "customer_name": "Nguyễn Văn A",
        "customer_phone": "0909123456",
        "customer_email": "nva@email.com",
        "total_amount": "1500000.00",
        "status": "pending",
        "notes": null,
        "sale": {
            "id": 3,
            "name": "Trần Sale",
            "employee_code": "NV0001"
        },
        "created_at": "2026-04-04T00:00:00.000000Z"
    }
}
```

### 4.3. Cập nhật Đơn hàng

- **Đường dẫn**: `PUT /orders/{id}`
- **Body JSON** (chỉ các field cần cập nhật):

```json
{
    "status": "processing",
    "sale_id": 3,
    "notes": "Đã liên hệ khách"
}
```

### 4.4. Xoá Đơn hàng

- **Đường dẫn**: `DELETE /orders/{id}`

---

## 5. Import Đơn hàng CSV - Phase 6

### 5.1. Import CSV

- **Đường dẫn**: `POST /orders/import`
- **Content-Type**: `multipart/form-data`
- **Form field**: `file` (file `.csv`, tối đa 10MB)

**Cấu trúc CSV yêu cầu:**

| Cột              | Bắt buộc | Mô tả                            |
| ---------------- | -------- | -------------------------------- |
| `customer_name`  | ✅       | Tên khách hàng                   |
| `customer_phone` | ❌       | Số điện thoại                    |
| `customer_email` | ❌       | Email                            |
| `total_amount`   | ❌       | Giá trị đơn hàng                 |
| `status`         | ❌       | Trạng thái (mặc định: `pending`) |
| `sale_code`      | ❌       | Mã nhân viên (vd: `NV0001`)      |
| `notes`          | ❌       | Ghi chú                          |

**Response (200 OK):**

```json
{
    "success": true,
    "message": "Import hoàn tất: 150/152 thành công",
    "data": {
        "total": 152,
        "success": 150,
        "failed": 2,
        "errors": [
            "Dòng 5: Thiếu tên khách hàng",
            "Dòng 89: Thiếu tên khách hàng"
        ]
    }
}
```

### 5.2. Tải File CSV Mẫu

- **Đường dẫn**: `GET /orders/import/template`
- **Response**: Tự động download file `order_import_template.csv`
- _Không cần Bearer Token_
