<?php
/**
 * Cổng thanh toán MÔ PHỎNG (không kết nối ngân hàng/thẻ thật).
 *
 *   SimulatedGateway     – dùng cho thanh toán chuyển khoản/QR: luôn thành công, trừ khi test ép lỗi.
 *   SimulatedCardGateway – dùng cho thẻ Visa/Mastercard, kết quả phụ thuộc số thẻ kiểm thử:
 *       4111 1111 1111 1111 → chấp thuận
 *       4000 0000 0000 0002 → timeout (HTTP 502)
 *       số thẻ khác (vd 5555 5555 5555 4444) → từ chối
 *
 * Muốn nối cổng thật sau này: viết class mới implements PaymentGatewayInterface.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

interface PaymentGatewayInterface {
    /**
     * Thực hiện trừ tiền.
     *
     * @param int    $amount   Số tiền VND (> 0)
     * @param string $method   Phương thức thanh toán
     * @param array  $metadata Thông tin thêm (fee_record_id, student_id, request_id, card_number...)
     * @return array ['status' => 'success'|'failed'|'declined'|'timeout', 'transaction_ref' => ?string, 'message' => string, ...]
     */
    public function charge(int $amount, string $method, array $metadata = []): array;
}

/** Mã tham chiếu giao dịch cổng: GW-YYYYMMDD-<12 ký tự hex>, ví dụ GW-20261112-A1B2C3D4E5F6. */
function generate_gateway_ref(): string {
    return sprintf('GW-%s-%s', date('Ymd'), strtoupper(bin2hex(random_bytes(6))));
}

class SimulatedGateway implements PaymentGatewayInterface {
    private static bool $simulateFailure = false;

    /** Test CLI gọi để ép cổng trả lỗi. */
    public static function setSimulateFailure(bool $fail): void {
        self::$simulateFailure = $fail;
    }

    public static function isSimulateFailure(): bool {
        return self::$simulateFailure;
    }

    public function charge(int $amount, string $method, array $metadata = []): array {
        // Ép lỗi: cờ tĩnh (test CLI), header X-Simulate-Gateway-Fail (chỉ request test), hoặc metadata
        $forcedFail = self::$simulateFailure
            || get_test_header('X-Simulate-Gateway-Fail') === '1'
            || !empty($metadata['simulate_failure']);

        if ($forcedFail) {
            return [
                'status'          => 'failed',
                'transaction_ref' => null,
                'message'         => 'Giao dịch qua cổng thanh toán thất bại (giả lập).',
            ];
        }

        return [
            'status'          => 'success',
            'transaction_ref' => generate_gateway_ref(),
            'message'         => 'Thanh toán thành công qua cổng thanh toán mô phỏng.',
        ];
    }
}

class SimulatedCardGateway implements PaymentGatewayInterface {
    private const CARD_APPROVED = '4111111111111111';
    private const CARD_TIMEOUT  = '4000000000000002';

    public function charge(int $amount, string $method, array $metadata = []): array {
        $cardNumber = preg_replace('/\D/', '', (string)($metadata['card_number'] ?? ''));

        // Chỉ giữ lại thương hiệu + 4 số cuối; số thẻ đầy đủ không bao giờ được lưu.
        $result = [
            'brand' => $this->detectBrand($cardNumber),
            'last4' => strlen($cardNumber) >= 4 ? substr($cardNumber, -4) : '0000',
        ];

        if ($cardNumber === self::CARD_APPROVED) {
            return $result + [
                'status'          => 'success',
                'transaction_ref' => generate_gateway_ref(),
                'message'         => 'Giao dịch thẻ được chấp thuận.',
            ];
        }

        if ($cardNumber === self::CARD_TIMEOUT) {
            return $result + [
                'status'          => 'timeout',
                'transaction_ref' => null,
                'message'         => 'Thanh toán chưa được xử lý. Vui lòng thử lại sau.',
            ];
        }

        return $result + [
            'status'          => 'declined',
            'transaction_ref' => null,
            'message'         => 'Thẻ bị từ chối. Vui lòng kiểm tra thông tin hoặc dùng thẻ khác.',
        ];
    }

    private function detectBrand(string $cardNumber): string {
        if (str_starts_with($cardNumber, '4')) {
            return 'Visa';
        }
        if (preg_match('/^(5[1-5]|2[2-7])/', $cardNumber)) {
            return 'Mastercard';
        }
        return 'Visa/Mastercard';
    }
}
