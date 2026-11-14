<?php
/**
 * Xác thực người dùng và quản lý phiên (session).
 *
 * Luồng chính:
 *   login_user()        – kiểm tra mật khẩu, khóa tài khoản khi sai nhiều lần, tạo phiên.
 *   start_session_safe()– mở phiên PHP + hủy phiên quá hạn (30 phút rảnh / 8 giờ tối đa).
 *   require_admin()     – chặn request nếu người gọi không phải admin.
 *   require_student()   – chặn request nếu người gọi không phải sinh viên; trả về thông tin SV.
 *   logout_user()       – xóa phiên.
 *
 * Dữ liệu lưu trong $_SESSION sau khi đăng nhập:
 *   user_id, username, full_name, role ('admin' | 'student'), student_id,
 *   must_change_password, created_at, last_activity, csrf_token, logout_token
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/rate_limit.php';

/**
 * Mở phiên PHP (an toàn khi gọi nhiều lần) và hủy phiên nếu đã quá hạn.
 *
 * @param bool $rejectExpired true  = phiên quá hạn → trả 401 và dừng request (mặc định).
 *                            false = phiên quá hạn → xóa và mở phiên trống mới (dùng khi đăng nhập).
 */
function start_session_safe(bool $rejectExpired = true): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $idleTimeout = (int)app_config('session.idle_timeout', 1800);  // 30 phút
    $maxLifetime = (int)app_config('session.max_lifetime', 28800); // 8 giờ
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    session_name((string)app_config('session.name', 'TUITION_SESSID'));
    ini_set('session.gc_maxlifetime', (string)$maxLifetime);
    session_set_cookie_params([
        'lifetime' => 0,          // cookie mất khi đóng trình duyệt
        'path'     => (string)app_config('session.cookie_path', '/'),
        'domain'   => '',
        'secure'   => $isHttps,   // chỉ gửi qua HTTPS khi đang chạy HTTPS
        'httponly' => true,       // JavaScript không đọc được cookie phiên
        'samesite' => 'Strict',   // chống CSRF từ trang khác
    ]);

    session_start();

    $expiredError = null;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
        // Quá lâu không thao tác
        $expiredError = ['Phiên làm việc đã hết hạn sau 30 phút không hoạt động. Vui lòng đăng nhập lại.', 'SESSION_EXPIRED'];
    } elseif (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at']) > $maxLifetime) {
        // Phiên tồn tại quá thời gian tối đa, kể cả khi vẫn đang thao tác
        $expiredError = ['Phiên làm việc đã hết hạn sau 8 giờ tối đa. Vui lòng đăng nhập lại.', 'SESSION_MAX_LIFETIME'];
    }

    if ($expiredError !== null) {
        session_unset();
        session_destroy();
        if ($rejectExpired) {
            json_error($expiredError[0], $expiredError[1], 401);
        }
        session_start(); // mở phiên trống mới để đăng nhập tiếp
        return;
    }

    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
}

/** Chỉ cho admin đi tiếp: chưa đăng nhập → 401, sai vai trò → 403. */
function require_admin(): void {
    start_session_safe();
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        json_error('Vui lòng đăng nhập để tiếp tục.', 'UNAUTHORIZED', 401);
    }
    if ($_SESSION['role'] !== 'admin') {
        json_error('Bạn không có quyền truy cập chức năng này.', 'FORBIDDEN', 403);
    }
}

/**
 * Chỉ cho sinh viên đi tiếp và trả về bản ghi users của sinh viên đó (đọc lại từ DB).
 *
 * @param bool $allowMustChangePassword true = vẫn cho qua khi SV chưa đổi mật khẩu tạm
 *                                      (chỉ dùng cho endpoint đổi mật khẩu).
 */
