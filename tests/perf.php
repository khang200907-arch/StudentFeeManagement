<?php
/**
 * Kiểm thử hiệu năng: với 1 người dùng, mọi thao tác phải phản hồi dưới ngưỡng (mặc định 200 ms).
 *
 * Đo trên database tuition_test (không đụng dữ liệu thật). Yêu cầu Apache + MySQL đang chạy
 * và đã chạy `php tests/run.php` ít nhất 1 lần để tạo tuition_test.
 *
 * Cách dùng:
 *   C:\xampp\php\php.exe tests\perf.php [--limit=200] [--n=30] [--base=http://localhost/tuition-system]
 *
 * Nội dung đo:
 *   1. Tải trang: HTML + CSS + 4 file JS.
 *   2. Đọc: 15 API của admin và sinh viên, mỗi API gọi N lần.
 *   3. Ghi: thêm sinh viên, tạo hồ sơ, miễn giảm, thu tiền, đảo bút toán, hoàn tiền,
 *      đăng ký tín chỉ, thanh toán QR (tạo phiên → poll → xác nhận), thanh toán thẻ.
 *   4. Tải đồng thời 1 / 10 / 25 / 50 người dùng – CHỈ ĐỂ THAM KHẢO, không tính đạt/không đạt.
 * Tiêu chí đạt (mục 1–3, 1 người dùng): thời gian LỚN NHẤT (max) của mỗi thao tác < ngưỡng.
 * Có thao tác vượt ngưỡng → exit code 1.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

require_once dirname(__DIR__) . '/api/lib/config.php'; // PASSWORD_HASH_OPTIONS (để in cost bcrypt)

$opts  = getopt('', ['base::', 'n::', 'limit::']);
$BASE  = rtrim($opts['base'] ?? 'http://localhost/tuition-system', '/');
$N     = (int)($opts['n'] ?? 30);
$LIMIT = (float)($opts['limit'] ?? 200);
$TEST_HEADER = 'X-Tuition-Test-Db: tuition_test';

$failures = [];

// ---------------------------------------------------------------------------
// Tiện ích HTTP
// ---------------------------------------------------------------------------

/** Tạo 1 curl handle (chưa chạy). */
function make_request(string $method, string $url, ?array $session = null, $body = null) {
    global $TEST_HEADER;
    $headers = ['Accept: application/json', 'Content-Type: application/json', $TEST_HEADER];
    if ($session && $method !== 'GET' && !empty($session['csrf'])) {
        $headers[] = 'X-CSRF-Token: ' . $session['csrf'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($session) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $session['cookie']);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $session['cookie']);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    return $ch;
}

/** Gửi 1 request, trả về [mã HTTP, JSON đã giải mã, thời gian ms]. */
function send(string $method, string $path, ?array $session = null, $body = null): array {
    global $BASE;
    $url = str_starts_with($path, 'http') ? $path : $BASE . $path;
    $ch = make_request($method, $url, $session, $body);
    $raw = (string)curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true), curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000];
}

function login(string $username, string $password): array {
    $session = ['cookie' => tempnam(sys_get_temp_dir(), 'perf'), 'csrf' => null];
    [$code, $json, $ms] = send('POST', '/api/login', $session, ['username' => $username, 'password' => $password]);
    if ($code !== 200) {
        fwrite(STDERR, "Đăng nhập $username thất bại (HTTP $code). Hãy chạy tests/run.php trước để nạp lại tuition_test.\n");
        exit(1);
    }
    $session['csrf'] = $json['data']['csrf_token'] ?? null;
    $session['login_ms'] = $ms;
    return $session;
}

function uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function percentile(array $values, float $p): float {
    sort($values);
    $i = (int)ceil($p / 100 * count($values)) - 1;
    return $values[max(0, min(count($values) - 1, $i))];
}

// ---------------------------------------------------------------------------
// In kết quả
// ---------------------------------------------------------------------------

