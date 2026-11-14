<?php
/**
 * Quét bảo mật black-box qua HTTP (bổ sung cho các test bảo mật trong tests/run.php).
 * Chạy trên database tuition_test. Yêu cầu Apache + MySQL đang chạy.
 * Cách dùng: php tests/security_scan.php [--base=http://localhost/tuition-system]
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$opts = getopt('', ['base::']);
$BASE = rtrim($opts['base'] ?? 'http://localhost/tuition-system', '/');
$TEST = 'X-Tuition-Test-Db: tuition_test';

$pass = 0; $fail = 0; $warn = 0;
function check(string $name, bool $ok, string $detail = '', bool $warnOnly = false): void {
    global $pass, $fail, $warn;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; return; }
    if ($warnOnly) { $warn++; echo "  [WARN] $name" . ($detail ? " — $detail" : '') . "\n"; return; }
    $fail++; echo "  [FAIL] $name" . ($detail ? " — $detail" : '') . "\n";
}

function req(string $method, string $url, array $headers = [], $body = null, ?string $cookie = null): array {
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers), CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($cookie) { $opt[CURLOPT_COOKIEFILE] = $cookie; $opt[CURLOPT_COOKIEJAR] = $cookie; }
    if ($body !== null) { $opt[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body); }
    curl_setopt_array($ch, $opt);
    $t = microtime(true);
    $raw = (string)curl_exec($ch);
    $ms = (microtime(true) - $t) * 1000;
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs), 'ms' => $ms];
}

function login(string $base, string $u, string $p, array $extra = []): array {
    $cookie = tempnam(sys_get_temp_dir(), 'sec');
    $r = req('POST', "$base/api/login", array_merge(['Content-Type: application/json', 'X-Tuition-Test-Db: tuition_test'], $extra), ['username' => $u, 'password' => $p], $cookie);
    $j = json_decode($r['body'], true);
    return ['cookie' => $cookie, 'csrf' => $j['data']['csrf_token'] ?? null, 'res' => $r];
}

echo "=== QUÉT BẢO MẬT — $BASE ===\n";

// 1. Truy cập trực tiếp tệp nhạy cảm
echo "\n[1] Chặn truy cập tệp/thư mục nhạy cảm qua web\n";
$sensitive = [
    'config/config.php', 'config/config.example.php', 'database/database.sql', 'database/schema.sql',
    'database/seed_users.php', 'database/create_app_user.sql', 'logs/app_error.log', 'api/lib/db.php',
    'api/handlers/auth.php', 'backend/config/database.php', 'backend/models/User.php',
    'backend/controllers/UserController.php', 'README.md', '.htaccess', 'tests/run.php',
    'tests/perf.php', 'tests/security_scan.php',
];
foreach ($sensitive as $p) {
    $r = req('GET', "$BASE/$p");
    $leak = $r['code'] === 200 && (str_contains($r['body'], '<?php') || str_contains($r['body'], 'CREATE TABLE') || str_contains($r['body'], 'TuitionApp') || strlen($r['body']) > 200);
    check("GET /$p → HTTP {$r['code']}", !$leak, 'nội dung bị lộ');
}
$r = req('GET', "$BASE/.gitignore");
check("Tệp ẩn /.gitignore bị chặn (HTTP {$r['code']})", $r['code'] !== 200, 'đọc được nội dung (rủi ro thấp: chỉ lộ cấu trúc dự án)', true);
$r = req('GET', "$BASE/frontend/assets/");
check("Không liệt kê thư mục /frontend/assets/ (HTTP {$r['code']})", !str_contains($r['body'], 'Index of'));
$r = req('GET', "$BASE/src/index.html");
check("Bản sao giao diện cũ /src/ không còn được phục vụ (HTTP {$r['code']})", $r['code'] !== 200, 'thư mục src/ trùng lặp frontend/ vẫn truy cập được', true);

// 2. Security headers & cookie
echo "\n[2] Security headers & cookie phiên\n";
$r = req('GET', "$BASE/");
foreach (['X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY', 'Content-Security-Policy:', 'Referrer-Policy:'] as $h) {
    check("Header '$h' có trên trang chính", stripos($r['headers'], $h) !== false);
}
check("CSP không dùng 'unsafe-inline' cho script", !preg_match("/script-src[^;]*'unsafe-inline'/i", $r['headers']), "script-src cho phép 'unsafe-inline' — giảm tác dụng chống XSS", true);
check("Không lộ phiên bản PHP qua X-Powered-By", stripos($r['headers'], 'X-Powered-By: PHP') === false, 'header X-Powered-By lộ phiên bản PHP (expose_php=On)', true);
$api = req('GET', "$BASE/api/me", [$TEST]);
check("API trả Content-Type application/json", stripos($api['headers'], 'Content-Type: application/json') !== false);
check("Header bảo mật áp dụng cả cho API", stripos($api['headers'], 'X-Content-Type-Options: nosniff') !== false);
$adm = login($BASE, 'admin', 'Admin@123');
$setCookie = preg_match('/Set-Cookie: TUITION_SESSID=[^\r\n]*/i', $adm['res']['headers'], $m) ? $m[0] : '';
check('Cookie phiên có HttpOnly', stripos($setCookie, 'HttpOnly') !== false, $setCookie);
check('Cookie phiên có SameSite=Strict', stripos($setCookie, 'SameSite=Strict') !== false, $setCookie);
check('Cookie phiên không có Expires/Max-Age (session cookie)', stripos($setCookie, 'expires=') === false && stripos($setCookie, 'Max-Age') === false, $setCookie);
check('Cookie phiên có Secure (chỉ đạt khi chạy HTTPS)', stripos($setCookie, 'secure') !== false, 'chạy HTTP nên chưa có Secure — cần HTTPS khi triển khai', true);

