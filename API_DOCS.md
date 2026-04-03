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

- Giao tiếp bằng Auth Bearer Token. Avatar chỉ gửi ID integer `1` đến `10`. Mã nhân viên được backend tự động khởi tạo.

### 3.1. Danh sách Nhân viên

- **Đường dẫn**: `GET /users`
- **Query Params**: `search={keyword}`, `status={0/1}`, `role={admin/sale/...}`, `page={1}`, `per_page={15}`
- **Response**: Trả về Paginated JSON.

### 3.2. Tạo Nhân viên

- **Đường dẫn**: `POST /users`
- **Yêu cầu Body JSON**:

```json
{
    "name": "Nguyễn Văn A",
    "email": "nva@eaglelife.com",
    "password": "strongPassword123",
    "phone": "0988123456",
    "avatar": 5,
    "roles": ["manager"]
}
```

_Lưu ý: Không gửi `employee_code` hay link `avatar`. `avatar` chỉ nhận integer từ 1 -> 10 đại diện cho 10 avatar icons mặc định trên FE._

### 3.3. Sửa Nhân viên

- **Đường dẫn**: `PUT /users/{id}`
- **Yêu cầu Body JSON tương tự Tạo** (Chỉ cập nhật những field được submit lên)

```json
{
    "name": "Nguyễn Văn A updated",
    "avatar": 2,
    "roles": ["manager", "admin"]
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

### 3.5. Xoá Nhân viên

- **Đường dẫn**: `DELETE /users/{id}`
