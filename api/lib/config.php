<?php
/**
 * Cấu hình dùng chung cho toàn bộ backend.
 *
 * File này gom 3 việc mà trước đây bị lặp lại ở nhiều nơi:
 *   1. app_config()      – đọc config/config.php (chỉ đọc 1 lần cho mỗi request).
 *   2. is_test_request() – nhận diện request của bộ kiểm thử tự động.
 *   3. hash_password()   – băm mật khẩu với tham số cố định (bcrypt, xem PASSWORD_HASH_OPTIONS).
 */

declare(strict_types=1);

/**
 * Đọc một giá trị cấu hình theo đường dẫn dạng "nhóm.khóa".
 *
 * Ví dụ: app_config('session.idle_timeout', 1800)
 *        app_config('app.school_name', '[Tên trường]')
 *        app_config('db')  → trả về cả mảng cấu hình DB
 *
 * Nếu chưa có config/config.php thì dùng config/config.example.php.
 */
function app_config(string $path, $default = null) {
    static $config = null;
    if ($config === null) {
        $configDir = dirname(__DIR__, 2) . '/config';
        $file = file_exists("$configDir/config.php") ? "$configDir/config.php" : "$configDir/config.example.php";
        $loaded = require $file;
        $config = is_array($loaded) ? $loaded : [];
    }

    $value = $config;
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value;
}

/**
 * Request này có phải do bộ kiểm thử tự động (tests/run.php, tests/perf.php...) gửi không?
 *
 * Bộ test gửi header "X-Tuition-Test-Db: tuition_test" để backend chuyển sang database
 * tuition_test và bật một số "cửa test" (ép lỗi cổng thanh toán, nới rate limit...).
 *
 * BẢO MẬT: header thì ai cũng gửi được, nên BẮT BUỘC kiểm tra thêm request phải đến
 * từ chính máy chủ (localhost). Mọi cửa test trong dự án đều phải đi qua hàm này.
 */
function is_test_request(): bool {
    if (($_SERVER['HTTP_X_TUITION_TEST_DB'] ?? '') !== 'tuition_test') {
        return false;
    }
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($remoteIp, ['127.0.0.1', '::1'], true);
}

/**
 * Lấy giá trị một header HTTP do bộ test gửi (ví dụ 'X-Simulate-Gateway-Fail').
 * Trả về null nếu không phải request test hoặc không có header đó.
 */
function get_test_header(string $name): ?string {
    if (!is_test_request()) {
        return null;
    }
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? $_SERVER['REDIRECT_' . $key] ?? null;
}

/**
 * Thông số bcrypt dùng cho MỌI mật khẩu trong hệ thống.
 *
 * Cố định cost thay vì PASSWORD_DEFAULT, vì cost mặc định khác nhau theo phiên bản PHP
 * (PHP 8.1–8.3: 10, PHP 8.4+: 12). Nếu cost của mật khẩu thật khác cost của DUMMY_PASSWORD_HASH
 * thì thời gian phản hồi đăng nhập sẽ tiết lộ username có tồn tại hay không.
 *
 * cost 11 ≈ 80–90 ms/lần kiểm tra → đăng nhập < 200 ms (yêu cầu hiệu năng), vẫn trên mức tối
 * thiểu 10 mà OWASP khuyến nghị. Mỗi +1 cost = chậm gấp đôi (cost 12 ≈ 180 ms).
 * Đổi cost ở đây thì PHẢI sinh lại DUMMY_PASSWORD_HASH cùng cost:
 *   php -r "echo password_hash('dummy', PASSWORD_BCRYPT, ['cost' => 11]);"
 * Mật khẩu cũ khác cost sẽ tự được băm lại ở lần đăng nhập thành công kế tiếp (xem login_user()).
 */
const PASSWORD_HASH_OPTIONS = ['cost' => 11];

/**
 * Hash giả (bcrypt, cùng cost với PASSWORD_HASH_OPTIONS) dùng khi username không tồn tại:
 * vẫn chạy password_verify() để thời gian phản hồi bằng với trường hợp sai mật khẩu.
 */
const DUMMY_PASSWORD_HASH = '$2y$11$4IDDQkRZdPPlFUkDK9XouOoS54JK3sTVAEsZLNO24PH5EdzebxJRO';

function hash_password(string $plainPassword): string {
    return password_hash($plainPassword, PASSWORD_BCRYPT, PASSWORD_HASH_OPTIONS);
}