/** Căn lề theo số KÝ TỰ (printf đếm byte nên chữ có dấu làm lệch cột). */
function pad(string $text, int $width): string {
    return $text . str_repeat(' ', max(1, $width - mb_strlen($text, 'UTF-8')));
}

function print_header(string $title): void {
    echo "\n--- $title ---\n";
    printf("%s%8s %8s %8s %8s  %s\n", pad('Thao tác', 56), 'TB(ms)', 'p95', 'max', 'HTTP', 'Kết quả');
}

/** In 1 dòng kết quả; $timesMs là danh sách thời gian đo của thao tác. */
function report(string $label, array $timesMs, string $httpCodes, bool $httpOk = true): void {
    global $LIMIT, $failures;
    $max = max($timesMs);
    $ok = $max < $LIMIT && $httpOk;
    if (!$ok) {
        $failures[] = $label . ($httpOk ? sprintf(' (max %.0f ms)', $max) : " (HTTP $httpCodes)");
    }
    printf("%s%8.1f %8.1f %8.1f %8s  %s\n", pad($label, 56), array_sum($timesMs) / count($timesMs),
        percentile($timesMs, 95), $max, $httpCodes, $ok ? 'ĐẠT' : 'KHÔNG ĐẠT');
}

/** Gửi 1 request ghi, in kết quả, trả về JSON phản hồi. */
function measure_write(string $label, string $method, string $path, array $session, $body, array $okCodes = [200, 201]) {
    [$code, $json, $ms] = send($method, $path, $session, $body);
    report($label, [$ms], (string)$code, in_array($code, $okCodes, true));
    return $json['data'] ?? null;
}

echo "=== KIỂM THỬ HIỆU NĂNG — ngưỡng < {$LIMIT} ms — $BASE (DB: tuition_test) ===\n";

$admin   = login('admin', 'Admin@123');
$checker = login('staff01', 'Staff@123'); // admin thứ 2 để duyệt hoàn tiền (maker-checker)
$student = login('sv002', 'Student@002');

// ---------------------------------------------------------------------------
// 1. Tải trang
// ---------------------------------------------------------------------------
print_header('1. Tải trang (mỗi tệp ' . $N . ' lần)');
$assets = ['/', '/frontend/css/style.css', '/frontend/js/qrcode.js', '/frontend/js/calc.js', '/frontend/js/api.js', '/frontend/js/main.js'];
$pageTotals = array_fill(0, $N, 0.0);
foreach ($assets as $asset) {
    $times = [];
    for ($i = 0; $i < $N; $i++) {
        [$code, , $ms] = send('GET', $asset);
        $times[] = $ms;
        $pageTotals[$i] += $ms;
    }
    report("GET $asset", $times, (string)$code, $code === 200);
}
report('TỔNG tải trang (6 tệp, tải tuần tự)', $pageTotals, '-');

// ---------------------------------------------------------------------------
// 2. Đăng nhập + API đọc
// ---------------------------------------------------------------------------
print_header("2. Đăng nhập & API đọc (mỗi API $N lần)");
$loginTimes = [];
for ($i = 0; $i < 10; $i++) {
    $s = login('staff01', 'Staff@123');
    $loginTimes[] = $s['login_ms'];
    @unlink($s['cookie']);
}
report('POST /api/login (bcrypt cost ' . PASSWORD_HASH_OPTIONS['cost'] . ', 10 lần)', $loginTimes, '200');

$readEndpoints = [
    [$admin, '/api/me'], [$admin, '/api/students'], [$admin, '/api/students/search?q=nguyen'],
    [$admin, '/api/semesters'], [$admin, '/api/rates'], [$admin, '/api/fee-records'], [$admin, '/api/fee-records/2'],
    [$admin, '/api/history'], [$admin, '/api/refunds'], [$admin, '/api/reports/summary'], [$admin, '/api/nav/badges'],
    [$admin, '/api/receipts/PT-20260901-0001'],
    [$student, '/api/student/semesters'], [$student, '/api/student/debts'], [$student, '/api/student/history'],
];
foreach ($readEndpoints as [$session, $path]) {
    $times = []; $codes = [];
    for ($i = 0; $i < $N; $i++) {
        [$code, , $ms] = send('GET', $path, $session);
        $times[] = $ms;
        $codes[$code] = true;
    }
    $who = $session === $student ? ' (SV)' : '';
    report("GET $path$who", $times, implode(',', array_keys($codes)), array_keys($codes) === [200]);
}

