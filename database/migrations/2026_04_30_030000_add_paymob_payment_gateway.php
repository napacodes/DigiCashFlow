<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('payment_gateways')->updateOrInsert(
            ['code' => 'paymob'],
            [
                'logo'        => 'general/static/default/payment-gateway.png',
                'name'        => 'Paymob',
                'currencies'  => json_encode(['EGP', 'SAR', 'AED', 'OMR', 'USD']),
                'credentials' => json_encode([
                    'api_key'         => '',
                    'secret_key'      => '',
                    'public_key'      => '',
                    'payment_methods' => '15182',
                    'hmac'            => '',
                    'base_url'        => 'https://ksa.paymob.com',
                    'sandbox'         => true,
                ]),
                'withdraw_field' => null,
                'ipn'            => true,
                'status'         => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]
        );

        Cache::forget('payment_gateways_all');
        Cache::forget('payment_gateway_code_paymob');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('payment_gateways')->where('code', 'paymob')->delete();

        Cache::forget('payment_gateways_all');
        Cache::forget('payment_gateway_code_paymob');
    }
};
