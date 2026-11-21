# Chạy Linh Họa Sư bằng Docker

> **Từ 2026-11-21:** MongoDB, Redis và MySQL không còn nằm trong `compose.yaml`. Chúng chạy chung trong stack `E:/platform-infra` (`pnpm infra:up` trong `E:/platform-infra`). Thông tin kết nối nằm ở `.env` của repo này, là bản sao của `E:/platform-infra/out/linhhoasu.env`. Database CDK đổi tên thành `lhs_cdks`. Khi cài mới trên database trống, chạy `docker compose --profile seed run --rm seed-mongo` và `docker compose --profile seed run --rm seed-mysql`. Các đoạn bên dưới nói về MongoDB/MySQL/Redis trong compose là mô tả cũ.

Yêu cầu Docker Desktop chạy Linux containers. Toàn bộ dịch vụ chạy trên máy local, không kết nối VM.

Trong PowerShell tại thư mục dự án:

```powershell
.\setup-docker.ps1
docker compose up -d --build
docker compose ps
```

Các service tự khởi động theo thứ tự (database → game/dist/admin → web) và có healthcheck; game và dist tự chờ MongoDB thêm tối đa khoảng 3 phút thay vì thoát, nên sau khi bật lại Docker không cần restart thủ công. Dùng `docker compose up -d --wait` để chờ tới khi mọi thứ sẵn sàng.

Mở game: http://127.0.0.1:18081/ . Trang quản trị: http://127.0.0.1:18081/admin/ . Địa chỉ chỉ mở trên máy này.

Tạo tài khoản tại http://127.0.0.1:18081/register.html; không cần mã mời. Sau khi đăng ký, trang quay về game và thử đăng nhập tự động.

```powershell
docker compose logs -f game dist web
docker compose stop
docker compose start
```

Sau khi sửa source Node.js, chạy `docker compose up -d --build`. Các tệp web được mount từ `www/wwwroot/game`, thay đổi sẽ có hiệu lực trực tiếp.

Lần đầu tạo volume, nếu có bản sao lưu riêng trong `docker/seed`, MongoDB nạp `mongodump`, MySQL nhập `mysql-cdk.sql`, Redis dùng `redis-dump.rdb`. Nếu không có, MongoDB tạo server 1001, MySQL tạo schema CDK trống và Redis khởi động trống. Không tạo sẵn tài khoản người chơi. Server tự nạp cấu hình game và tạo nhân vật khi đăng ký. Seed không ghi đè server đã tồn tại. Dữ liệu MongoDB nằm trực tiếp trong `docker/data/mongo` (không đưa lên Git); MySQL và Redis vẫn dùng volume Docker. Để khởi tạo lại MongoDB từ đầu, dừng `mongo` rồi xóa thư mục này. Những lần chạy sau dùng dữ liệu đã lưu. `docker compose down` dừng và xóa container nhưng giữ volume. Không dùng `docker compose down -v` nếu cần giữ dữ liệu.

Các mật khẩu và khóa nội bộ dùng từ bản cấu hình cũ, nằm trong `docker/game.env`, `docker/php-config.php` và `data/lhs/LHS-dist/.env`; các tệp này cần giữ kín. Hệ thống được cấu hình cho local/development và không phù hợp đưa trực tiếp ra Internet. MySQL 5.7, PHP của dự án và một số thư viện Node đã cũ; `npm ci` báo có lỗ hổng phụ thuộc.

Cấu hình nguồn: game `data/lhs/newServer`, phân phối `data/lhs/LHS-dist`, quản trị `data/lhs/LHS-admin`, client `www/wwwroot/game`. PHP được cấu hình dùng MySQL trong mạng Docker; game truy cập MongoDB/Redis và các dịch vụ khác qua tên container. `docker/configMap,json` là bản sao cấu hình riêng cho container, có thể được trang quản trị sửa.

Đã kiểm tra bản cài mới với server mặc định, đăng ký, đăng nhập, khởi tạo nhân vật và gửi yêu cầu WebSocket. Chưa kiểm thử toàn bộ gameplay qua giao diện trình duyệt; client là bản mirror và tài liệu gốc ghi có một số tài nguyên thiếu.

Trong môi trường local, đăng ký không yêu cầu mã mời. Có thể dùng trang http://127.0.0.1:18081/register.html; API và giao thức đăng ký/đăng nhập của game đã được kiểm tra. Nếu vừa thay đổi cấu hình service game, chạy `docker compose up -d game`; Nginx tự phân giải lại địa chỉ container nên không cần khởi động lại `web`.

## Thiết lập sau khi clone

Chạy `./setup-docker.ps1` trong PowerShell để tạo cấu hình local từ các file mẫu, sau đó chạy `docker compose up -d --build`. Lệnh không ghi đè cấu hình đã có. Bản clone tạo server game mặc định, Redis trống và schema CDK; dữ liệu người dùng và khóa thật không được đưa lên Git. Để khôi phục dữ liệu riêng, đặt `mongodump/`, `mysql-cdk.sql` và `redis-dump.rdb` vào `docker/seed/` trước khi khởi tạo volume lần đầu. Các file đó nằm trong `.gitignore`.

Để kiểm tra trên bản cài thử, chạy `docker compose exec -T game node scripts/test-clean-install.js`. Lệnh tạo một tài khoản thử trong database đang dùng; chỉ chạy ở môi trường thử nghiệm.
