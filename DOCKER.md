# Chạy Linh Họa Sư bằng Docker

Yêu cầu Docker Desktop chạy Linux containers. Toàn bộ dịch vụ chạy trên máy local, không kết nối VM.

Trong PowerShell tại thư mục dự án:

```powershell
.\setup-docker.ps1
docker compose up -d --build
docker compose ps
```

Mở game: http://127.0.0.1:18081/ . Trang quản trị: http://127.0.0.1:18081/admin/ . Địa chỉ chỉ mở trên máy này.

```powershell
docker compose logs -f game dist web
docker compose stop
docker compose start
```

Sau khi sửa source Node.js, chạy `docker compose up -d --build`. Nếu vừa tạo lại container game, chạy `docker compose restart web` để Nginx cập nhật kết nối. Các tệp web được mount từ `www/wwwroot/game`, thay đổi sẽ có hiệu lực trực tiếp.

Lần đầu tạo volume, MongoDB tự nạp bản dump `docker/seed/mongodump`, MySQL nhập `mysql-cdk.sql`, Redis dùng `redis-dump.rdb`. Những lần chạy sau dùng dữ liệu đã lưu trong volume Docker; không tự ghi đè bằng bản sao lưu. `docker compose down` dừng và xóa container nhưng giữ volume. Không dùng `docker compose down -v` nếu cần giữ dữ liệu.

Các mật khẩu và khóa nội bộ dùng từ bản cấu hình cũ, nằm trong `docker/game.env`, `docker/php-config.php` và `data/lhs/LHS-dist/.env`; các tệp này cần giữ kín. Hệ thống được cấu hình cho local/development và không phù hợp đưa trực tiếp ra Internet. MySQL 5.7, PHP của dự án và một số thư viện Node đã cũ; `npm ci` báo có lỗ hổng phụ thuộc.

Cấu hình nguồn: game `data/lhs/newServer`, phân phối `data/lhs/LHS-dist`, quản trị `data/lhs/LHS-admin`, client `www/wwwroot/game`. PHP được cấu hình dùng MySQL trong mạng Docker; game truy cập MongoDB/Redis và các dịch vụ khác qua tên container. `docker/configMap,json` là bản sao cấu hình riêng cho container, có thể được trang quản trị sửa.

Đã kiểm tra các container, HTTP game/admin/API/PHP, kết nối PHP-MySQL và WebSocket; dữ liệu MongoDB đã khôi phục gồm 1 player và 1 agent. Công cụ trình duyệt tự động không khả dụng trong phiên này, do đó chưa xác nhận toàn bộ luồng đăng nhập và chơi game. Client là bản mirror và tài liệu gốc ghi có một số tài nguyên thiếu.

Trong môi trường local, đăng ký không yêu cầu mã mời. Có thể dùng trang http://127.0.0.1:18081/register.html; API và giao thức đăng ký/đăng nhập của game đã được kiểm tra. Nếu vừa thay đổi cấu hình service game, chạy `docker compose up -d game` rồi `docker compose restart web`.

## Thiết lập sau khi clone

Chạy `./setup-docker.ps1` trong PowerShell để tạo cấu hình local từ các file mẫu, sau đó chạy `docker compose up -d --build`. Lệnh không ghi đè cấu hình đã có. Bản clone khởi tạo MongoDB và Redis trống, MySQL chỉ có schema CDK; dữ liệu người dùng và khóa thật không được đưa lên Git. Để khôi phục dữ liệu riêng, đặt `mongodump/`, `mysql-cdk.sql` và `redis-dump.rdb` vào `docker/seed/` trước khi khởi tạo volume lần đầu. Các file đó nằm trong `.gitignore`.