// 3. Xác thực & phân quyền
echo "\n[3] Xác thực & phân quyền\n";
foreach (['/api/students', '/api/fee-records', '/api/history', '/api/reports/summary', '/api/refunds', '/api/student/debts', '/api/receipts/PT-20260901-0001'] as $ep) {
    $r = req('GET', $BASE . $ep, [$TEST]);
    check("Chưa đăng nhập: GET $ep → 401", $r['code'] === 401, "HTTP {$r['code']}");
}
$r = req('GET', "$BASE/backend/api/users.php", [$TEST]);
check("Endpoint cũ /backend/api/users.php yêu cầu đăng nhập (HTTP {$r['code']})", in_array($r['code'], [401, 403, 404], true));
check("Endpoint đăng nhập trùng lặp /backend/api/users.php đã được gỡ bỏ", $r['code'] === 404, 'vẫn là 1 cổng đăng nhập thứ 2 song song với /api/login (tăng bề mặt tấn công)', true);
$sv = login($BASE, 'sv001', 'Student@001');
$r = req('GET', "$BASE/api/students", [$TEST], null, $sv['cookie']);
check('Sinh viên gọi API quản trị /api/students → 403', $r['code'] === 403, "HTTP {$r['code']}");
$r = req('GET', "$BASE/api/student/debts", [$TEST], null, $adm['cookie']);
check('Quản trị gọi API sinh viên /api/student/debts → 403', $r['code'] === 403, "HTTP {$r['code']}");
$r = req('GET', "$BASE/api/student/bills/999999", [$TEST], null, $sv['cookie']);
check('Sinh viên truy cập hóa đơn không thuộc mình → 404', $r['code'] === 404, "HTTP {$r['code']}");

// 4. CSRF
echo "\n[4] CSRF\n";
$r = req('POST', "$BASE/api/students", [$TEST, 'Content-Type: application/json'], ['student_code' => 'SVX01', 'full_name' => 'CSRF Test'], $adm['cookie']);
check('POST không có X-CSRF-Token → 403', $r['code'] === 403, "HTTP {$r['code']}");
$r = req('POST', "$BASE/api/students", [$TEST, 'Content-Type: application/json', 'X-CSRF-Token: ' . str_repeat('a', 64)], ['student_code' => 'SVX01', 'full_name' => 'CSRF Test'], $adm['cookie']);
check('POST với X-CSRF-Token giả → 403', $r['code'] === 403, "HTTP {$r['code']}");

// 5. Injection
echo "\n[5] SQL Injection / XSS / dữ liệu bất thường\n";
$base = req('GET', "$BASE/api/students/search?q=" . rawurlencode('zzzzqqq'), [$TEST], null, $adm['cookie']);
foreach (["' OR '1'='1", "' OR 1=1 -- ", "\" OR \"\"=\"", "1; DROP TABLE students; --", "' UNION SELECT username,password_hash FROM users -- ", "%' AND SLEEP(3) -- "] as $payload) {
    $r = req('GET', "$BASE/api/students/search?q=" . rawurlencode($payload), [$TEST], null, $adm['cookie']);
    $j = json_decode($r['body'], true);
    $rows = is_array($j['data'] ?? null) ? count($j['data']) : 0;
    $bad = $r['code'] >= 500 || str_contains($r['body'], 'SQLSTATE') || str_contains($r['body'], 'password_hash') || $rows > 0 || $r['ms'] > 2500;
    check("Search q=" . json_encode($payload) . " → HTTP {$r['code']}, {$rows} dòng, " . round($r['ms']) . 'ms', !$bad);
}
$r = req('POST', "$BASE/api/login", [$TEST, 'Content-Type: application/json'], ['username' => "admin' -- ", 'password' => 'x']);
check("Login username=\"admin' -- \" bị từ chối (HTTP {$r['code']})", $r['code'] === 401);
$r = req('POST', "$BASE/api/login", [$TEST, 'Content-Type: application/json'], '{"username":"admin","password":');
check("JSON hỏng → 4xx, không lộ lỗi nội bộ (HTTP {$r['code']})", $r['code'] >= 400 && $r['code'] < 500 && !str_contains($r['body'], 'Stack'));
$r = req('POST', "$BASE/api/login", [$TEST, 'Content-Type: application/json'], ['username' => ['$ne' => ''], 'password' => ['x']]);
check("Kiểu dữ liệu sai (mảng thay chuỗi) bị từ chối (HTTP {$r['code']})", $r['code'] >= 400 && $r['code'] < 500);
$r = req('POST', "$BASE/api/login", [$TEST, 'Content-Type: application/json'], ['username' => 'admin', 'password' => 'Admin@123', 'role' => 'admin']);
check("Trường lạ trong body bị từ chối (mass assignment) (HTTP {$r['code']})", $r['code'] === 400);
$js = (string)@file_get_contents(dirname(__DIR__) . '/frontend/js/main.js');
check('Frontend có hàm escape HTML trước khi chèn innerHTML', (bool)preg_match('/function\s+escape\w*\s*\(/i', $js));

