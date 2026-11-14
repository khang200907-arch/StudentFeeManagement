<?php
/**
 * Nạp DỮ LIỆU LỚN vào database tuition_test để kiểm thử hiệu năng ở quy mô thật.
 * KHÔNG chạy trên tuition_system.
 *
 * Cách dùng:  C:\xampp\php\php.exe tests\seed_large.php [--students=5000]
 * Sau đó:     C:\xampp\php\php.exe tests\perf.php
 * Muốn quay về dữ liệu mẫu nhỏ: C:\xampp\php\php.exe tests\run.php
 *
 * Mỗi sinh viên mới có hồ sơ học phí ở 2 học kỳ (id 3 và 4) và 1 khoản thu ở học kỳ 3.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$opts = getopt('', ['students::']);
$total = max(1, (int)($opts['students'] ?? 5000));

$pdo = new PDO('mysql:host=127.0.0.1;dbname=tuition_test;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$adminId = (int)$pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();
$rates = $pdo->query("SELECT semester_id, price_per_credit FROM credit_rates")->fetchAll(PDO::FETCH_KEY_PAIR);
$runTag = substr(bin2hex(random_bytes(3)), 0, 4); // để chạy nhiều lần không trùng mã SV

$lastNames = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Huỳnh', 'Phan', 'Vũ', 'Võ', 'Đặng', 'Bùi', 'Đỗ'];
$middle    = ['Văn', 'Thị', 'Hữu', 'Minh', 'Ngọc', 'Thanh', 'Quốc', 'Gia'];
$first     = ['An', 'Bình', 'Cường', 'Dũng', 'Hà', 'Hòa', 'Khánh', 'Lan', 'Long', 'Mai', 'Nam', 'Phúc', 'Quân', 'Sơn', 'Trang', 'Tú', 'Vy'];

// Dùng đúng hàm backend tạo cột name_search (tìm kiếm không dấu)
require_once dirname(__DIR__) . '/api/lib/validate.php';

$start = microtime(true);
$batch = 500;
for ($offset = 0; $offset < $total; $offset += $batch) {
    $n = min($batch, $total - $offset);
    $pdo->beginTransaction();

    // 1. Sinh viên
    $rows = []; $params = [];
    for ($i = 0; $i < $n; $i++) {
        $name = $lastNames[array_rand($lastNames)] . ' ' . $middle[array_rand($middle)] . ' ' . $first[array_rand($first)];
        $rows[] = '(?, ?, ?, ?)';
        array_push($params, sprintf('LG%s%05d', $runTag, $offset + $i), $name, generate_name_search($name), 'K' . (60 + ($offset + $i) % 8));
    }
    $pdo->prepare("INSERT INTO students (student_code, full_name, name_search, class_name) VALUES " . implode(',', $rows))->execute($params);
    $firstId = (int)$pdo->lastInsertId();
    $studentIds = range($firstId, $firstId + $n - 1);

    // 2. Hồ sơ học phí ở học kỳ 3 và 4
    $rows = []; $params = [];
    foreach ($studentIds as $sid) {
        foreach ([3, 4] as $sem) {
            $credits = random_int(12, 24);
            $rows[] = '(?, ?, ?, ?, ?)';
            array_push($params, $sid, $sem, $credits, $rates[$sem], $credits * $rates[$sem]);
        }
    }
    $pdo->prepare("INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition) VALUES " . implode(',', $rows))->execute($params);
    $firstFee = (int)$pdo->lastInsertId();

    // 3. Một khoản thu cho hồ sơ học kỳ 3 (id lẻ trong mỗi cặp)
    $rows = []; $params = [];
    for ($k = 0; $k < $n; $k++) {
        $feeId = $firstFee + $k * 2;
        $rows[] = "(?, 'payment', ?, 'Chuyển khoản', CURDATE(), ?, ?)";
        array_push($params, $feeId, random_int(1, 10) * 500000, 'perf-' . $runTag . '-' . $feeId, $adminId);
    }
    $pdo->prepare("INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, request_id, created_by) VALUES " . implode(',', $rows))->execute($params);

    $pdo->commit();
    printf("\r  Đã nạp %d / %d sinh viên", $offset + $n, $total);
}

$counts = $pdo->query("SELECT (SELECT COUNT(*) FROM students), (SELECT COUNT(*) FROM fee_records), (SELECT COUNT(*) FROM ledger_entries)")->fetch(PDO::FETCH_NUM);
printf("\nXong sau %.1fs. tuition_test hiện có: %d sinh viên, %d hồ sơ, %d giao dịch.\n", microtime(true) - $start, ...$counts);
