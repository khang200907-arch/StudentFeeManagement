<?php
/**
 * Cấu hình mẫu - Hệ thống Quản lý Học phí Sinh viên
 *
 * Cách dùng: chép file này thành config/config.php rồi sửa cho máy của bạn.
 * config.php KHÔNG đưa lên Git (đã có trong .gitignore). Nếu chưa có config.php,
 * backend dùng tạm file này.
 *
 * Đọc giá trị trong code bằng app_config('nhóm.khóa'), ví dụ app_config('session.idle_timeout').
 */

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'tuition_system',
        'user'    => 'tuition_user',
        'pass'    => 'TuitionApp2026!Sec', // ĐỔI khi triển khai thật (và sửa cả database/create_app_user.sql)
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name'         => 'TUITION_SESSID',
        'idle_timeout' => 1800,   // giây: tự đăng xuất sau 30 phút không thao tác
        'max_lifetime' => 28800,  // giây: phiên tối đa 8 giờ
        'cookie_path'  => '/',
    ],
    'security' => [
        'max_login_attempts' => 5,    // sai mật khẩu bấy nhiêu lần thì khóa tài khoản
        'lockout_minutes'    => 15,   // thời gian khóa
        'rate_limit_window'  => 60,   // giây
        'rate_limit_max'     => 30,   // số lần gọi /api/login tối đa mỗi IP trong 1 cửa sổ
    ],
    'app' => [
        'school_name' => '[Tên trường]', // in trên biên lai và hóa đơn
    ],

    // Nút "Mô phỏng thanh toán thành công" cho thanh toán Mã QR.
    // true  = bật (demo/học tập: hệ thống không nối ngân hàng thật nên cần nút này để hoàn tất QR).
    // false = tắt. BẮT BUỘC đặt false khi dùng thật, nếu không sinh viên tự xác nhận
    //         thanh toán mà không trả tiền. Mặc định trong code (khi thiếu khóa này) là false.
    'simulation_enabled' => true,
];