// 6. Lộ thông tin lỗi
echo "\n[6] Lộ thông tin lỗi\n";
$r = req('GET', "$BASE/api/khong-ton-tai", [$TEST]);
check("Endpoint không tồn tại → 404 JSON (HTTP {$r['code']})", $r['code'] === 404);
$r = req('GET', "$BASE/api/students", ['X-Tuition-Test-Db: bat-ky', 'X-Test-Force-Exception: 1']);
check('Lỗi 500 không lộ stack trace / đường dẫn', !preg_match('/Stack|\.php|SQLSTATE|C:\\\\/i', $r['body']));
check('Header kích hoạt lỗi 500 (X-Test-Force-Exception) không dùng được ngoài môi trường test',
    $r['code'] !== 500, "HTTP {$r['code']} — bất kỳ client nào gửi X-Tuition-Test-Db (giá trị bất kỳ) + X-Test-Force-Exception đều ép được lỗi 500 (api/index.php)", true);

// 7. Brute-force / enumeration
echo "\n[7] Chống dò mật khẩu / dò tài khoản\n";
$tEx = []; $tNo = [];
for ($i = 0; $i < 3; $i++) {
    $tNo[] = req('POST', "$BASE/api/login", [$TEST, 'Content-Type: application/json'], ['username' => 'khongtontai' . $i, 'password' => 'Wrong@123'])['ms'];
    $tEx[] = req('POST', "$BASE/api/login", [$TEST, 'Content-Type: application/json'], ['username' => 'staff01', 'password' => 'Wrong@123'])['ms'];
}
login($BASE, 'staff01', 'Staff@123'); // reset bộ đếm sai của staff01
$d = abs(array_sum($tEx) / 3 - array_sum($tNo) / 3);
check(sprintf('Thời gian phản hồi tài khoản có/không tồn tại tương đương (chênh %.0f ms)', $d), $d < 80, 'có thể dò tài khoản qua thời gian phản hồi — kiểm tra DUMMY_PASSWORD_HASH (api/lib/config.php) có cùng cost với PASSWORD_HASH_OPTIONS và mật khẩu trong DB không');
// Mọi "cửa test" (header X-...) phải đi qua is_test_request() – chỉ nhận request từ localhost.
$apiSrc = '';
foreach (glob(dirname(__DIR__) . '/api/{lib,handlers}/*.php', GLOB_BRACE) as $f) {
    if (basename($f) !== 'config.php') { $apiSrc .= file_get_contents($f); }
}
$apiSrc .= file_get_contents(dirname(__DIR__) . '/api/index.php');
check('Không đọc trực tiếp header test (X-Tuition-Test-Db, X-Simulation-Enabled...) ngoài is_test_request()',
    !preg_match('/HTTP_X_(TUITION_TEST_DB|SIMULATION|SIMULATE|TEST_FORCE)|getallheaders/', $apiSrc),
    'có đoạn code đọc header test trực tiếp → client từ xa có thể dùng');

// 8. Cấu hình
echo "\n[8] Cấu hình triển khai\n";
$cfg = require dirname(__DIR__) . '/config/config.php';
check('simulation_enabled = false (tắt nút "Mô phỏng thanh toán thành công")', empty($cfg['simulation_enabled']), 'đang bật — sinh viên có thể tự xác nhận thanh toán QR mà không trả tiền; phải tắt khi dùng thật', true);
check('.gitignore loại trừ config/config.php (tệp chứa mật khẩu DB)', str_contains((string)@file_get_contents(dirname(__DIR__) . '/.gitignore'), 'config/config.php'), 'thêm config/config.php vào .gitignore', true);
$r = req('PUT', "$BASE/api/login", [$TEST]);
check("Sai method trên endpoint có thật → 405 (PUT /api/login: HTTP {$r['code']})", $r['code'] === 405);

foreach ([$adm, $sv] as $s) { @unlink($s['cookie']); }
echo "\n=== KẾT QUẢ: $pass PASS, $fail FAIL, $warn WARN ===\n";
exit($fail > 0 ? 1 : 0);
