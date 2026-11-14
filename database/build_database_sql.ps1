# Sinh lại database/database.sql từ 3 tệp gốc: schema.sql + create_app_user.sql + seed.sql
# Chạy tại thư mục gốc dự án:  powershell -File database\build_database_sql.ps1
# Chạy lại mỗi khi sửa một trong 3 tệp gốc để database.sql không bị lỗi thời.

$ErrorActionPreference = 'Stop'
$dir = $PSScriptRoot
$utf8NoBom = New-Object Text.UTF8Encoding $false

$header = @"
-- ============================================================================
-- Hệ thống Quản lý Học phí Sinh viên - TỆP HỢP NHẤT (tự sinh, KHÔNG sửa tay)
--
-- = database/schema.sql + database/create_app_user.sql + database/seed.sql
-- Muốn thay đổi cấu trúc/dữ liệu: sửa 3 tệp gốc rồi sinh lại tệp này bằng lệnh
--   powershell -File database\build_database_sql.ps1
--
-- Cách nạp: chọn database tuition_system rồi Import tệp này, sau đó chạy
--   C:\xampp\php\php.exe database\seed_users.php
-- ============================================================================


"@

$parts = foreach ($name in 'schema.sql', 'create_app_user.sql', 'seed.sql') {
    $content = [IO.File]::ReadAllText((Join-Path $dir $name)).TrimStart([char]0xFEFF)
    "-- >>>>>>>>>> BẮT ĐẦU: database/$name >>>>>>>>>>`n$content`n-- <<<<<<<<<< KẾT THÚC: database/$name <<<<<<<<<<`n`n"
}

[IO.File]::WriteAllText((Join-Path $dir 'database.sql'), $header + ($parts -join ''), $utf8NoBom)
Write-Host "Đã sinh lại database/database.sql"
