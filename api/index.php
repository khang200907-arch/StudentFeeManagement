<?php
/**
 * API Front Controller – MỌI request /api/... đều đi qua file này.
 *
 * Luồng xử lý 1 request:
 *   1. .htaccess ở thư mục gốc chuyển /tuition-system/api/xxx → api/index.php
 *   2. File này nạp thư viện (api/lib) và các handler (api/handlers)
 *   3. Tìm route khớp trong bảng $routes bên dưới → gọi hàm handle_*_routes() tương ứng
 *   4. Handler kiểm tra đăng nhập/quyền/CSRF, xử lý, rồi trả JSON bằng json_success()/json_error()
 *      (hai hàm này gọi exit, nên handler không "return" về đây nếu đã xử lý xong)
 *   5. Nếu handler return mà chưa trả lời → path đúng nhưng sai method → HTTP 405
 *
 * Định dạng phản hồi chung (api/lib/response.php):
 *   Thành công: {"ok": true,  "data": ...}
 *   Lỗi:        {"ok": false, "error": {"code": "...", "message": "...", "fields": {...}}}
 */

declare(strict_types=1);

// Không bao giờ in lỗi PHP ra cho client; chỉ ghi vào logs/app_error.log
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Nén gzip phản hồi JSON (chỉ khi trình duyệt gửi Accept-Encoding: gzip).
// Danh sách lớn (hàng nghìn hồ sơ) nhỏ đi khoảng 10 lần → tải nhanh hơn nhiều qua mạng thật.
if (extension_loaded('zlib') && !headers_sent()) {
    ini_set('zlib.output_compression', '1');
    ini_set('zlib.output_compression_level', '1'); // mức nhẹ: đủ nén tốt, tốn ít CPU
}

$logsDir = dirname(__DIR__) . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0777, true);
}
ini_set('error_log', $logsDir . '/app_error.log');

// ---------------------------------------------------------------------------
// Thư viện dùng chung
// ---------------------------------------------------------------------------
require_once __DIR__ . '/lib/config.php';          // app_config(), is_test_request(), hash_password()
require_once __DIR__ . '/lib/response.php';        // json_success(), json_error()
require_once __DIR__ . '/lib/db.php';              // get_db_connection(), db_transaction()
require_once __DIR__ . '/lib/auth.php';            // đăng nhập, phiên, require_admin(), require_student()
require_once __DIR__ . '/lib/csrf.php';            // verify_csrf_token()
require_once __DIR__ . '/lib/validate.php';        // đọc JSON body, kiểm tra dữ liệu nhập
require_once __DIR__ . '/lib/calc.php';            // công thức học phí, trạng thái thanh toán
require_once __DIR__ . '/lib/audit.php';           // log_audit()
require_once __DIR__ . '/lib/rate_limit.php';      // check_rate_limit()
require_once __DIR__ . '/lib/payment_gateway.php'; // cổng thanh toán mô phỏng
require_once __DIR__ . '/lib/payment.php';         // execute_payment_transaction()

