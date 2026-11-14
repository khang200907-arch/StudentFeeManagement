# Đặc tả yêu cầu — Hệ thống Quản lý Học phí Sinh viên

| | |
| --- | --- |
| Phiên bản | 1.0 — 14/11/2026 |
| Bổ sung cho | [Phân tích & Thiết kế](PHAN_TICH_THIET_KE.md) (mục tiêu, tác nhân, use case, yêu cầu chức năng FR-01…FR-45) |
| Dữ liệu | [ERD](ERD.md) |
| Số liệu đo | [Báo cáo kiểm thử](BAO_CAO_KIEM_THU.md), chạy ngày 14/11/2026 trên PHP 8.5.12, MariaDB 10.11.16, Windows 11, dữ liệu mẫu `seed.sql` |

## 0. Quy ước mã

| Tiền tố | Ý nghĩa | Nguồn |
| --- | --- | --- |
| `FR-xx` | Yêu cầu chức năng | [PHAN_TICH_THIET_KE.md §3](PHAN_TICH_THIET_KE.md#3-yêu-cầu-chức-năng) |
| `NFR-<NHÓM>-xx` | Yêu cầu phi chức năng: SEC (bảo mật), PERF (hiệu năng), USE (khả dụng), LOC (bản địa hóa), REL (tin cậy) | §1 |
| `BR-0xx` | Quy tắc nghiệp vụ | §2 |
| `AS-xx` / `CON-xx` | Giả định / Ràng buộc | §3 |
| `AC-xx` | Tiêu chí nghiệm thu | §4 |
| `TC-SS-NN` | Test tích hợp backend: nhóm `SS`, thứ tự `NN` trong `tests/run.php`. Mã được in ở mỗi dòng kết quả, ví dụ `[✓] TC-13-04  Yêu cầu ghi với sai CSRF token…` | `C:\xampp\php\php.exe tests\run.php` |
| `FE-NN` | Test đơn vị frontend, tương ứng "Test NN" trong Console | `http://localhost/tuition-system/?test=1` |
| `PERF` / `SEC` | Kết quả của `tests/perf.php` / `tests/security_scan.php` | |

---

## 1. Yêu cầu phi chức năng

Cột **Đo được** ghi kết quả thật của lần chạy 14/11/2026.

### 1.1 Bảo mật (SEC)

| Mã | Yêu cầu | Chỉ tiêu đo được | Đo được | Kiểm chứng |
| --- | --- | --- | --- | --- |
| NFR-SEC-01 | Chặn truy cập trực tiếp mã nguồn, cấu hình, SQL, log | 100% đường dẫn nhạy cảm trả **403** | 17/17 → 403; không liệt kê thư mục | SEC [1] |
| NFR-SEC-02 | Header bảo mật trên mọi phản hồi | Có đủ 4 header: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy` (không `'unsafe-inline'` cho script), `Referrer-Policy`; không có `X-Powered-By` | 4/4, CSP `script-src 'self'` | SEC [2] |
| NFR-SEC-03 | Cookie phiên an toàn | `HttpOnly` + `SameSite=Strict` + không `Expires`; `Secure` khi chạy HTTPS | Đạt (Secure: WARN vì môi trường HTTP) | SEC [2] |
| NFR-SEC-04 | Xác thực mọi API dữ liệu | 100% API dữ liệu trả **401** khi chưa đăng nhập | 7/7 (SEC) + toàn bộ `/api/student/*` | SEC [3], TC-13-01, TC-13-02, TC-22-39, TC-20-01 |
| NFR-SEC-05 | Phân quyền theo vai trò | Sai vai trò → **403**; dữ liệu của sinh viên khác → **404**; body chứa `student_id` → **400** | Đạt | TC-22-35…42, TC-25-12, TC-26-08, TC-26-09, TC-29-09, TC-30-08 |
| NFR-SEC-06 | Chống CSRF | 100% request ghi thiếu hoặc sai token → **403** | Đạt | TC-13-03, TC-13-04, TC-20-10, TC-22-42, TC-31-08, SEC [4] |
| NFR-SEC-07 | Chống SQL injection | 0 payload làm lộ dữ liệu, gây lỗi 500 hoặc trễ > 2,5 s | 0/6 payload (`' OR 1=1`, `UNION SELECT`, `SLEEP(3)`…) | SEC [5] |
| NFR-SEC-08 | Chống gán trường trái phép | Trường ngoài danh sách cho phép → **400** | Đạt | SEC [5], TC-22-38, TC-31-07 |
| NFR-SEC-09 | Chống dò mật khẩu | Khóa **15 phút** sau **5** lần sai; ≤ **30** lần đăng nhập/phút/IP → **429** | Khóa: đạt. Rate limit: **chưa có test tự động** | TC-13-05…07, TC-22-43, TC-24-05, TC-24-06 |
| NFR-SEC-10 | Không dò được tài khoản | Cùng thông báo lỗi; chênh lệch thời gian đăng nhập (tài khoản có / không tồn tại) **< 80 ms** | Chênh **1 ms** | TC-24-03, TC-24-04, SEC [7] |
| NFR-SEC-11 | Lưu mật khẩu an toàn | bcrypt cost **≥ 10** (thực tế 11); mật khẩu tạm 12 ký tự từ nguồn ngẫu nhiên mật mã | cost 11 | TC-22-01, `api/lib/config.php` |
| NFR-SEC-12 | Phiên hết hạn | Rảnh **1.800 s** → 401 `SESSION_EXPIRED`; tối đa **28.800 s** → 401 `SESSION_MAX_LIFETIME` | Đạt | TC-31-02, TC-31-03 |
| NFR-SEC-13 | Không lộ thông tin lỗi | Lỗi 500 không chứa stack trace, đường dẫn, SQL | Đạt | TC-23-03, SEC [6] |
| NFR-SEC-14 | Bảo vệ dữ liệu thẻ | **0** lần xuất hiện số thẻ đầy đủ/CVV/hạn thẻ trong DB, log, phản hồi, HTML hóa đơn | 0 | TC-28-26, TC-30-09 |
| NFR-SEC-15 | Tổng hợp quét bảo mật | **0 FAIL** | 63 PASS / **0 FAIL** / 2 WARN (Secure cookie, `simulation_enabled` đang bật cho demo) | SEC |

### 1.2 Hiệu năng (PERF)

Đo bằng `tests/perf.php`, **1 người dùng**, mỗi thao tác đọc lặp 30 lần, tính theo **lần chậm nhất (max)**. Máy chủ và trình duyệt chạy cùng máy.

| Mã | Yêu cầu | Chỉ tiêu | Đo được (max) | Kết quả |
| --- | --- | --- | ---: | :---: |
| NFR-PERF-01 | Tải trang ứng dụng (HTML + CSS + 4 JS, tải tuần tự) | < 200 ms | 15 ms | Đạt |
| NFR-PERF-02 | Đăng nhập | < 200 ms | 107 ms | Đạt |
| NFR-PERF-03 | 15 API đọc (danh sách, chi tiết, báo cáo, cổng SV) | < 200 ms mỗi API | 46 ms | Đạt |
| NFR-PERF-04 | 14 thao tác ghi (thêm SV, hồ sơ, miễn giảm, thu, đảo, hoàn, duyệt, đăng ký, QR tạo/poll/xác nhận, thẻ) | < 200 ms mỗi thao tác | 44 ms | Đạt |
| NFR-PERF-05 | Kiểm tra trạng thái phiên QR (gọi 3 giây/lần) | < 200 ms | 31 ms | Đạt |
| NFR-PERF-06 *(tham khảo)* | 50 người dùng đồng thời, dữ liệu mẫu | p95 < 200 ms, 0 lỗi | p95 41 ms, ~1.200 req/s, 0 lỗi | Đạt |

Giới hạn quy mô: với ~5.000 sinh viên / 10.000 hồ sơ, `GET /api/fee-records` đo được 626 ms (1 người dùng), vì các API danh sách không phân trang (CON-08).

### 1.3 Khả dụng (USE)

| Mã | Yêu cầu | Chỉ tiêu đo được | Kiểm chứng |
| --- | --- | --- | --- |
| NFR-USE-01 | Lỗi nhập liệu hiện đúng chỗ | 100% lỗi kiểm tra dữ liệu trả `error.fields.<tên trường>` để hiện ngay dưới ô nhập | TC-18-03…35 (35 test), TC-22-13, TC-22-14 |
| NFR-USE-02 | Chống thao tác lặp | Bấm gửi 2 lần → **1** bản ghi duy nhất (nút bị khóa + `request_id`) | TC-07-01…03, TC-22-27 |
| NFR-USE-03 | Phản hồi tức thì | Mọi thao tác < 200 ms (= NFR-PERF-01…05) | PERF |
| NFR-USE-04 | Không mất dữ liệu khi lỗi mạng/máy chủ | Form giữ nguyên dữ liệu đã nhập; thông báo tiếng Việt | Checklist thủ công README #14, #20 |
| NFR-USE-05 | Hiển thị trên màn hình nhỏ | Không vỡ bố cục ở chiều rộng **360 px**; bảng cuộn ngang | Checklist thủ công README #22 |
| NFR-USE-06 | Số liệu tổng quan không đổi khi lọc | KPI toàn trường giữ nguyên khi tìm kiếm/lọc | TC-23-11, TC-23-12 |
| NFR-USE-07 | In chứng từ | Biên lai/hóa đơn in vừa **1 trang A4**, ẩn menu và nút | Checklist thủ công README #18, bảng SV #17 |

### 1.4 Bản địa hóa (LOC)

| Mã | Yêu cầu | Chỉ tiêu đo được | Kiểm chứng |
| --- | --- | --- | --- |
| NFR-LOC-01 | Giao diện và thông báo tiếng Việt | 100% thông báo lỗi API bằng tiếng Việt | TC-02-02, TC-11-03, TC-22-15, TC-24-03 |
| NFR-LOC-02 | Tiếng Việt Unicode toàn vẹn | Họ tên có dấu đi qua DB → JSON → giao diện không đổi byte; tên tới **100** ký tự UTF-8 | TC-17-01, TC-14-09, TC-14-10, TC-32-05 |
| NFR-LOC-03 | Tìm kiếm không dấu | `nguyen` = `Nguyễn`, `don`/`đoàn` = `Đoàn`, không phân biệt hoa thường, dấu tổ hợp NFD/NFC | TC-10-01…05, TC-19-02, TC-19-03, TC-29-01, TC-29-02, FE-21, FE-22 |
| NFR-LOC-04 | Nhập tiền theo thói quen Việt Nam | Chấp nhận `1.500.000`, `1,500,000`, `1 500 000`, `1500000 đ`, `₫1.500.000`; từ chối `1.5`, `1e6`, số âm | TC-14-01…08, TC-18-01, TC-18-02, FE-23…27 |
| NFR-LOC-05 | Hiển thị tiền VND | Định dạng `1.500.000 ₫`; số tiền bằng chữ đúng tiếng Việt (vd "Một triệu năm trăm nghìn đồng chẵn") | FE-28, FE-29 |
| NFR-LOC-06 | Ngày và múi giờ | Nhận `dd/mm/yyyy` và lưu ISO `YYYY-MM-DD`; múi giờ PHP và MySQL đều `Asia/Ho_Chi_Minh` (+07:00) | TC-18-17, `api/lib/db.php` |

### 1.5 Tin cậy (REL)

| Mã | Yêu cầu | Chỉ tiêu đo được | Kiểm chứng |
| --- | --- | --- | --- |
| NFR-REL-01 | Giao dịch nguyên tử | Lỗi ở bất kỳ bước nào → **0** dòng sổ cái/hóa đơn được ghi | TC-02-03, TC-22-22, TC-22-25, TC-28-14, TC-30-03 |
| NFR-REL-02 | An toàn khi đồng thời | 2 request đồng thời vượt giới hạn → đúng **1** thành công | TC-09-01, TC-16-03, TC-16-04, TC-16-10 |
| NFR-REL-03 | Idempotency | Gửi lại cùng `request_id` → **0** bản ghi mới, trả kết quả cũ | TC-07-02, TC-07-03, TC-16-16, TC-16-17, TC-22-17, TC-22-27, TC-28-07, TC-28-25, TC-30-04 |
| NFR-REL-04 | Số liệu luôn cân bằng | `Đã thu + Còn phải thu = Tổng phải thu` ở **mọi** phạm vi (toàn trường, từng học kỳ), kể cả sau chuyển/hoàn tiền | TC-12-02, TC-16-20, TC-27-03, FE-30 |
| NFR-REL-05 | Chứng từ không trùng, không nhảy số | Số `PT-`/`HD-` liên tục theo ngày, 0 trùng | TC-08-01…03, TC-32-03 |
| NFR-REL-06 | Cổng thanh toán lỗi không làm sai số liệu | Lỗi/timeout cổng → HTTP 502/422, **0** ghi sổ cái | TC-22-25, TC-28-19, TC-28-20, TC-30-03 |
| NFR-REL-07 | Cài đặt lặp lại được | Nạp lại `schema.sql` + `seed.sql` + `seed_users.php` **2 lần liên tiếp** không lỗi, dữ liệu toàn vẹn | TC-32-06, TC-00-01, TC-00-02 |
| NFR-REL-08 | Kiểm thử hồi quy | 100% test tự động PASS trước mỗi lần bàn giao | 341/341 TC, 30/30 FE |

---

## 2. Quy tắc nghiệp vụ

### 2.1 Tính tiền

| Mã | Quy tắc |
| --- | --- |
| BR-001 | Mọi số tiền là **số nguyên VND**, trong khoảng 1 … 999.999.999.999. Không dùng số thực. |
| BR-002 | **Học phí gốc** `gross = registered_credits × rate_snapshot`. |
| BR-003 | `rate_snapshot` = đơn giá của học kỳ **tại thời điểm tạo hồ sơ**. Đổi đơn giá sau đó không làm đổi hồ sơ đã có. |
| BR-004 | **Miễn giảm %**: `exemption = ROUND(gross × p / 100)`, với p là số nguyên 1–100. |
| BR-005 | **Miễn giảm cố định**: `exemption = MIN(value, gross)`. API từ chối giá trị vượt học phí gốc. |
| BR-006 | Mỗi hồ sơ có tối đa **1** miễn giảm; đặt lại sẽ ghi đè. |
| BR-007 | **Phải nộp** `net_due = MAX(0, gross − exemption)`. |
| BR-008 | **Đã nộp** `total_paid = Σ payment − Σ reversal − Σ refund(completed) − Σ transfer_out + Σ transfer_in`. Yêu cầu hoàn tiền `pending` **không** làm giảm `total_paid`. |
| BR-009 | **Còn nợ** `outstanding = MAX(0, net_due − total_paid)`. |
| BR-010 | **Nộp thừa** `overpaid = MAX(0, total_paid − net_due)`. |
| BR-011 | **Thừa khả dụng** (để chuyển hoặc hoàn) = `overpaid − Σ refund(pending)`. |

### 2.2 Suy ra trạng thái thanh toán

| Mã | Quy tắc |
| --- | --- |
| BR-020 | Trạng thái của hồ sơ được **suy ra** từ `total_paid` và `net_due` (không lưu), xét theo thứ tự ưu tiên. Xem bảng và mã giả bên dưới. |
| BR-021 | Hồ sơ **miễn giảm 100%** (`net_due = 0`) và chưa nộp → "Đã thanh toán đủ", không phải "Chưa thanh toán". |
| BR-022 | Báo cáo đếm **Nộp thừa** thành nhóm riêng; 3 nhóm còn lại + Nộp thừa = tổng số hồ sơ trong phạm vi. |
| BR-023 | Cờ quy trình **Chờ duyệt** chỉ bật khi hồ sơ nộp thừa **và** có ít nhất 1 yêu cầu hoàn tiền `pending`. |

```text
p = total_paid, n = net_due

status (api/lib/calc.php · derive_payment_status):
    nếu p ≤ 0 và n > 0          → "Unpaid"          (Chưa thanh toán)
    ngược lại nếu 0 < p < n     → "Partially Paid"  (Thanh toán một phần)
    ngược lại (p ≥ n)           → "Fully Paid"      (Đã thanh toán đủ)

is_overpaid      = p > n            overpaid_amount = is_overpaid ? p − n : 0
outstanding      = max(0, n − p)
is_pending       = is_overpaid và (số yêu cầu hoàn pending > 0)
workflow_status  = is_pending ? "Pending" (Chờ duyệt) : null

derived_status (SQL, dùng cho bộ lọc và báo cáo – Nộp thừa được ưu tiên trước):
    p > n → "Overpaid" (Nộp thừa) ; còn lại như status ở trên
```

| `total_paid` (p) | `net_due` (n) | status | is_overpaid | derived_status | Test |
| --- | --- | --- | :---: | --- | --- |
| 0 | 5.000.000 | Chưa thanh toán | ✗ | Unpaid | TC-03-01, FE-09 |
| 4.999.999 | 5.000.000 | Thanh toán một phần | ✗ | Partially Paid | TC-03-02, FE-10 |
| 5.000.000 | 5.000.000 | Đã thanh toán đủ | ✗ | Fully Paid | TC-03-03, FE-11 |
| 0 | 0 (miễn 100%) | Đã thanh toán đủ | ✗ | Fully Paid | TC-03-04, TC-23-13, FE-12 |
| 5.000.001 | 5.000.000 | Đã thanh toán đủ | ✓ (1 ₫) | Overpaid | TC-03-05, FE-13 |
| thừa + có hoàn `pending` | | Đã thanh toán đủ | ✓ | Overpaid, workflow "Chờ duyệt" | TC-21-01…06 |

### 2.3 Thu tiền và chứng từ

| Mã | Quy tắc |
| --- | --- |
| BR-030 | Số tiền một lần thu **≤ còn nợ**. Vượt dù 1 ₫ → từ chối (422), hướng dẫn lập hoàn tiền. |
| BR-031 | Hồ sơ đang **nộp thừa không nhận thêm** tiền (422 `OVERPAID_BLOCKED`). |
| BR-032 | Ngày chứng từ **không được ở tương lai** và phải là ngày có thật trên lịch. |
| BR-033 | Quản trị viên thu bằng *Tiền mặt / Chuyển khoản / Thẻ*. Sinh viên chỉ thanh toán bằng *Mã QR / Thẻ Visa/Mastercard*, không *Tiền mặt*. |
| BR-034 | Mỗi yêu cầu ghi tiền mang `request_id` dạng UUID. Trùng `request_id` → trả lại kết quả lần trước, không ghi thêm. |
| BR-035 | Số biên lai `PT-YYYYMMDD-NNNN` (theo ngày chứng từ), tăng liên tục từ `0001` mỗi ngày, không trùng. Chỉ khoản `payment` có biên lai. |
| BR-036 | Mỗi khoản thu do **sinh viên tự thanh toán** sinh đúng 1 hóa đơn `HD-YYYYMMDD-NNNN`. Thanh toán thất bại không sinh hóa đơn. |
| BR-037 | Hóa đơn là **bản chụp bất biến** tại thời điểm thanh toán (công nợ trước/sau, học phí, phương thức). Chỉ lưu thương hiệu thẻ + 4 số cuối. |

### 2.4 Đảo bút toán, chuyển tiền, hoàn tiền

| Mã | Quy tắc |
| --- | --- |
| BR-040 | Chỉ đảo được dòng `payment`. Mỗi phiếu đảo **tối đa 1 lần** (409). Không đảo dòng `reversal` hay bút toán chuyển nội bộ. Bắt buộc có lý do. |
| BR-041 | Không đảo được nếu số tiền của phiếu đã được hoàn hoặc chuyển đi, tức là sau khi đảo `total_paid` sẽ âm. |
| BR-042 | Chuyển tiền: nguồn và đích **cùng một sinh viên**, **khác hồ sơ**; số chuyển ≤ thừa khả dụng của nguồn **và** ≤ còn nợ của đích. Tạo 1 cặp `transfer_out` + `transfer_in` chung `transfer_group_id`, không cấp số biên lai. |
| BR-043 | Hoàn tiền: số hoàn ≤ thừa khả dụng (BR-011). Lý do tối đa 200 ký tự. |
| BR-044 | **Maker-checker**: người duyệt hoàn tiền phải **khác** người lập (403). Người lập được tự hủy. Hủy bắt buộc có lý do. |
| BR-045 | Khi duyệt, hệ thống kiểm tra lại số thừa **trong giao dịch**. Hai yêu cầu cùng vượt số thừa → chỉ 1 được duyệt. |

### 2.5 Danh mục, tài khoản, cổng sinh viên

| Mã | Quy tắc |
| --- | --- |
| BR-050 | Mã SV: 6–12 ký tự chữ/số, không khoảng trắng hay ký tự đặc biệt, **lưu chữ hoa**, không trùng. |
| BR-051 | Họ tên: 1–100 ký tự Unicode, không ký tự điều khiển, không chỉ gồm số/ký hiệu; chuẩn hóa NFC. Lớp ≤ 50 ký tự; rỗng → NULL. |
| BR-052 | Không xóa được sinh viên đã có hồ sơ học phí; học kỳ đã có hồ sơ hoặc đơn giá; đơn giá của học kỳ đã có hồ sơ. |
| BR-053 | Học kỳ: `term_no` 1–3, không trùng (học kỳ, năm học). Đơn giá > 0, mỗi học kỳ 1 đơn giá. |
| BR-054 | Mỗi sinh viên **1 hồ sơ / học kỳ** (409 kèm `existing_id`). Không tạo được hồ sơ khi học kỳ chưa có đơn giá. |
| BR-055 | Số tín chỉ: quản trị viên 1–60; sinh viên trong `[min, max]` của `student_settings` (mặc định 1–30). |
| BR-056 | Sinh viên chỉ đăng ký được học kỳ có `registration_open = 1` và `hôm nay ≤ registration_close` (nếu có). Không sửa tín chỉ khi `total_paid > 0`. |
| BR-057 | Mỗi sinh viên tối đa **1 tài khoản**: username = mã SV viết thường, mật khẩu tạm 12 ký tự (đủ hoa/thường/số/ký tự đặc biệt), `must_change_password = 1`. |
| BR-058 | Mật khẩu mới: ≥ 8 ký tự, có chữ và số, khớp ô xác nhận, khác mật khẩu hiện tại. Khi `must_change_password = 1`, mọi API sinh viên khác trả 403 `PASSWORD_CHANGE_REQUIRED`. |
| BR-059 | Phiên QR hết hạn sau 15 phút. Tạo phiên mới cho cùng hồ sơ thì phiên `pending` cũ bị hủy. Không hủy được phiên đã `confirmed`. Lúc xác nhận, số tiền được kiểm tra lại với số nợ hiện tại. |
| BR-060 | Mọi kênh thanh toán mô phỏng (QR xác nhận, thẻ, endpoint cũ) chỉ hoạt động khi `simulation_enabled = true`. |
| BR-061 | Tra cứu lịch sử: `to ≥ from` và khoảng ≤ 5 năm. Từ khóa tìm kiếm ≤ 100 ký tự; tìm sinh viên trả tối đa 20 kết quả. |

---

## 3. Giả định và ràng buộc

### 3.1 Giả định

| Mã | Giả định |
| --- | --- |
| AS-01 | Hệ thống phục vụ **một** đơn vị đào tạo, đơn vị tiền tệ duy nhất là VND. |
| AS-02 | Học phí tính thuần theo tín chỉ × đơn giá chung của học kỳ, không phân biệt ngành hay loại học phần. |
| AS-03 | Quản trị viên là cán bộ được tin cậy; có ít nhất **2** quản trị viên để vận hành maker-checker. |
| AS-04 | Việc nhận tiền thật (ngân hàng, thẻ) nằm ngoài hệ thống; hệ thống chỉ ghi nhận. Cổng thanh toán là **mô phỏng**. |
| AS-05 | Người dùng dùng trình duyệt hiện đại (Chrome, Edge, Firefox bản gần đây) có bật JavaScript. |
| AS-06 | Đồng hồ máy chủ đúng giờ Việt Nam; PHP và MySQL cùng múi giờ +07:00. |
| AS-07 | Quy mô dữ liệu ở mức một trường nhỏ/vừa (hàng trăm đến khoảng 2.000 sinh viên mỗi học kỳ); xem CON-08. |
| AS-08 | Việc mở/đóng đăng ký học kỳ do quản trị CSDL thực hiện (chưa có giao diện). |

### 3.2 Ràng buộc

| Mã | Ràng buộc |
| --- | --- |
| CON-01 | Chạy trên **XAMPP**: Apache 2.4 + PHP ≥ 8.1 + MariaDB 10.x/MySQL 8; không npm, không framework, không CDN, không bước build. |
| CON-02 | Frontend là **một trang** HTML + JavaScript thuần; script chỉ từ file `.js` của site (CSP). |
| CON-03 | Phiên lưu bằng file của PHP; chạy trên **một** máy chủ (không chia sẻ phiên giữa nhiều máy). |
| CON-04 | Ứng dụng kết nối DB bằng tài khoản quyền tối thiểu `tuition_user` (SELECT/INSERT/UPDATE/DELETE). |
| CON-05 | XAMPP mặc định chạy HTTP; cookie chỉ có `Secure` khi triển khai HTTPS. |
| CON-06 | `simulation_enabled` **phải là `false`** khi dùng với tiền thật; khi đó các kênh thanh toán trực tuyến bị tắt cho tới khi tích hợp cổng thật. |
| CON-07 | Sổ cái, hóa đơn, nhật ký chỉ được thêm; mọi điều chỉnh thực hiện bằng bút toán đối ứng. |
| CON-08 | Các API danh sách (`/api/fee-records`, `/api/students`, `/api/history`) **không phân trang**. Với ~5.000 sinh viên, chúng vượt 200 ms; cần thêm phân trang trước khi mở rộng quy mô. |
| CON-09 | Bộ kiểm thử dùng database riêng `tuition_test` và chỉ được chấp nhận từ localhost. |

---

## 4. Tiêu chí nghiệm thu

### 4.1 Tiêu chí bàn giao chung

| Mã | Tiêu chí | Cách đo | Trạng thái 14/11/2026 |
| --- | --- | --- | :---: |
| AC-01 | 100% test tích hợp backend PASS | `tests/run.php` | 341/341 ✔ |
| AC-02 | 100% test đơn vị frontend PASS | `?test=1` | 30/30 ✔ |
| AC-03 | Mọi thao tác < 200 ms với 1 người dùng | `tests/perf.php` (exit 0) | ✔ (max 107 ms) |
| AC-04 | Quét bảo mật 0 FAIL | `tests/security_scan.php` (exit 0) | ✔ (63/0/2) |
| AC-05 | Checklist thủ công trong README (34 + 20 mục) đạt | Kiểm thử viên ký xác nhận | Chưa thực hiện |
| AC-06 | Cài mới theo README thành công trên máy sạch | Làm theo README, đăng nhập `admin` | ✔ |
| AC-07 | Trước khi dùng thật: `simulation_enabled = false`, có HTTPS, đã đổi mật khẩu DB và tài khoản mẫu | Rà soát cấu hình | Chưa áp dụng (môi trường demo) |

### 4.2 Tiêu chí theo chức năng

Viết dạng *Cho … / Khi … / Thì …*. Mỗi tiêu chí được nghiệm thu khi các test liệt kê ở §5 đều PASS.

| Mã | Chức năng | Tiêu chí |
| --- | --- | --- |
| AC-10 | Đăng nhập (FR-01, FR-02) | Cho tài khoản hợp lệ, khi nhập đúng → 200 + vào đúng giao diện theo vai trò. Khi sai mật khẩu hoặc tài khoản không tồn tại → 401 cùng thông báo "Sai tên đăng nhập hoặc mật khẩu.". Khi sai lần thứ 5 → khóa 15 phút (409), hết khóa thì đăng nhập lại được. |
| AC-11 | Phiên (FR-03) | Khi rảnh 30 phút hoặc quá 8 giờ → request kế tiếp nhận 401. Khi đóng tab cuối → phiên bị hủy; tải lại trang (F5) không đăng xuất. |
| AC-12 | Tài khoản SV (FR-04…06) | Cấp tài khoản lần 2 cho cùng SV → 409. SV đăng nhập bằng mật khẩu tạm → mọi chức năng khác trả 403 tới khi đổi mật khẩu hợp lệ. |
| AC-20 | Sinh viên (FR-10, FR-11) | Thêm mã `sv013` → lưu `SV013`. Tìm `nguyen` → có cả "Nguyễn". Xóa SV đã có hồ sơ → 409, dữ liệu không đổi. |
| AC-21 | Học kỳ, đơn giá (FR-12, FR-13) | Thêm học kỳ trùng hoặc `term_no = 4` → 400. Sửa đơn giá → hồ sơ cũ giữ nguyên `rate_snapshot`. |
| AC-30 | Hồ sơ & miễn giảm (FR-20…22) | 15 TC × 850.000 → gross 12.750.000. Miễn 10% → net 11.475.000. Tạo hồ sơ trùng → 409 + `existing_id`, không thêm dòng. |
| AC-31 | Thu tiền (FR-23) | Còn nợ 10.300.000: thu 10.300.001 → 422, sổ cái không đổi; thu 10.300.000 → 201, còn nợ 0, trạng thái "Đã thanh toán đủ". Gửi lại cùng `request_id` → 200, vẫn 1 dòng. |
| AC-32 | Đảo bút toán (FR-24) | Đảo phiếu thu → còn nợ trở về mức trước khi thu. Đảo lần 2 → 409. |
| AC-33 | Chuyển tiền thừa (FR-25) | SV007 chuyển 700.000 từ HK2 2025-2026 sang HK1 2026-2027 → nguồn "Đã thanh toán đủ" (7.500.000), đích còn nợ 9.500.000; báo cáo vẫn cân bằng. |
| AC-34 | Hoàn tiền (FR-26) | Người lập tự duyệt → 403. Admin khác duyệt → `completed`, số thừa về 0. Hủy (có lý do) → `cancelled`, số thừa khả dụng trở lại. |
| AC-40 | Báo cáo (FR-30, FR-31) | Với mọi học kỳ và toàn trường: `collected + outstanding = receivable`; tổng các nhóm trạng thái = tổng hồ sơ; không có giá trị null. |
| AC-41 | Badge (FR-32) | Có giao dịch mới → badge tăng; mở màn hình Lịch sử → badge về 0. |
| AC-50 | Đăng ký tín chỉ (FR-40) | SV011 đăng ký 15 TC HK1 2026-2027 → 201, gross 12.750.000. 0 hoặc 31 TC → 400. Học kỳ đã đóng → 422. Đã nộp tiền → sửa tín chỉ bị 422. |
| AC-51 | Thanh toán QR (FR-42) | Tạo phiên 2.000.000 → `pending`, hết hạn sau 15 phút. Bấm mô phỏng → `confirmed`, có biên lai `PT-…` và hóa đơn `HD-…`, còn nợ giảm đúng 2.000.000. Xác nhận lần 2 → 409. |
| AC-52 | Thanh toán thẻ (FR-43) | Thẻ `4111…1111` → 201; `5555…4444` → 422; `4000…0002` → 502 và không ghi sổ cái. DB không chứa số thẻ đầy đủ. |
| AC-53 | Hóa đơn (FR-44) | Mỗi thanh toán SV thành công có đúng 1 hóa đơn; tải về là tệp HTML `bill-HD-….html` không chứa `<script>` hay số thẻ đầy đủ. SV khác truy cập → 404. |

---

## 5. Ma trận truy vết FR → test chức năng

| FR | Mô tả ngắn | Test tích hợp backend (`tests/run.php`) | Test frontend | AC |
| --- | --- | --- | --- | --- |
| FR-01 | Đăng nhập, thông báo lỗi chung | TC-00-03, TC-18-27, TC-18-28, TC-18-29, TC-22-04, TC-24-01, TC-24-02, TC-24-03, TC-24-04, TC-24-07, TC-26-01, TC-26-04, TC-28-01, TC-31-01 | — | AC-10 |
| FR-02 | Khóa tài khoản 15 phút / 5 lần sai | TC-13-05, TC-13-06, TC-13-07, TC-22-43, TC-24-05, TC-24-06 | — | AC-10 |
| FR-03 | Đăng xuất, hết phiên, đóng tab | TC-31-02, TC-31-03, TC-31-04, TC-31-05, TC-31-06, TC-31-07, TC-31-08, TC-31-09 | Thủ công: bảng SV #18–20 | AC-11 |
| FR-04 | Cấp tài khoản sinh viên | TC-22-01, TC-22-02, TC-23-01 | — | AC-12 |
| FR-05 | Đặt lại mật khẩu sinh viên | TC-22-03 | — | AC-12 |
| FR-06 | Bắt buộc đổi mật khẩu | TC-22-05, TC-22-06, TC-22-07, TC-22-08, TC-22-09, TC-22-10, TC-22-11, TC-23-02, TC-26-06, TC-26-07 | — | AC-12 |
| FR-10 | Quản lý & tìm kiếm sinh viên | TC-10-01, TC-10-02, TC-10-03, TC-14-09, TC-14-10, TC-18-04…TC-18-14, TC-18-30, TC-18-31, TC-19-01…TC-19-04, TC-19-10…TC-19-13, TC-23-11, TC-23-12, TC-29-01, TC-29-02, TC-29-06, TC-29-07, TC-29-08, TC-32-04, TC-32-05 | FE-21, FE-22 | AC-20 |
| FR-11 | Chặn xóa sinh viên có dữ liệu | TC-11-01, TC-11-02, TC-11-03, TC-19-05, TC-19-06, TC-23-01 | — | AC-20 |
| FR-12 | Quản lý học kỳ | TC-18-23, TC-18-24, TC-29-03, TC-29-04, TC-29-05 | — | AC-21 |
| FR-13 | Đơn giá tín chỉ | TC-04-02, TC-18-25, TC-18-26 | — | AC-21 |
| FR-20 | Tạo hồ sơ học phí (snapshot) | TC-04-01, TC-04-03, TC-04-04, TC-04-05, TC-16-01, TC-16-02, TC-16-03, TC-16-04, TC-29-10, TC-29-11, TC-32-01 | FE-01, FE-02 | AC-30 |
| FR-21 | Sửa số tín chỉ (quản trị viên) | **Chưa có test tự động** (luồng tương tự của SV: TC-22-19, TC-22-29) | — | AC-30 |
| FR-22 | Miễn giảm | TC-01-01…TC-01-10, TC-03-04, TC-18-19, TC-18-20, TC-18-21, TC-18-22, TC-19-07 | FE-03…FE-08 | AC-30 |
| FR-23 | Thu tiền, biên lai | TC-02-01…TC-02-05, TC-03-01, TC-03-02, TC-03-03, TC-03-05, TC-07-01, TC-07-02, TC-07-03, TC-08-01, TC-08-02, TC-08-03, TC-09-01, TC-14-01…TC-14-08, TC-15-01, TC-15-02, TC-18-01, TC-18-02, TC-18-03, TC-18-15, TC-18-16, TC-18-17 | FE-09…FE-15, FE-23…FE-27 | AC-31 |
| FR-24 | Đảo bút toán | TC-06-01, TC-06-02, TC-06-03, TC-16-21, TC-16-22, TC-16-23 | FE-18 | AC-32 |
| FR-25 | Chuyển tiền thừa | TC-16-11…TC-16-20, TC-16-24, TC-16-25, TC-16-26, TC-17-05 | FE-19, FE-20 | AC-33 |
| FR-26 | Hoàn tiền (maker-checker) | TC-05-01…TC-05-06, TC-14-11, TC-16-05…TC-16-10, TC-17-02, TC-17-03, TC-17-04, TC-17-06, TC-19-08, TC-21-01…TC-21-13 | FE-16, FE-17 | AC-34 |
| FR-27 | Tra cứu, in biên lai | TC-18-34, TC-18-35, TC-22-34, TC-22-35 | FE-28, FE-29 · Thủ công README #18 | — |
| FR-30 | Sổ cái, lọc | TC-10-04, TC-10-05, TC-18-18, TC-19-09, TC-23-10 | — | AC-40 |
| FR-31 | Báo cáo đối soát | TC-12-01, TC-12-02, TC-12-03, TC-16-20, TC-23-04…TC-23-09, TC-23-13, TC-23-14, TC-23-15, TC-24-08…TC-24-11, TC-25-01…TC-25-11, TC-27-01…TC-27-07 | FE-30 | AC-40 |
| FR-32 | Badge thông báo | TC-20-01…TC-20-15 | — | AC-41 |
| FR-33 | Nhật ký kiểm toán | TC-21-08, TC-24-07 | — | — |
| FR-40 | Đăng ký tín chỉ | TC-22-12…TC-22-19, TC-22-29, TC-22-37 | — | AC-50 |
| FR-41 | Tra cứu công nợ | TC-22-20, TC-22-21, TC-26-02, TC-26-03, TC-26-05 | — | — |
| FR-42 | Thanh toán Mã QR | TC-28-02…TC-28-17; quy tắc chung của thanh toán SV: TC-22-22…TC-22-28, TC-22-36 | — | AC-51 |
| FR-43 | Thanh toán thẻ | TC-28-18…TC-28-26 | — | AC-52 |
| FR-44 | Hóa đơn: xem, tải, in | TC-30-01…TC-30-09, TC-32-02, TC-32-03 | Thủ công: bảng SV #16, #17 | AC-53 |
| FR-45 | Lịch sử thanh toán sinh viên | TC-22-30, TC-22-31, TC-22-32, TC-22-33 | — | — |

### 5.1 Test không gắn với FR cụ thể

| Test | Phục vụ |
| --- | --- |
| TC-00-01, TC-00-02, TC-32-06 | Chuẩn bị dữ liệu, cài đặt lặp lại được (NFR-REL-07) |
| TC-13-01…TC-13-04, TC-22-38…TC-22-42, TC-23-03, TC-25-12, TC-26-08, TC-26-09, TC-29-09 | Bảo mật: xác thực, phân quyền, CSRF, lỗi 500 (NFR-SEC) |
| TC-17-01 | Bản địa hóa: toàn vẹn tiếng Việt (NFR-LOC-02) |

### 5.2 Khoảng trống kiểm thử

| Hạng mục | Hiện trạng | Đề xuất |
| --- | --- | --- |
| FR-21 sửa số tín chỉ của quản trị viên (`PUT /api/fee-records/{id}`) | Không có test tự động | Thêm test: sửa tín chỉ → gross tính lại; tín chỉ 0/61 → 400 |
| NFR-SEC-09 giới hạn 30 lần đăng nhập/phút | Không có test tự động (bộ test chạy ở chế độ nới giới hạn) | Thêm test không gửi header test, đăng nhập 31 lần → 429 |
| Giao diện (đóng tab, in A4, 360 px, mất mạng) | Chỉ có checklist thủ công | Bổ sung test trình duyệt tự động (Playwright) nếu có điều kiện |
