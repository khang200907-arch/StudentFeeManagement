<?php
/**
 * Giới hạn số request theo IP (rate limit) – hiện dùng cho đăng nhập.
 *
 * Cách hoạt động: mỗi cặp (hành động, IP) có 1 file JSON trong thư mục tạm, lưu danh sách
 * thời điểm các request gần đây. Nếu trong $windowSeconds giây đã có đủ $maxAttempts request
 * thì trả HTTP 429.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/response.php';

/**
 * @param string   $action        Tên hành động, ví dụ 'login'
 * @param int|null $maxAttempts   Số request tối đa trong cửa sổ (mặc định: security.rate_limit_max)
 * @param int|null $windowSeconds Độ dài cửa sổ tính bằng giây (mặc định: security.rate_limit_window)
 */
function check_rate_limit(string $action = 'default', ?int $maxAttempts = null, ?int $windowSeconds = null): void {
    $maxAttempts   = $maxAttempts   ?? (int)app_config('security.rate_limit_max', 30);
    $windowSeconds = $windowSeconds ?? (int)app_config('security.rate_limit_window', 60);

    // Bộ test tự động đăng nhập hàng trăm lần mỗi phút → nới giới hạn và dùng bộ đếm riêng.
    // Chỉ áp dụng cho request test từ localhost (xem is_test_request()).
    $scope = '';
    if (is_test_request()) {
        $maxAttempts = 500;
        $scope = '_test';
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $dir = sys_get_temp_dir() . '/tuition_rate_limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $file = $dir . '/' . md5($action . '_' . $ip . $scope) . '.json';

    // Mở file và khóa độc quyền để 2 request đồng thời không ghi đè bộ đếm của nhau.
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return; // Không ghi được file tạm → bỏ qua giới hạn thay vì chặn người dùng.
    }
    flock($handle, LOCK_EX);

    $now = time();
    $saved = json_decode((string)stream_get_contents($handle), true);
    $recent = array_values(array_filter(
        is_array($saved) ? $saved : [],
        fn($t) => is_int($t) && ($now - $t) < $windowSeconds
    ));

    $isLimited = count($recent) >= $maxAttempts;
    if (!$isLimited) {
        $recent[] = $now;
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($recent));
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($isLimited) {
        json_error('Quá nhiều yêu cầu gửi lên từ địa chỉ này. Vui lòng thử lại sau giây lát.', 'RATE_LIMITED', 429);
    }
}