function require_student(bool $allowMustChangePassword = false): array {
    start_session_safe();
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        json_error('Vui lòng đăng nhập để tiếp tục.', 'UNAUTHORIZED', 401);
    }
    if ($_SESSION['role'] !== 'student') {
        json_error('Bạn không có quyền truy cập chức năng này.', 'FORBIDDEN', 403);
    }

    $stmt = get_db_connection()->prepare(
        "SELECT id, username, password_hash, full_name, role, student_id, must_change_password
         FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['role'] !== 'student' || empty($user['student_id'])) {
        json_error('Bạn không có quyền truy cập chức năng này.', 'FORBIDDEN', 403);
    }
    if (!$allowMustChangePassword && !empty($user['must_change_password'])) {
        json_error('Vui lòng đổi mật khẩu trước khi tiếp tục.', 'PASSWORD_CHANGE_REQUIRED', 403);
    }

    return $user;
}

/**
 * ID người đang đăng nhập. Gọi SAU require_admin()/require_student().
 * Không có phiên → 401 (không bao giờ "đoán" một user id mặc định).
 */
function current_user_id(): int {
    if (empty($_SESSION['user_id'])) {
        json_error('Vui lòng đăng nhập để tiếp tục.', 'UNAUTHORIZED', 401);
    }
    return (int)$_SESSION['user_id'];
}

/** Chuyển 1 dòng bảng users thành dữ liệu công khai trả về cho frontend (không có password_hash). */
function public_user_fields(array $user): array {
    return [
        'id'                   => (int)$user['id'],
        'username'             => $user['username'] ?? '',
        'full_name'            => $user['full_name'] ?? '',
        'role'                 => $user['role'] ?? '',
        'student_id'           => isset($user['student_id']) ? (int)$user['student_id'] : null,
        'must_change_password' => (int)($user['must_change_password'] ?? 0),
    ];
}

