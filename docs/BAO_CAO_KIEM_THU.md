# Báo cáo kiểm thử — Hệ thống Quản lý Học phí Sinh viên

| | |
| --- | --- |
| Ngày kiểm thử | 14/11/2026 |
| Phiên bản mã nguồn | Nhánh `main` của repo `Inf-Entity/academic-project-1` (tải ngày 14/11/2026) |
| Môi trường | Windows 11 Pro, XAMPP: Apache 2.4, PHP 8.5.12 (CLI + mod_php), MariaDB 10.11.16, Chrome headless |
| Database kiểm thử | `tuition_test` (reset và nạp lại `schema.sql` + `seed.sql` + `seed_users.php` trước mỗi lần chạy) |

## 1. Tóm tắt

| Hạng mục | Công cụ | Kết quả |
| --- | --- | --- |
| Chức năng — backend | `tests/run.php` | **341/341 PASS** (32 nhóm, ~30 giây) |
| Chức năng — frontend | `tests/tests.js` (`?test=1`) | **30/30 PASS** |
| Hiệu năng | `tests/perf.php` | **0 lỗi**; p95 ≤ 45 ms mọi endpoint; ~900 request/giây ở 25 client đồng thời |
| Bảo mật | `tests/security_scan.php` + đọc mã nguồn | **53 PASS, 1 FAIL, 10 WARN** — 1 lỗi mức Cao (cấu hình), 3 lỗi mức Trung bình |