// ---------------------------------------------------------------------------
// 3. Thao tác ghi (mỗi thao tác 1 lần, theo đúng thứ tự nghiệp vụ)
// ---------------------------------------------------------------------------
print_header('3. Thao tác ghi (luồng nghiệp vụ thật)');
$today = date('Y-m-d');
$code  = 'PF' . substr((string)time(), -6);

$newStudent = measure_write('POST /api/students — thêm sinh viên', 'POST', '/api/students', $admin,
    ['student_code' => $code, 'full_name' => 'Nguyễn Văn Hiệu Năng', 'class_name' => 'PERF01']);
$studentId = (int)($newStudent['id'] ?? 0);

$fee = measure_write('POST /api/fee-records — tạo hồ sơ học phí', 'POST', '/api/fee-records', $admin,
    ['student_id' => $studentId, 'semester_id' => 3, 'registered_credits' => 15]);
$feeId = (int)($fee['id'] ?? 0);

measure_write('PUT /api/fee-records/{id}/exemption — miễn giảm 10%', 'PUT', "/api/fee-records/$feeId/exemption", $admin,
    ['type' => 'percent', 'value' => 10, 'reason' => 'Kiểm thử hiệu năng']);

$pay = measure_write('POST /api/fee-records/{id}/payments — thu tiền', 'POST', "/api/fee-records/$feeId/payments", $admin,
    ['amount' => 1000000, 'method' => 'Tiền mặt', 'entry_date' => $today, 'note' => 'perf', 'request_id' => uuid()]);
$ledgerId = (int)($pay['id'] ?? 0);

measure_write('POST /api/ledger/{id}/reversal — đảo bút toán', 'POST', "/api/ledger/$ledgerId/reversal", $admin,
    ['reason' => 'Kiểm thử hiệu năng', 'request_id' => uuid()]);

// Nộp đủ rồi thêm miễn giảm → nộp thừa → lập và duyệt hoàn tiền
[, $detail] = send('GET', "/api/fee-records/$feeId", $admin);
$outstanding = (int)($detail['data']['outstanding_amount'] ?? $detail['data']['outstanding'] ?? 0);
measure_write('POST .../payments — nộp đủ số còn nợ', 'POST', "/api/fee-records/$feeId/payments", $admin,
    ['amount' => $outstanding, 'method' => 'Chuyển khoản', 'entry_date' => $today, 'note' => 'perf', 'request_id' => uuid()]);
measure_write('PUT .../exemption — tăng miễn giảm 20% (tạo tiền thừa)', 'PUT', "/api/fee-records/$feeId/exemption", $admin,
    ['type' => 'percent', 'value' => 20, 'reason' => 'Kiểm thử hiệu năng']);
$refund = measure_write('POST /api/fee-records/{id}/refunds — lập hoàn tiền', 'POST', "/api/fee-records/$feeId/refunds", $admin,
    ['amount' => 100000, 'reason' => 'Kiểm thử hiệu năng', 'request_id' => uuid()]);
$refundId = (int)($refund['id'] ?? 0);
measure_write('POST /api/refunds/{id}/complete — duyệt (admin khác)', 'POST', "/api/refunds/$refundId/complete", $checker, []);

// Cổng sinh viên: sv011 chưa có hồ sơ → đăng ký tạo mới; sv002 còn nợ hồ sơ #2 → thanh toán
$newcomer = login('sv011', 'Student@011');
measure_write('POST /api/student/registrations — đăng ký 15 TC (sv011)', 'POST', '/api/student/registrations', $newcomer,
    ['semester_id' => 3, 'credits' => 15, 'request_id' => uuid()]);
