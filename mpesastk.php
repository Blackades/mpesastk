<?php

/**
 * PHP Mikrotik Billing 
 * M-Pesa Bank STK Push API Integration - FINAL WORKING VERSION
 **/

// ========================
// 1. CALLBACK ENTRY POINT
// ========================
if (isset($_GET['_route']) && $_GET['_route'] == 'callback/mpesastk') {
    mpesastk_payment_notification();
    exit;
}

// ========================
// 2. CONFIGURATION FUNCTIONS
// ========================

/**
 * Display configuration form
 */
function mpesastk_show_config()
{
    global $ui;
    $ui->assign('_title', 'M-Pesa STK Push Configuration');

    try {
        $config = mpesastk_get_config();
        
        $ui->assign('mpesastk_consumer_key', $config['consumer_key'] ?? '');
        $ui->assign('mpesastk_consumer_secret', $config['consumer_secret'] ?? '');
        $ui->assign('mpesastk_business_shortcode', $config['business_shortcode'] ?? '');
        $ui->assign('mpesastk_passkey', $config['passkey'] ?? '');
        $ui->assign('mpesastk_environment', $config['environment'] ?? 'sandbox');
        $ui->assign('mpesastk_account_reference', $config['account_reference'] ?? 'PHPNuxBill');
        $ui->assign('mpesastk_transaction_desc', $config['transaction_desc'] ?? 'Payment for Internet Access');
        $ui->assign('mpesastk_callback_url', U . 'callback/mpesastk');
        
        $ui->display('paymentgateway/mpesastk.tpl');
    } catch (Exception $e) {
        _log('Config Error: ' . $e->getMessage(), 'Admin');
        r2(U . 'paymentgateway', 'e', 'Failed to load configuration');
    }
}

/**
 * Save configuration
 */
function mpesastk_save_config()
{
    global $admin;
    
    try {
        $required = [
            'mpesastk_consumer_key' => 'Consumer Key',
            'mpesastk_consumer_secret' => 'Consumer Secret',
            'mpesastk_business_shortcode' => 'Business Shortcode'
        ];
        
        foreach ($required as $field => $name) {
            if (empty(_post($field))) {
                throw new Exception("$name is required");
            }
        }

        $data = [
            'consumer_key' => _post('mpesastk_consumer_key'),
            'consumer_secret' => _post('mpesastk_consumer_secret'),
            'business_shortcode' => _post('mpesastk_business_shortcode'),
            'passkey' => _post('mpesastk_passkey'),
            'environment' => _post('mpesastk_environment'),
            'account_reference' => _post('mpesastk_account_reference'),
            'transaction_desc' => _post('mpesastk_transaction_desc')
        ];

        $record = ORM::for_table('tbl_appconfig')
            ->where('setting', 'mpesastk_config')
            ->find_one() ?: ORM::for_table('tbl_appconfig')->create();
            
        $record->setting = 'mpesastk_config';
        $record->value = json_encode($data);
        
        if (!$record->save()) {
            throw new Exception('Failed to save configuration');
        }
        
        _log("{$admin['username']} updated M-Pesa config", 'Admin');
        r2(U . 'paymentgateway/mpesastk', 's', 'Configuration saved');
        
    } catch (Exception $e) {
        _log('Config Save Error: ' . $e->getMessage(), 'Admin');
        r2(U . 'paymentgateway/mpesastk', 'e', $e->getMessage());
    }
}

// ========================
// 3. CORE MPESA FUNCTIONS
// ========================

/**
 * Get M-Pesa configuration
 */
function mpesastk_get_config()
{
    static $config;
    
    if ($config === null) {
        $record = ORM::for_table('tbl_appconfig')
            ->where('setting', 'mpesastk_config')
            ->find_one();
            
        if (!$record) {
            throw new Exception('M-Pesa configuration not found');
        }
        
        $config = json_decode($record->value, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid configuration format');
        }
    }
    
    return $config;
}

/**
 * Get access token
 */
