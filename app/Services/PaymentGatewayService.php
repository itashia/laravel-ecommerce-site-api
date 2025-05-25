<?php

namespace App\Services;

class PaymentGatewayService
{
    private const BASE_URL = 'https://pay.ir/pg/';
    private const TEST_API_KEY = 'test';

    /**
     * آغاز فرآیند پرداخت
     */
    public function initiatePayment(
        int $amount,
        string $callback,
        ?string $description = null,
        ?string $mobile = null,
        ?string $factorNumber = null,
        array $metadata = []
    ): array {
        $response = $this->makeRequest('send', [
            'api' => self::TEST_API_KEY,
            'amount' => $amount,
            'redirect' => $callback,
            'mobile' => $mobile,
            'factorNumber' => $factorNumber,
            'description' => $description,
            'metadata' => json_encode($metadata)
        ]);

        $result = json_decode($response, true);

        return [
            'status' => $result['status'] ?? false,
            'token' => $result['token'] ?? null,
            'payment_url' => isset($result['token']) ? self::BASE_URL . $result['token'] : null,
            'message' => $result['errorMessage'] ?? 'خطایی در ارتباط با درگاه پرداخت رخ داده است'
        ];
    }

    /**
     * تایید پرداخت
     */
    public function verifyPayment(string $token): array
    {
        $response = $this->makeRequest('verify', [
            'api' => self::TEST_API_KEY,
            'token' => $token
        ]);

        $result = json_decode($response, true);

        return [
            'status' => $result['status'] ?? false,
            'transId' => $result['transId'] ?? null,
            'message' => $result['errorMessage'] ?? 'خطایی در تایید پرداخت رخ داده است'
        ];
    }

    /**
     * ارسال درخواست به درگاه پرداخت
     */
    private function makeRequest(string $endpoint, array $params): string
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => self::BASE_URL . $endpoint,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('خطا در ارتباط با درگاه پرداخت: ' . $error);
        }

        return $response;
    }
}