// Lỗi không lường trước → ghi log chi tiết, trả client thông báo chung (không lộ SQL/stack trace)
set_exception_handler(function (Throwable $e) {
    error_log(sprintf(
        "[%s] Exception: %s in %s on line %d\nStack Trace:\n%s",
        date('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
    ));
    json_error('Đã xảy ra lỗi hệ thống. Vui lòng thử lại.', 'INTERNAL_SERVER_ERROR', 500);
});

// Cho bộ test kiểm tra exception handler ở trên (chỉ request test từ localhost)
if (get_test_header('X-Test-Force-Exception') !== null) {
    throw new RuntimeException('Forced test exception for 500 handler verification');
}

// ---------------------------------------------------------------------------
// Handlers – mỗi file phụ trách 1 nhóm chức năng
// ---------------------------------------------------------------------------
require_once __DIR__ . '/handlers/auth.php';      // đăng nhập / đăng xuất / phiên
require_once __DIR__ . '/handlers/students.php';  // [admin] quản lý sinh viên + tài khoản SV
require_once __DIR__ . '/handlers/semesters.php'; // [admin] học kỳ
require_once __DIR__ . '/handlers/rates.php';     // [admin] đơn giá tín chỉ
require_once __DIR__ . '/handlers/fees.php';      // [admin] hồ sơ học phí, miễn giảm, chuyển tiền thừa
require_once __DIR__ . '/handlers/payments.php';  // [admin] thu tiền, đảo bút toán
require_once __DIR__ . '/handlers/refunds.php';   // [admin] hoàn tiền (maker-checker)
require_once __DIR__ . '/handlers/history.php';   // [admin] sổ cái giao dịch
require_once __DIR__ . '/handlers/reports.php';   // [admin] báo cáo đối soát
require_once __DIR__ . '/handlers/receipts.php';  // [admin + SV] biên lai
require_once __DIR__ . '/handlers/nav.php';       // [admin] badge thông báo trên menu
require_once __DIR__ . '/handlers/student.php';   // [SV] cổng sinh viên

// ---------------------------------------------------------------------------
// Chuẩn hóa đường dẫn: "/tuition-system/api/students/?q=1" → "/api/students"
// ---------------------------------------------------------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$apiPos = strpos($path, '/api');
$path = $apiPos !== false ? substr($path, $apiPos) : '/api';
if (strlen($path) > 1) {
    $path = rtrim($path, '/');
}

// ---------------------------------------------------------------------------
// Bảng định tuyến: [regex của path, tên hàm handler]
// Route đầu tiên khớp sẽ được dùng. $m (các nhóm bắt trong regex, ví dụ id) được truyền vào handler.
// ---------------------------------------------------------------------------
$routes = [
    ['#^/api/(csrf|login|logout|logout-beacon|me)$#',                         'handle_auth_routes'],
    ['#^/api/students(/search|/(\d+)(/account(/reset)?)?)?$#',                 'handle_students_routes'],
    ['#^/api/student(/.*)?$#',                                                 'handle_student_routes'],
    ['#^/api/semesters(/search|/(\d+))?$#',                                    'handle_semesters_routes'],
    ['#^/api/rates(/(\d+))?$#',                                                'handle_rates_routes'],
    ['#^/api/fee-records/(\d+)/payments$#',                                    'handle_payments_routes'],
    ['#^/api/ledger/(\d+)/reversal$#',                                         'handle_payments_routes'],
    ['#^/api/fee-records/(\d+)/refunds$#',                                     'handle_refunds_routes'],
    ['#^/api/refunds(/(\d+)/(complete|cancel))?$#',                            'handle_refunds_routes'],
    ['#^/api/(fee-records(/(\d+)(/(exemption|transfers))?)?|transfers)$#',     'handle_fees_routes'],
    ['#^/api/history$#',                                                       'handle_history_routes'],
    ['#^/api/reports/summary$#',                                               'handle_reports_routes'],
    ['#^/api/receipts(/([^/]+))?$#',                                           'handle_receipts_routes'],
    ['#^/api/nav/(badges|seen)$#',                                             'handle_nav_routes'],
];

foreach ($routes as [$pattern, $handler]) {
    if (!preg_match($pattern, $path)) {
        continue;
    }
    $handler($method, $path, route_params($path));

    // Handler đã xử lý thì đã exit. Chạy tới đây = path đúng nhưng method không hỗ trợ.
    json_error('Phương thức không được hỗ trợ cho tài nguyên này.', 'METHOD_NOT_ALLOWED', 405);
}

json_error('Không tìm thấy tài nguyên yêu cầu.', 'ENDPOINT_NOT_FOUND', 404);

/**
 * Tách tham số từ path theo đúng định dạng mảng $m mà các handler đang dùng
 * (giữ nguyên chỉ số nhóm như các regex cũ để handler không phải sửa).
 */
function route_params(string $path): array {
    $legacyPatterns = [
        '#^/api/students/(\d+)(/account(/reset)?)?$#',
        '#^/api/semesters/(\d+)$#',
        '#^/api/rates/(\d+)$#',
        '#^/api/fee-records/(\d+)(/(exemption|transfers))?$#',
        '#^/api/fee-records/(\d+)/payments$#',
        '#^/api/ledger/(\d+)/reversal$#',
        '#^/api/fee-records/(\d+)/refunds$#',
        '#^/api/refunds/(\d+)/(complete|cancel)$#',
        '#^/api/receipts/([^/]+)$#',
    ];
    foreach ($legacyPatterns as $pattern) {
        if (preg_match($pattern, $path, $m)) {
            return $m;
        }
    }
    return [];
}