> **Cập nhật:** các lỗi bên dưới đã được sửa trong cùng ngày; kết quả chạy lại ở [mục 6](#6-cập-nhật-sau-đợt-sửa-lỗi-14112026) (bảo mật: 63 PASS / 0 FAIL / 2 WARN).

**Kết luận (trước khi sửa):** Chức năng nghiệp vụ hoạt động đúng theo đặc tả và hiệu năng dư sức cho quy mô một trường. Hệ thống **chưa sẵn sàng triển khai thật** cho đến khi xử lý các mục bảo mật ở [mục 4.2](#42-các-phát-hiện), đặc biệt là tắt `simulation_enabled`. Ngoài ra, tệp `database/database.sql` đã lỗi thời so với mã nguồn ([mục 5](#5-vấn-đề-khác-phát-hiện-trong-quá-trình-kiểm-thử)).

## 2. Kiểm thử chức năng

### 2.1 Backend — `tests/run.php`

Bộ test tích hợp gọi API thật qua Apache (`http://localhost/tuition-system/api`) và kiểm tra trực tiếp trên CSDL.

| # | Nhóm kiểm thử | Số test | Kết quả |
| ---: | --- | ---: | :---: |
| 0 | Khởi tạo & nạp dữ liệu sạch vào `tuition_test` | 3 | PASS |
| 1 | Tính học phí, miễn giảm & làm tròn | 10 | PASS |
| 2 | Từ chối nộp thừa & bảo toàn dữ liệu | 5 | PASS |
| 3 | Trạng thái thanh toán tại các ngưỡng biên | 5 | PASS |
| 4 | Bất biến của rate snapshot khi sửa định mức | 5 | PASS |
| 5 | Quy trình hoàn tiền & kiểm soát nộp thừa | 6 | PASS |
| 6 | Bút toán đảo mục | 3 | PASS |
| 7 | Idempotency với `request_id` | 3 | PASS |
| 8 | Đánh số biên lai liên tục, reset theo ngày | 3 | PASS |
| 9 | Thanh toán đồng thời (2 tiến trình PHP) | 1 | PASS |
| 10 | Tìm kiếm tiếng Việt không dấu & hoa thường | 5 | PASS |
| 11 | Ràng buộc xóa sinh viên | 3 | PASS |
| 12 | Đối soát báo cáo tài chính | 3 | PASS |
| 13 | An ninh phiên & CSRF | 7 | PASS |
| 14 | Xác thực dữ liệu đầu vào & chuẩn hóa tiền tệ | 11 | PASS |
| 15 | Xác thực ngày thanh toán | 2 | PASS |
| 16 | Hồ sơ trùng lặp, kiểm toán hoàn tiền, xử lý tiền thừa | 26 | PASS |
| 17 | Kiểm tra các lỗi đã khắc phục (encoding, hoàn tiền, trạng thái thừa) | 6 | PASS |
| 18 | Validation & bản đồ lỗi theo trường | 35 | PASS |
| 19 | Dữ liệu sinh viên mở rộng | 13 | PASS |
| 20 | Notification badges & trạng thái điều hướng | 15 | PASS |
| 21 | Trạng thái chờ duyệt & maker-checker | 13 | PASS |
| 22 | Phân quyền, cấp tài khoản & cô lập dữ liệu sinh viên | 43 | PASS |
| 23 | Khắc phục 3 lỗi hệ thống | 15 | PASS |
| 24 | Đăng nhập & báo cáo thống kê (mở rộng) | 11 | PASS |
| 25 | Thống kê trạng thái thanh toán | 12 | PASS |
| 26 | Truy cập menu thanh toán & quyền sinh viên | 9 | PASS |
| 27 | 3 cột số tiền báo cáo (phải thu, đã thu, còn nợ) | 7 | PASS |
| 28 | Thanh toán sinh viên: Mã QR & thẻ Visa/Mastercard mô phỏng | 26 | PASS |
| 29 | Tìm kiếm sinh viên & học kỳ khi tạo hồ sơ | 11 | PASS |
| 30 | Hóa đơn thanh toán học phí | 9 | PASS |
| 31 | Quản lý phiên & đăng xuất khi đóng tab | 9 | PASS |
| 32 | Dữ liệu mẫu mới & tình huống thực tế | 6 | PASS |
| | **Tổng** | **341** | **341 PASS, 0 FAIL** |

### 2.2 Frontend — `tests/tests.js`

Chạy trang `http://localhost/tuition-system/?test=1` bằng Chrome headless và đọc banner kết quả trong DOM: **"Unit Tests: 30/30 Passed"**. Các test bao phủ thư viện `calc.js`: tính học phí gốc, miễn giảm phần trăm/cố định, giới hạn miễn giảm, làm tròn, định dạng tiền và chuyển số thành chữ tiếng Việt.

### 2.3 Giới hạn của phần kiểm thử chức năng

- Chưa có test tự động cho giao diện (click, modal, in ấn, responsive 360px, multi-tab). Các mục này vẫn dựa vào checklist thủ công trong README và **không được thực hiện** trong đợt này.
- Test frontend chỉ kiểm tra `calc.js`. Các file `main.js` (217 KB) và `api.js` chưa có unit test.

## 3. Kiểm thử hiệu năng — `tests/perf.php`

Phương pháp: máy chủ và client chạy trên cùng một máy. Mỗi endpoint được gọi tuần tự 50 lần; sau đó tải đồng thời bằng `curl_multi`, mỗi client dùng một phiên admin riêng và gọi ngẫu nhiên 5 endpoint đọc (`students`, `fee-records`, `history`, `reports/summary`, `me`), 20 request mỗi client.

### 3.1 Độ trễ từng endpoint (ms)

| Endpoint | Trung bình | p50 | p95 | Max |
| --- | ---: | ---: | ---: | ---: |
| `POST /api/login` (10 lần) | 185.9 | 183.1 | 200.9 | 200.9 |
| `GET /` (trang SPA) | 6.6 | 1.0 | 23.5 | 25.4 |
| `GET /api/me` | 19.0 | 14.6 | 30.6 | 39.7 |
| `GET /api/students` | 26.3 | 28.8 | 42.2 | 45.5 |
| `GET /api/students/search?q=nguyen` | 21.0 | 15.5 | 30.9 | 31.4 |
| `GET /api/semesters` | 16.4 | 14.4 | 31.0 | 41.3 |
| `GET /api/rates` | 18.6 | 14.8 | 30.9 | 42.6 |
| `GET /api/fee-records` | 20.4 | 15.0 | 30.5 | 30.7 |
| `GET /api/history` | 19.4 | 15.3 | 30.2 | 30.9 |
| `GET /api/refunds` | 17.6 | 14.7 | 30.2 | 30.5 |
| `GET /api/reports/summary` | 16.6 | 14.6 | 30.0 | 31.1 |
| `GET /api/nav/badges` | 19.5 | 24.3 | 30.4 | 31.2 |
| `GET /api/receipts/{số biên lai}` | 20.4 | 19.5 | 30.3 | 31.4 |
| `GET /api/student/semesters` (sv002) | 23.3 | 27.8 | 31.2 | 45.5 |
| `GET /api/student/debts` (sv002) | 18.9 | 14.6 | 30.3 | 30.5 |
| `GET /api/student/history` (sv002) | 20.5 | 16.2 | 30.7 | 31.9 |

### 3.2 Tải đồng thời

| Client đồng thời | Tổng request | Thời gian | Request/giây | p50 (ms) | p95 (ms) | Lỗi |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 20 | 0.35 s | 56.7 | 14.9 | 27.3 | 0 |
| 5 | 100 | 0.56 s | 180.1 | 15.4 | 29.1 | 0 |
| 10 | 200 | 0.47 s | 422.3 | 15.3 | 29.8 | 0 |
| 25 | 500 | 0.56 s | 900.8 | 10.9 | 33.2 | 0 |

10 request song song trên **cùng một phiên** hoàn tất trong 154 ms, không bị xếp hàng tuần tự đáng kể.

### 3.3 Nhận xét

- Thông lượng tăng gần tuyến tính đến 25 client trong khi p95 gần như không đổi (~30 ms), tức là chưa chạm ngưỡng nghẽn.
- Đăng nhập mất ~185 ms do bcrypt cost 12. Đây là chi phí bảo mật có chủ đích. *(Sau đó đã hạ xuống cost 11 để đạt < 200 ms, xem mục 6.4.)*
- Độ trễ có dạng bậc thang ~15 ms (p50 ≈ 15, p95 ≈ 30). Nhiều khả năng đây là độ phân giải bộ định thời của Windows, không phải chi phí xử lý thật.
- **Giới hạn:** dữ liệu mẫu chỉ có 24 sinh viên và 26 giao dịch; chưa test ghi đồng thời quy mô lớn (tính đúng đắn khi ghi đồng thời đã được nhóm 9 của `run.php` kiểm tra). Nên lặp lại với dữ liệu sinh ngẫu nhiên khoảng 10.000 sinh viên trước khi kết luận về quy mô lớn, nhất là với `/api/students` và `/api/history` (hai endpoint có xu hướng chậm nhất).

## 4. Kiểm thử bảo mật

### 4.1 Phạm vi đã kiểm tra (đạt)

`tests/security_scan.php` thực hiện 64 kiểm tra; ngoài ra `run.php` đã có sẵn các nhóm 13, 22, 31 về bảo mật. Các mục **đạt**:

- **Chặn tệp nhạy cảm:** 17/17 đường dẫn (`config/`, `database/*.sql`, `logs/`, `api/lib`, `api/handlers`, `backend/*`, `tests/*.php`, `README.md`, `.htaccess`) trả về 403; không liệt kê thư mục.
- **Headers:** có `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy`, `Referrer-Policy`, áp dụng cho cả API.
- **Cookie phiên:** `HttpOnly`, `SameSite=Strict`, cookie phiên (không `Expires`); idle timeout 30 phút, tối đa 8 giờ (đã test trong nhóm 31).
- **Xác thực/phân quyền:** 7 endpoint trả 401 khi chưa đăng nhập; sinh viên gọi API quản trị nhận 403, quản trị gọi API sinh viên nhận 403; sinh viên A truy cập dữ liệu của sinh viên B nhận 404.
- **CSRF:** POST thiếu token hoặc token giả đều nhận 403.
- **SQL Injection:** 6 payload (`' OR 1=1`, `UNION SELECT`, `SLEEP(3)`...) vào ô tìm kiếm và đăng nhập không lộ dữ liệu, không lỗi 500, không bị trễ. Toàn bộ truy vấn dùng PDO prepared statements với `ATTR_EMULATE_PREPARES = false`.
- **Mass assignment:** trường lạ trong body (ví dụ `role`) bị từ chối với 400.
- **Lộ lỗi:** lỗi 500 chỉ trả thông báo chung, không có stack trace, SQL hay đường dẫn.
- **Brute-force:** khóa tài khoản 15 phút sau 5 lần sai (nhóm 13).
- **Thông tin thẻ:** số thẻ/CVV không được lưu vào DB, log hay phản hồi (nhóm 28, 30).

### 4.2 Các phát hiện

| ID | Mức | Phát hiện | Bằng chứng | Đề xuất sửa |
| --- | --- | --- | --- | --- |
| S-01 | **Cao** | `simulation_enabled = true`: sinh viên gọi `POST /api/student/payment-sessions/{id}/simulate-confirm` để tự đánh dấu đã thanh toán QR mà không chuyển tiền. | `config/config.php`; `api/handlers/student.php:578` | Đặt `'simulation_enabled' => false` khi chạy thật; nên để mặc định `false` trong `config.example.php`. |
| S-02 | Trung bình | **Dò tài khoản qua thời gian phản hồi.** Đăng nhập với username có thật chậm hơn username không tồn tại ~120 ms, vì `dummyHash` dùng bcrypt **cost 10** còn mật khẩu thật dùng **cost 12**. Đây là mục FAIL duy nhất. | `api/lib/auth.php:125`, `backend/models/User.php:89`; scan đo được chênh 116–124 ms | Thay `dummyHash` bằng hash cost 12, ví dụ `password_hash('dummy', PASSWORD_BCRYPT, ['cost' => 12])`, hoặc dùng cùng tham số với `seed_users.php`. |
| S-03 | Trung bình | **Nới rate limit bằng header.** Client ở bất kỳ IP nào gửi `X-Tuition-Test-Db: tuition_test` được tăng giới hạn đăng nhập từ 30 lên 500 request/phút. Khóa tài khoản sau 5 lần sai vẫn còn hiệu lực, nhưng việc dò mật khẩu rải trên nhiều tài khoản trở nên dễ hơn. | `api/lib/rate_limit.php:10-13` (không kiểm tra `REMOTE_ADDR`, khác với `db.php`) | Chỉ chấp nhận header test khi `REMOTE_ADDR` là loopback **và** có biến môi trường bật chế độ test; tốt nhất tách cờ test ra khỏi header client. |
| S-04 | Trung bình | **Bí mật nằm trong repo công khai:** `config/config.php` (mật khẩu DB `tuition_user`), mật khẩu mẫu trong README, `seed_users.php`, `create_app_user.sql`. `.gitignore` không loại trừ `config/config.php` dù README ghi "không đưa lên Git". | `.gitignore` | Thêm `config/config.php` vào `.gitignore`, xóa khỏi Git (`git rm --cached`), đổi mật khẩu DB khi triển khai và chỉ commit `config.example.php`. |
| S-05 | Thấp | Gửi `X-Tuition-Test-Db` (giá trị bất kỳ) kèm `X-Test-Force-Exception` từ bất kỳ IP nào đều ép được lỗi 500 và ghi log. | `api/index.php:49-51` | Xóa đoạn này khỏi bản triển khai hoặc áp cùng điều kiện loopback + cờ môi trường như S-03. |
| S-06 | Thấp | CSP cho phép `'unsafe-inline'` trong `script-src`, làm giảm hiệu quả chống XSS. Frontend đã có hàm escape HTML nên rủi ro hiện tại thấp. | `.htaccess` | Chuyển script inline và `onclick` sang file `.js`, bỏ `'unsafe-inline'`. |
| S-07 | Thấp | Header `X-Powered-By` lộ phiên bản PHP. | Response headers | Đặt `expose_php = Off` trong `php.ini`. |
| S-08 | Thấp | Thư mục `src/` (bản sao cũ của frontend) và `.gitignore` truy cập được qua web; `backend/api/users.php` là cổng đăng nhập thứ hai song song với `/api/login`. Cổng này có đủ rate limit và khóa tài khoản, nhưng vẫn làm tăng bề mặt tấn công. | `GET /src/index.html` → 200, `GET /.gitignore` → 200 | Xóa `src/` và `backend/` nếu không dùng, hoặc chặn bằng `.htaccess`; chặn các tệp bắt đầu bằng dấu chấm. |
| S-09 | Thông tin | Cookie phiên chưa có cờ `Secure` vì XAMPP chạy HTTP. Mã nguồn đã tự bật `Secure` khi phát hiện HTTPS. | Set-Cookie header | Bật HTTPS khi triển khai. |
| S-10 | Thông tin | Phản hồi khi tài khoản bị khóa (409 `ACCOUNT_LOCKED`) khác với khi sai mật khẩu (401), nên vẫn xác nhận được tài khoản tồn tại. Kẻ tấn công cũng có thể cố ý khóa tài khoản `admin` bằng 5 lần đăng nhập sai. | `api/lib/auth.php` | Cân nhắc trả cùng thông báo; thêm CAPTCHA hoặc độ trễ tăng dần thay vì khóa cứng. |

### 4.3 Giới hạn của phần kiểm thử bảo mật

Đây là quét tự động kết hợp đọc mã các phần trọng yếu (đăng nhập, phiên, CSRF, rate limit, DB, router), **không phải** pentest đầy đủ. Chưa kiểm tra: XSS lưu trữ qua từng trường hiển thị trong `main.js`, IDOR trên toàn bộ endpoint quản trị, upload tệp (hệ thống không có chức năng này), và cấu hình Apache/PHP ngoài dự án.

## 5. Vấn đề khác phát hiện trong quá trình kiểm thử

| ID | Mức | Vấn đề | Đề xuất | Trạng thái |
| --- | --- | --- | --- | --- |
| D-01 | **Cao** | `database/database.sql` (tệp "hợp nhất" mà README cũ hướng dẫn dùng) **lỗi thời**: thiếu bảng `bills`, `bill_counters`, `payment_sessions`, chỉ có 12 sinh viên thay vì 24. Ai cài đặt theo tệp này sẽ gặp lỗi ở các chức năng thanh toán QR/thẻ và hóa đơn. | Tạo lại `database.sql` từ `schema.sql` + `create_app_user.sql` + `seed.sql`, hoặc xóa tệp. README đã được sửa để hướng dẫn dùng 3 tệp riêng. | ✅ **Đã sửa:** sinh lại từ 3 tệp gốc (15 bảng, 24 sinh viên, đã import thử), thêm `database/build_database_sql.ps1` để sinh lại khi sửa schema/seed. |
| D-02 | Trung bình | README có lỗi hướng dẫn: lệnh `C:\xampp\php\php.exe php database/seed_users.php` (thừa chữ `php`); "3 bước" nhưng có 4 bước; số liệu seed mâu thuẫn (13 hay 24 sinh viên); cây thư mục thiếu `src/`, `qrcode.js`, `payment.php`; lệnh `mysql` giả định có trong PATH. | Sửa README. | ✅ **Đã sửa** trong README. |
| D-03 | Thấp | Mã nguồn trùng lặp: `src/` giống `frontend/`, `src/js/app.js` giống `main.js`, `src/tests/tests.js` giống `tests/tests.js`, `assets/screenshots/` giống `frontend/assets/images/`. | Xóa bản thừa để tránh sửa nhầm chỗ. | ✅ **Đã sửa:** xóa `src/`, `backend/` (cổng đăng nhập cũ không dùng) và `frontend/assets/images/`; giữ `assets/screenshots/`. |

## 6. Cập nhật sau đợt sửa lỗi (14/11/2026)

### 6.1 Các lỗi đã sửa

| ID | Cách sửa | File |
| --- | --- | --- |
| S-01 | Ngoài cờ `simulation_enabled`, đã đóng thêm **2 lỗ hổng mới phát hiện khi sửa**: (a) header `X-Simulation-Enabled: 1` từ **bất kỳ client nào** bật lại được chế độ mô phỏng dù config đã tắt; (b) endpoint cũ `POST /api/student/fee-records/{id}/payments` và thanh toán thẻ đi qua cổng giả luôn thành công nên **không bị chặn bởi cờ này**. Nay cả 3 đường (QR simulate-confirm, thẻ, endpoint cũ) đều qua `require_simulation_enabled()`; thiếu khóa trong config thì mặc định **tắt**. Đã kiểm chứng: khi tắt, cả 3 trả 404. | `api/lib/payment.php`, `api/handlers/student.php` |
| S-02 | Hash giả và mọi mật khẩu dùng chung một cost bcrypt (`hash_password()`, `DUMMY_PASSWORD_HASH`), không còn phụ thuộc phiên bản PHP. Sau đó hạ từ cost 12 xuống **11** để đăng nhập < 200 ms (mục 6.4); hash cũ tự băm lại khi đăng nhập. | `api/lib/config.php`, `api/lib/auth.php`, `students.php`, `student.php`, `seed_users.php` |
| S-03, S-05 | Gom mọi "cửa test" về `is_test_request()`: chỉ nhận header test khi request đến từ localhost. Áp dụng cho chọn DB test, nới rate limit, ép lỗi 500, ép lỗi cổng thanh toán, bật/tắt mô phỏng. | `api/lib/config.php`, `db.php`, `rate_limit.php`, `payment_gateway.php`, `api/index.php` |
| S-04 | `config/config.php` đã vào `.gitignore`; thiếu file thì backend dùng `config.example.php`. **Còn phải** `git rm --cached` và đổi mật khẩu vì file đã có trên GitHub. | `.gitignore` |
| S-06 | Bỏ 2 khối `<script>` inline trong `index.html` → CSP `script-src 'self'` (không còn `'unsafe-inline'`). | `frontend/index.html`, `.htaccess` |
| S-07 | `Header unset X-Powered-By`. | `.htaccess` |
| S-08 | Xóa `src/` (bản sao giống hệt `frontend/`) và `backend/` (cổng đăng nhập thứ 2 không ai dùng); chặn mọi tệp ẩn (`.gitignore`, `.git/`...). | `.htaccess` |
| D-01 | Sinh lại `database.sql` từ 3 tệp gốc (15 bảng, 24 sinh viên, đã import thử thành công) + script `database/build_database_sql.ps1`. | `database/` |
| D-03 | Xóa `src/` (bản sao `frontend/`), `backend/` (cổng đăng nhập thứ 2 không dùng) và ảnh trùng `frontend/assets/images/`. | `src/`, `backend/`, `frontend/assets/images/` |

### 6.2 Code bất hợp lý khác đã sửa

| Vấn đề | Cách sửa |
| --- | --- |
| Gọi sai method (vd `PUT /api/login`) trả **HTTP 200 với body rỗng** | Router trả **405 METHOD_NOT_ALLOWED** khi path đúng nhưng method sai |
| `config.php` khai báo timeout phiên, số lần sai, rate limit… nhưng code **viết cứng** số, nên sửa config không có tác dụng | Đọc qua `app_config()`; tên trường trên hóa đơn cũng lấy từ config |
| Cookie phiên cũ đã hết hạn làm lần đăng nhập đầu tiên bị trả 401 "phiên hết hạn" | `start_session_safe(false)` khi đăng nhập: xóa phiên cũ rồi đăng nhập bình thường |
| `$_SESSION['user_id'] ?? 1` (8 chỗ): thiếu phiên thì "đoán" là user id 1 | Hàm `current_user_id()`: thiếu phiên thì trả 401 |
| Mật khẩu tạm sinh bằng `ord(random_bytes(1)) % n` (lệch xác suất) | `random_int()` |
| Rate limit đọc rồi ghi file không khóa, request đồng thời có thể đếm sai | `flock()` |
| `db_transaction()` chỉ rollback khi lỗi PDO | Rollback với mọi lỗi |
| Khối kiểm tra số tiền chép 3 lần trong `student.php` | Hàm `require_positive_amount()` |
| Router là chuỗi `if` dài, tham số `$m` dùng lại từ regex trước | Bảng `$routes` + `route_params()` |
| `api.js` đoán đường dẫn API theo tên thư mục `tuition-system`/`src`/`frontend` | Lấy theo thư mục của trang, chạy với mọi tên thư mục |

Đồng thời đã thêm chú thích tiếng Việt cho toàn bộ `api/lib/`, router, và **mục lục endpoint** ở đầu mỗi handler. Thêm tài liệu cho người mới: [HUONG_DAN_DOC_CODE.md](HUONG_DAN_DOC_CODE.md).

### 6.3 Kết quả chạy lại

| Hạng mục | Trước | Sau |
| --- | --- | --- |
| Backend `tests/run.php` | 341/341 | **346/346** (thêm nhóm 33: rate limit) |
| Frontend `?test=1` | 30/30 | **30/30** |
| Quét bảo mật | 53 PASS / 1 FAIL / 10 WARN | **63 PASS / 0 FAIL / 2 WARN** (thêm 3 kiểm tra mới) |
| Hiệu năng (25 client) | 900 req/s, 0 lỗi | **926 req/s, 0 lỗi** |

2 WARN còn lại là cấu hình khi triển khai: `simulation_enabled` đang bật cho demo, và chưa có HTTPS.

### 6.4 Yêu cầu thời gian phản hồi < 200 ms (1 người dùng)

Chạy `C:\xampp\php\php.exe tests\perf.php` (ngưỡng mặc định 200 ms, tính theo thời gian **lớn nhất** của mỗi thao tác) trên dữ liệu mẫu:

| Nhóm thao tác | Số thao tác | Chậm nhất | Kết quả |
| --- | ---: | ---: | :---: |
| Tải trang (HTML + CSS + 4 JS) | 7 | 15 ms (cả trang) | ĐẠT |
| Đăng nhập | 1 | 107 ms | ĐẠT |
| API đọc (admin + sinh viên) | 15 | 46 ms | ĐẠT |
| Thao tác ghi: thêm SV, tạo hồ sơ, miễn giảm, thu tiền, đảo bút toán, hoàn tiền, duyệt, đăng ký tín chỉ, QR (tạo/poll/xác nhận), thẻ | 14 | 44 ms | ĐẠT |

Thay đổi để đạt yêu cầu:
- Đăng nhập ban đầu mất 183–204 ms, do bcrypt cost 12 tốn ~180 ms mỗi lần. Đã hạ xuống **cost 11** (~80 ms), vẫn trên mức tối thiểu 10 của OWASP. Kết quả: ~100 ms. Mật khẩu cũ cost 12 được `login_user()` tự băm lại ở lần đăng nhập thành công kế tiếp (đã kiểm chứng `$2y$12$` → `$2y$11$`).
- Truy vấn tổng hợp công nợ (`get_fee_summary_subquery()`) quét `ledger_entries` 1 lần thay vì 4 và `refund_requests` 1 lần thay vì 2. Kết quả trả về không đổi (341/341 test).
- Bật nén gzip cho JSON (`api/index.php`).

**Giới hạn quy mô** (đo thêm với `tests/seed_large.php`, ~5.000 sinh viên / 10.000 hồ sơ): 1 người dùng vẫn đạt < 800 ms, nhưng `/api/fee-records` mất ~340 ms (> 200 ms) và trả về 9,5 MB (494 KB khi nén gzip), vì các API danh sách **không phân trang**. Nếu cần chạy với hàng nghìn sinh viên, phải thêm phân trang cho `/api/fee-records`, `/api/students`, `/api/history`.

### 6.5 Chưa làm (đề xuất tiếp theo)

- `api/handlers/student.php` (~1.300 dòng) và `frontend/js/main.js` (~5.200 dòng) vẫn là các hàm/tệp rất lớn. Nên tách mỗi endpoint thành 1 hàm riêng và tách `main.js` theo màn hình. Chưa làm trong đợt này để giữ rủi ro thấp; đã có mục lục và bản đồ đọc code thay thế.
- Form liên hệ chưa lưu dữ liệu (đã ghi chú trong code).
- Chưa có test tự động cho giao diện (click, modal, in ấn).

## 7. Cách chạy lại

Yêu cầu Apache và MySQL trong XAMPP đang chạy. Chạy tại thư mục gốc dự án:

```powershell
C:\xampp\php\php.exe tests\run.php            # chức năng backend (tạo/reset tuition_test)
# mở http://localhost/tuition-system/?test=1  # chức năng frontend
C:\xampp\php\php.exe tests\perf.php           # hiệu năng
C:\xampp\php\php.exe tests\security_scan.php  # bảo mật
```

Cả ba script đều chỉ thao tác trên database `tuition_test`, không ảnh hưởng dữ liệu trong `tuition_system`.