/** Thông tin người đang đăng nhập, hoặc null nếu chưa đăng nhập. */
function get_session_user(): ?array {
    start_session_safe();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = get_db_connection()->prepare(
        "SELECT id, username, full_name, role, student_id, must_change_password FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    // Nếu tài khoản vừa bị xóa khỏi DB thì dùng tạm dữ liệu trong phiên.
    return public_user_fields($user ?: [
        'id'                   => $_SESSION['user_id'],
        'username'             => $_SESSION['username'] ?? '',
        'full_name'            => $_SESSION['full_name'] ?? '',
        'role'                 => $_SESSION['role'] ?? '',
        'student_id'           => $_SESSION['student_id'] ?? null,
        'must_change_password' => $_SESSION['must_change_password'] ?? 0,
    ]);
}

/**
 * Đăng nhập. Thành công → trả thông tin người dùng + csrf_token + logout_token.
 * Thất bại → json_error() (dừng request).
 *
 * Các bước:
 *   1. Rate limit theo IP.
 *   2. Tìm user. Không có → vẫn chạy password_verify với hash giả để thời gian phản hồi
 *      giống hệt trường hợp sai mật khẩu (không lộ username có tồn tại hay không).
 *   3. Tài khoản đang bị khóa → 409. Hết hạn khóa → mở khóa.
 *   4. Sai mật khẩu → tăng failed_logins, đủ ngưỡng thì khóa N phút.
 *   5. Đúng mật khẩu → reset bộ đếm, tạo phiên mới (chống session fixation).
 */
function login_user(string $username, string $password): array {
    check_rate_limit('login');

    $maxAttempts    = (int)app_config('security.max_login_attempts', 5);
    $lockoutMinutes = (int)app_config('security.lockout_minutes', 15);
    $db = get_db_connection();

    // Bước 2: tìm user
    $stmt = $db->prepare(
        "SELECT id, username, password_hash, full_name, role, student_id, must_change_password, failed_logins, locked_until
         FROM users WHERE username = :username LIMIT 1"
    );
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        password_verify($password, DUMMY_PASSWORD_HASH); // chỉ để cân bằng thời gian
        log_audit(null, 'login_failed', 'users', null, ['username' => $username, 'reason' => 'user_not_found']);
        json_error('Sai tên đăng nhập hoặc mật khẩu.', 'INVALID_CREDENTIALS', 401);
    }

    $userId = (int)$user['id'];
    $resetFailedLogins = $db->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = :id");

    // Bước 3: tài khoản đang bị khóa?
    if (!empty($user['locked_until'])) {
        if (strtotime($user['locked_until']) > time()) {
            log_audit($userId, 'login_failed', 'users', (string)$userId, ['username' => $username, 'reason' => 'account_locked']);
            json_error("Tài khoản đã bị tạm khóa {$lockoutMinutes} phút do nhập sai quá {$maxAttempts} lần. Vui lòng thử lại sau.", 'ACCOUNT_LOCKED', 409);
        }
        $resetFailedLogins->execute(['id' => $userId]);
        $user['failed_logins'] = 0;
    }

    // Bước 4: sai mật khẩu
    if (!password_verify($password, $user['password_hash'])) {
        $failedCount = (int)$user['failed_logins'] + 1;
        $lockedUntil = $failedCount >= $maxAttempts ? date('Y-m-d H:i:s', time() + $lockoutMinutes * 60) : null;

        $db->prepare("UPDATE users SET failed_logins = :failed, locked_until = :locked WHERE id = :id")
           ->execute(['failed' => $failedCount, 'locked' => $lockedUntil, 'id' => $userId]);

        log_audit($userId, 'login_failed', 'users', (string)$userId, ['username' => $username, 'failed_count' => $failedCount]);
        json_error('Sai tên đăng nhập hoặc mật khẩu.', 'INVALID_CREDENTIALS', 401);
    }

    // Bước 5: đăng nhập thành công
    $resetFailedLogins->execute(['id' => $userId]);

    // Hash cũ khác thông số hiện tại (vd đổi bcrypt cost) → băm lại ngay khi đang có mật khẩu gốc
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, PASSWORD_HASH_OPTIONS)) {
        $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
           ->execute(['hash' => hash_password($password), 'id' => $userId]);
    }

    start_session_safe(false);   // cookie phiên cũ đã hết hạn cũng không chặn việc đăng nhập lại
    session_regenerate_id(true); // đổi session id sau khi đăng nhập → chống session fixation

    $publicUser = public_user_fields($user);
    $_SESSION['user_id']              = $userId;
    $_SESSION['username']             = $publicUser['username'];
    $_SESSION['full_name']            = $publicUser['full_name'];
    $_SESSION['role']                 = $publicUser['role'];
    $_SESSION['student_id']           = $publicUser['student_id'];
    $_SESSION['must_change_password'] = $publicUser['must_change_password'];
    $_SESSION['created_at']           = time();
    $_SESSION['last_activity']        = time();
    $_SESSION['csrf_token']           = bin2hex(random_bytes(32)); // gửi kèm mọi request ghi (header X-CSRF-Token)
    $_SESSION['logout_token']         = bin2hex(random_bytes(32)); // dùng cho đăng xuất khi đóng tab (sendBeacon)

    log_audit($userId, 'login_success', 'users', (string)$userId, ['username' => $username]);

    if ($user['role'] === 'admin') {
        init_admin_nav_baseline($db, $userId);
    }

    return $publicUser + [
        'csrf_token'   => $_SESSION['csrf_token'],
        'logout_token' => $_SESSION['logout_token'],
    ];
}

/**
 * Lần đầu admin đăng nhập: đánh dấu mọi giao dịch hiện có là "đã xem" để badge
 * "giao dịch mới" trên menu không hiện toàn bộ lịch sử cũ. Lỗi ở bước này không chặn đăng nhập.
 */
function init_admin_nav_baseline(PDO $db, int $userId): void {
    try {
        $check = $db->prepare("SELECT user_id FROM user_nav_state WHERE user_id = :uid AND nav_key = 'history'");
        $check->execute(['uid' => $userId]);
        if ($check->fetch()) {
            return;
        }
        $maxEntryId = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM ledger_entries")->fetchColumn();
        $db->prepare("INSERT INTO user_nav_state (user_id, nav_key, last_seen_entry_id, updated_at) VALUES (:uid, 'history', :maxId, NOW())")
           ->execute(['uid' => $userId, 'maxId' => $maxEntryId]);
    } catch (Throwable $e) {
        error_log('init_admin_nav_baseline: ' . $e->getMessage());
    }
}

/** Đăng xuất: ghi audit, xóa dữ liệu phiên và cookie. */
function logout_user(): void {
    start_session_safe();
    if (!empty($_SESSION['user_id'])) {
        log_audit((int)$_SESSION['user_id'], 'logout', 'users', (string)$_SESSION['user_id']);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