function mpesastk_get_token()
{
    $config = mpesastk_get_config();
    
    try {
        $url = ($config['environment'] == 'sandbox')
            ? 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
            : 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        
        $credentials = base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $credentials],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) throw new Exception("CURL Error: $error");
        if ($http_code != 200) throw new Exception("HTTP $http_code");
        
        $data = json_decode($response, true);
        if (empty($data['access_token'])) throw new Exception("No access token");
        
        return $data['access_token'];
        
    } catch (Exception $e) {
        _log("Token Error: " . $e->getMessage(), 'MPESA');
        return null;
    }
}

/**
 * Format phone number
 */
function mpesastk_format_phone($phone)
{
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    $phone = ltrim($phone, '+');
    
    if (strpos($phone, '0') === 0) {
        $phone = '254' . substr($phone, 1);
    }
    
    if (!preg_match('/^254\d{9}$/', $phone)) {
        throw new Exception('Invalid phone format');
    }
    
    return $phone;
}

// ========================
// 4. PAYMENT PROCESSING
// ========================

/**
 * Initiate STK Push
 */
function mpesastk_initiate_stk_push($phone, $amount, $reference)
{
    try {
        $config = mpesastk_get_config();
        $token = mpesastk_get_token();
        if (!$token) throw new Exception('Failed to get token');
        
        $phone = mpesastk_format_phone($phone);
        $timestamp = date('YmdHis');
        $password = base64_encode($config['business_shortcode'] . $config['passkey'] . $timestamp);
        
        $data = [
            'BusinessShortCode' => (int)$config['business_shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int)round($amount),
            'PartyA' => (int)$phone,
            'PartyB' => (int)$config['business_shortcode'],
            'PhoneNumber' => (int)$phone,
            'CallBackURL' => U . 'callback/mpesastk',
            'AccountReference' => ($config['account_reference'] ?? 'PHPNuxBill') . '-' . $reference,
            'TransactionDesc' => $config['transaction_desc'] ?? 'Payment for Internet Access'
        ];
        
        $url = ($config['environment'] == 'sandbox')
            ? 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
            : 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) throw new Exception("CURL Error: $error");
        if ($http_code != 200) throw new Exception("HTTP $http_code");
        
        $result = json_decode($response, true);
        if (empty($result['CheckoutRequestID'])) {
            throw new Exception($result['errorMessage'] ?? 'STK Push failed');
        }
        
        return [
            'success' => true,
            'CheckoutRequestID' => $result['CheckoutRequestID'],
            'ResponseCode' => $result['ResponseCode'],
            'ResponseDescription' => $result['ResponseDescription']
        ];
        
    } catch (Exception $e) {
        _log("STK Push Error: " . $e->getMessage(), 'MPESA');
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Create transaction
 */
function mpesastk_create_transaction($trx, $user)
{
    try {
        if (empty($trx['id']) || empty($trx['price']) || $trx['price'] <= 0) {
            throw new Exception('Invalid transaction');
        }
        
        $phone = $user['phonenumber'] ?? _post('phone');
        if (empty($phone)) throw new Exception('Phone number required');
        
        $response = mpesastk_initiate_stk_push($phone, $trx['price'], $trx['id']);
        
        $record = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
        if (!$record) throw new Exception('Transaction not found');
        
        $record->pg_request = json_encode([
            'phone' => $phone,
            'amount' => $trx['price'],
            'reference' => $trx['id']
        ]);
        
        $record->pg_request_data = json_encode($response);
        
        if ($response['success']) {
            $record->gateway_trx_id = $response['CheckoutRequestID']; // Using gateway_trx_id instead of pg_token
            $record->pg_url_payment = U . 'order/view/' . $trx['id'];
            $record->status = 2; // Pending
            $record->save();
            
            return [
                'success' => true,
                'message' => 'STK Push sent to ' . substr($phone, 0, 6) . 'XXX',
                'checkout_request_id' => $response['CheckoutRequestID']
            ];
        } else {
            $record->pg_message = $response['message'];
            $record->status = 3; // Failed
            $record->save();
            
            throw new Exception($response['message']);
        }
        
    } catch (Exception $e) {
        _log("Transaction Error: " . $e->getMessage(), 'MPESA');
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

// ========================
// 5. CALLBACK PROCESSING
// ========================

/**
 * Payment notification callback - PHPNuxBill compatible version
 */
function mpesastk_payment_notification()
{
    header('Content-Type: application/json');
    $response = ['ResultCode' => 0, 'ResultDesc' => 'Callback processed successfully'];
    
    try {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            throw new Exception('Empty callback data');
        }
        
        _log("Raw Callback: $input", 'MPESA-CALLBACK');
        
        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: '.json_last_error_msg());
        }
        
        // Validate callback structure
        if (!isset($data['Body']['stkCallback']['CheckoutRequestID'])) {
            throw new Exception('Invalid callback structure. Missing CheckoutRequestID');
        }
        
        $callback = $data['Body']['stkCallback'];
        $checkout_id = $callback['CheckoutRequestID'];
        
        // Find transaction using gateway_trx_id field
        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('gateway_trx_id', $checkout_id)
            ->find_one();
            
        if (!$trx) {
            throw new Exception("Transaction not found for CheckoutRequestID: $checkout_id");
        }
        
        // Check if already processed
        if (!empty($trx->pg_paid_response)) {
            throw new Exception('Callback already processed for this transaction');
        }
        
        // Store raw callback data
        $trx->pg_paid_response = $input;
        
        if ($callback['ResultCode'] == 0) {
            // Verify transaction hasn't been processed already
            if ($trx->status == 1) {
                throw new Exception('Transaction already completed');
            }
            
            // Extract metadata from callback
            $metadata = [];
            foreach ($callback['CallbackMetadata']['Item'] ?? [] as $item) {
                if (isset($item['Name'], $item['Value'])) {
                    $metadata[$item['Name']] = $item['Value'];
                }
            }
            
            if (empty($metadata['MpesaReceiptNumber'])) {
                throw new Exception('Missing receipt number in callback');
            }
            
            // Update transaction record
            $trx->status = 1; // Mark as paid
            $trx->paid_date = date('Y-m-d H:i:s');
            $trx->payment_method = 'M-Pesa';
            $trx->gateway_trx_id = $metadata['MpesaReceiptNumber']; // Store receipt number
            $trx->payment_channel = 'M-Pesa STK Push';
            
            if (!$trx->save()) {
                throw new Exception('Database error while updating transaction');
            }
            
            // Process payment (add user time, etc.)
            mpesastk_process_successful_payment($trx);
            
            _log("Payment Success: $checkout_id", 'MPESA-CALLBACK');
        } else {
            // Payment failed
            $trx->status = 3; // Mark as failed
            $trx->pg_paid_response = $callback['ResultDesc'] ?? 'Payment failed';
            $trx->save();
            
            _log("Payment Failed: $checkout_id - " . $trx->pg_paid_response, 'MPESA-CALLBACK');
        }
        
    } catch (Exception $e) {
        $response = [
            'ResultCode' => 1,
            'ResultDesc' => $e->getMessage(),
            'Debug' => [
                'Time' => date('Y-m-d H:i:s'),
                'Input' => $input ?? ''
            ]
        ];
        _log("Callback Error: " . $e->getMessage(), 'MPESA-ERROR');
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Process successful payment
 */
function mpesastk_process_successful_payment($trx)
{
    try {
        $user = ORM::for_table('tbl_customers')->find_one($trx->user_id);
        $plan = ORM::for_table('tbl_plans')->find_one($trx->plan_id);
        
        if (!$user || !$plan) {
            throw new Exception('User or plan not found');
        }
        
        $date_exp = date("Y-m-d", strtotime("+{$plan['validity']} day"));
        
        // Add to Mikrotik if enabled
        if (!empty($trx->routers)) {
            $mikrotik = Mikrotik::info($trx->routers);
            if ($mikrotik && $mikrotik['enabled'] == '1') {
                if ($plan['type'] == 'Hotspot') {
                    Mikrotik::addHotspotUser($mikrotik, $user['username'], $plan, $user['password']);
                } else if ($plan['type'] == 'PPPOE') {
                    Mikrotik::addPpoeUser($mikrotik, $user['username'], $plan, $user['password']);
                }
            }
        }
        
        // Update balance
        Balance::plus($user['id'], $plan['price']);
        
        // Create recharge record
        $recharge = ORM::for_table('tbl_user_recharges')->create();
        $recharge->customer_id = $user['id'];
        $recharge->username = $user['username'];
        $recharge->plan_id = $plan['id'];
        $recharge->namebp = $plan['name_plan'];
        $recharge->recharged_on = date("Y-m-d");
        $recharge->recharged_time = date("H:i:s");
        $recharge->expiration = $date_exp;
        $recharge->time = $plan['validity'];
        $recharge->amount = $plan['price'];
        $recharge->gateway = 'M-Pesa STK Push';
        $recharge->payment_method = 'M-Pesa';
        $recharge->routers = $trx->routers;
        $recharge->type = 'Customer';
        $recharge->save();
        
        // Update user expiration
        $user->expiration = $date_exp;
        $user->save();
        
        _log("User Activated: {$user['username']} on {$plan['name_plan']}", 'MPESA');
        
    } catch (Exception $e) {
        _log("Payment Processing Error: " . $e->getMessage(), 'MPESA-ERROR');
        throw $e;
    }
}

// ========================
// 6. STATUS CHECKING
// ========================

/**
 * Check payment status
 */
function mpesastk_get_status($trx, $user)
{
    try {
        if (empty($trx['gateway_trx_id'])) {
            throw new Exception('No checkout request ID');
        }
        
        $response = mpesastk_check_status($trx['gateway_trx_id']);
        
        $record = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
        if (!$record) throw new Exception('Transaction not found');
        
        $record->pg_check_data = json_encode($response);
        
        if (isset($response['ResultCode'])) {
            if ($response['ResultCode'] == 0) {
                // Payment successful
                $record->pg_paid_response = json_encode($response);
                $record->pg_paid_date = date('Y-m-d H:i:s');
                $record->paid_date = date('Y-m-d H:i:s');
                $record->status = 1; // Paid
                $record->save();
                
                mpesastk_process_successful_payment($record);
                r2(U . 'order/view/' . $trx['id'], 's', 'Payment successful');
                
            } else {
                // Payment failed or pending
                $record->pg_message = $response['ResultDesc'] ?? 'Unknown status';
                
                if ($response['ResultCode'] != 1032) { // 1032 = in progress
                    $record->status = 3; // Failed
                }
                
                $record->save();
                
                if ($response['ResultCode'] == 1032) {
                    r2(U . 'order/view/' . $trx['id'], 'w', 'Payment pending');
                } else {
                    r2(U . 'order/view/' . $trx['id'], 'e', $record->pg_message);
                }
            }
        } else {
            throw new Exception('Invalid status response');
        }
        
    } catch (Exception $e) {
        _log("Status Error: " . $e->getMessage(), 'MPESA');
        r2(U . 'order/view/' . $trx['id'], 'e', $e->getMessage());
    }
}

/**
 * Check STK Push status
 */
function mpesastk_check_status($checkout_request_id)
{
    try {
        $config = mpesastk_get_config();
        $token = mpesastk_get_token();
        if (!$token) throw new Exception('Failed to get token');
        
        $timestamp = date('YmdHis');
        $password = base64_encode($config['business_shortcode'] . $config['passkey'] . $timestamp);
        
        $data = [
            'BusinessShortCode' => (int)$config['business_shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkout_request_id
        ];
        
        $url = ($config['environment'] == 'sandbox')
            ? 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query'
            : 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) throw new Exception("CURL Error: $error");
        if ($http_code != 200) throw new Exception("HTTP $http_code");
        
        $result = json_decode($response, true);
        if (!isset($result['ResultCode'])) {
            throw new Exception('Invalid status response');
        }
        
        return $result;
        
    } catch (Exception $e) {
        _log("Status Check Error: " . $e->getMessage(), 'MPESA');
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}
