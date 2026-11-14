<?php
/**
 * Kết nối cơ sở dữ liệu (PDO) và tiện ích transaction.
 *
 * - get_db_connection(): trả về 1 kết nối PDO dùng chung cho cả request.
 * - db_transaction():    chạy một đoạn code trong transaction, tự rollback khi lỗi
 *                        và tự thử lại 1 lần nếu MySQL báo deadlock.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Lấy cấu hình kết nối DB. Tên database được chọn theo thứ tự ưu tiên:
 *   1. Tham số $overrideDbName (dùng trong script CLI)
 *   2. Biến môi trường TUITION_DB (tests/run.php đặt = tuition_test)
 *   3. Request của bộ test từ localhost → tuition_test
 *   4. Giá trị trong config/config.php
 */
function get_db_config(?string $overrideDbName = null): array {
    $dbCfg = app_config('db', []);

    if ($overrideDbName !== null) {
        $dbCfg['name'] = $overrideDbName;
    } elseif (getenv('TUITION_DB')) {
        $dbCfg['name'] = getenv('TUITION_DB');
    } elseif (is_test_request()) {
        $dbCfg['name'] = 'tuition_test';
    }
    return $dbCfg;
}

function get_db_connection(?string $overrideDbName = null): PDO {
    static $connections = [];

    $key = $overrideDbName ?? 'default';
    if (isset($connections[$key])) {
        return $connections[$key];
    }

    $cfg = get_db_config($overrideDbName);
    // charset=utf8mb4 trong DSN đã đủ để MySQL nhận/trả tiếng Việt đúng, không cần thêm SET NAMES.
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4');

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // lỗi SQL → ném exception
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // fetch() trả mảng ['cột' => giá trị]
        PDO::ATTR_EMULATE_PREPARES   => false,                  // prepared statement thật → chống SQL injection
    ]);
    // Cho NOW(), CURDATE()... của MySQL cùng múi giờ Việt Nam với PHP.
    $pdo->exec("SET time_zone = '+07:00'");

    $connections[$key] = $pdo;
    return $pdo;
}

/**
 * Chạy $callback($pdo) trong một transaction.
 *
 * - Thành công → COMMIT và trả về kết quả của callback.
 * - Có lỗi     → ROLLBACK rồi ném lại lỗi.
 * - Deadlock   → ROLLBACK, chờ 50ms rồi thử lại đúng 1 lần.
 *
 * Lưu ý: nếu callback gọi json_error() (hàm này exit ngay), transaction chưa commit
 * sẽ bị MySQL tự rollback khi kết nối đóng.
 */
function db_transaction(callable $callback, ?PDO $pdo = null) {
    $pdo = $pdo ?? get_db_connection();
    $maxAttempts = 2;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $pdo->beginTransaction();
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // MySQL deadlock: mã lỗi 1213 hoặc SQLSTATE 40001
            $isDeadlock = $e instanceof PDOException
                && ($e->getCode() === '40001' || ($e->errorInfo[1] ?? null) === 1213);
            if ($isDeadlock && $attempt < $maxAttempts) {
                usleep(50000);
                continue;
            }
            throw $e;
        }
    }
}
