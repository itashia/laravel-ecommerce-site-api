<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Api\v1\Product;
use App\Models\Api\v1\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\PaymentGatewayService;

class PaymentController extends ApiController
{
    protected $paymentGatewayService;

    public function __construct(PaymentGatewayService $paymentGatewayService)
    {
        $this->paymentGatewayService = $paymentGatewayService;
    }

    /**
     * ارسال اطلاعات به درگاه پرداخت
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendInfoToGateway(Request $request)
    {
        $validator = $this->validateRequest($request);
        if ($validator->fails()) {
            return $this::errorResponse(422, $validator->messages());
        }

        try {
            $amounts = $this->calculateOrderAmounts($request->order_items);
            
            $paymentResponse = $this->paymentGatewayService->initiatePayment(
                amount: $amounts['payingAmount'],
                callback: route('payment.verify'),
                description: 'پرداخت سفارش',
                metadata: [
                    'user_id' => $request->user_id,
                    'request_from' => $request->request_from
                ]
            );

            if ($paymentResponse['status']) {
                OrderController::create($request, $amounts, $paymentResponse['token']);
                return $this::successResponse(200, [
                    'url' => $paymentResponse['payment_url'],
                    'amounts' => $amounts
                ]);
            }

            return $this::errorResponse(422, $paymentResponse['message']);

        } catch (\Exception $e) {
            Log::error('Payment initiation failed: ' . $e->getMessage());
            return $this::errorResponse(500, 'خطایی در سرور رخ داده است');
        }
    }

    /**
     * بررسی و تایید تراکنش
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyTransaction(Request $request)
    {
        try {
            $verificationResponse = $this->paymentGatewayService->verifyPayment($request->token);

            if ($verificationResponse['status'] === 1) {
                if (Transaction::where('trans_id', $verificationResponse['transId'])->exists()) {
                    return $this->errorResponse(422, 'این تراکنش قبلا در سیستم ثبت شده است');
                }

                OrderController::update($request->token, $verificationResponse['transId']);
                return $this->successResponse(200, null, 'تراکنش با موفقیت انجام شد');
            }

            return $this->errorResponse(422, 'تراکنش با خطا مواجه شد');

        } catch (\Exception $e) {
            Log::error('Payment verification failed: ' . $e->getMessage());
            return $this::errorResponse(500, 'خطایی در تایید تراکنش رخ داده است');
        }
    }

    /**
     * اعتبارسنجی درخواست
     * 
     * @param Request $request
     * @return \Illuminate\Validation\Validator
     */
    private function validateRequest(Request $request)
    {
        return Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'order_items' => 'required|array|min:1',
            'order_items.*.product_id' => 'required|integer|exists:products,id',
            'order_items.*.quantity' => 'required|integer|min:1',
            'request_from' => 'required|string'
        ], [
            'order_items.*.product_id.exists' => 'محصول انتخاب شده معتبر نیست',
            'order_items.*.quantity.min' => 'تعداد محصول باید حداقل ۱ باشد'
        ]);
    }

    /**
     * محاسبه مبالغ سفارش
     * 
     * @param array $orderItems
     * @return array
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    private function calculateOrderAmounts(array $orderItems): array
    {
        $totalAmount = 0;
        $deliveryAmount = 0;

        foreach ($orderItems as $orderItem) {
            $product = Product::findOrFail($orderItem['product_id']);
            
            if ($product->quantity < $orderItem['quantity']) {
                throw new \Exception('مقدار وارد شده برای محصول ' . $product->name . ' بیشتر از حد مجاز است');
            }

            $totalAmount += $product->price * $orderItem['quantity'];
            $deliveryAmount += $product->delivery_amount;
        }

        return [
            'totalAmount' => $totalAmount,
            'deliveryAmount' => $deliveryAmount,
            'payingAmount' => $totalAmount + $deliveryAmount,
        ];
    }
}