@unlink($newcomer['cookie']);

$sess = measure_write('POST .../payment-sessions — tạo mã QR', 'POST', '/api/student/fee-records/2/payment-sessions', $student,
    ['amount' => 100000, 'method' => 'Mã QR', 'request_id' => uuid()]);
$sessionId = (int)($sess['session_id'] ?? 0);
$pollTimes = [];
for ($i = 0; $i < $N; $i++) {
    [$c, , $ms] = send('GET', "/api/student/payment-sessions/$sessionId", $student);
    $pollTimes[] = $ms;
}
report("GET .../payment-sessions/{id} — poll QR ($N lần)", $pollTimes, (string)$c, $c === 200);
measure_write('POST .../simulate-confirm — xác nhận QR + hóa đơn', 'POST', "/api/student/payment-sessions/$sessionId/simulate-confirm", $student, []);
measure_write('POST .../card-payments — thanh toán thẻ', 'POST', '/api/student/fee-records/2/card-payments', $student,
    ['amount' => 100000, 'card_number' => '4111111111111111', 'cardholder_name' => 'TRAN THI BINH', 'expiry' => '12/30', 'cvv' => '123', 'request_id' => uuid()]);

// ---------------------------------------------------------------------------
// 4. Tải đồng thời: mỗi người dùng ảo có phiên riêng, gọi liên tục 20 request đọc
// ---------------------------------------------------------------------------
echo "\n--- 4. Tải đồng thời – tham khảo, không tính vào kết luận (mỗi người dùng 20 request đọc) ---\n";
printf("%-14s %-10s %-10s %-10s %-10s %-10s %s\n", 'Người dùng', 'Requests', 'Thời gian', 'Req/giây', 'p95(ms)', 'max(ms)', 'Lỗi');
$loadEndpoints = ['/api/students', '/api/fee-records', '/api/history', '/api/reports/summary', '/api/me'];
$sessions = [];
foreach ([1, 10, 25, 50] as $users) {
    while (count($sessions) < $users) {
        $sessions[] = login('admin', 'Admin@123');
    }
    $remaining = array_fill(0, $users, 20);
    $multi = curl_multi_init();
    $owner = [];
    $times = [];
    $errors = 0;
    $start = microtime(true);

    $next = function (int $u) use (&$remaining, &$owner, $multi, $loadEndpoints, $sessions, $BASE) {
        if ($remaining[$u]-- <= 0) { return; }
        $ch = make_request('GET', $BASE . $loadEndpoints[array_rand($loadEndpoints)], $sessions[$u]);
        curl_multi_add_handle($multi, $ch);
        $owner[spl_object_id($ch)] = $u;
    };
    for ($u = 0; $u < $users; $u++) { $next($u); }

    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);
        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $times[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            if ($info['result'] !== CURLE_OK || curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) { $errors++; }
            $u = $owner[spl_object_id($ch)];
            unset($owner[spl_object_id($ch)]);
            curl_multi_remove_handle($multi, $ch);
            $next($u);
        }
    } while ($running || $owner);

    $elapsed = microtime(true) - $start;
    printf("%-14d %-10d %-10s %-10.0f %-10.1f %-10.1f %d\n", $users, count($times), sprintf('%.2fs', $elapsed),
        count($times) / $elapsed, percentile($times, 95), max($times), $errors);
}

foreach (array_merge($sessions, [$admin, $checker, $student]) as $s) { @unlink($s['cookie']); }

echo "\n=== KẾT LUẬN: " . (empty($failures)
    ? "TẤT CẢ thao tác phản hồi dưới {$LIMIT} ms ✔"
    : count($failures) . " thao tác KHÔNG ĐẠT:\n  - " . implode("\n  - ", $failures)) . " ===\n";
exit(empty($failures) ? 0 : 1);
