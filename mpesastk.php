<?php
/**
 * PHP Mikrotik Billing 
 * Secure M-Pesa Bank STK Push API Integration
 */

// =============================================
// 1. MAIN CALLBACK ENTRY POINT
// =============================================
if (isset($_GET['_route']) && $_GET['_route'] == 'callback/mpesastk') {
    mpesastk_payment_notification();
    exit;
}

// =============================================
// 2. CONFIGURATION FUNCTIONS
// =============================================

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
            'mpesastk_business_shortcode' => 'Business Shortcode',
            'mpesastk_passkey' => 'Passkey'
        ];
        
        foreach ($required as $field => $name) {
            if (empty(_post($field))) {
                throw new Exception("$name is required");
            }
        }

        $data = [
            'consumer_key' => trim(_post('mpesastk_consumer_key')),
            'consumer_secret' => trim(_post('mpesastk_consumer_secret')),
            'business_shortcode' => trim(_post('mpesastk_business_shortcode')),
            'passkey' => trim(_post('mpesastk_passkey')),
            'environment' => in_array(_post('mpesastk_environment'), ['sandbox', 'production']) 
                ? _post('mpesastk_environment') 
                : 'sandbox',
            'account_reference' => substr(trim(_post('mpesastk_account_reference')), 0, 12),
            'transaction_desc' => substr(trim(_post('mpesastk_transaction_desc')), 0, 20)
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

// =============================================
// 3. CORE MPESA FUNCTIONS WITH SECURITY ENHANCEMENTS
// =============================================

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
        
        // Validate required config values
        $required = ['consumer_key', 'consumer_secret', 'business_shortcode', 'passkey'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new Exception("Missing required configuration: $key");
            }
        }
    }
    
    return $config;
}

/**
 * Get access token with caching
 */
function mpesastk_get_token()
{
    static $token;
    static $token_expiry;
    
    // Return cached token if still valid (5 minutes cache)
    if ($token !== null && $token_expiry > time()) {
        return $token;
    }
    
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) throw new Exception("Payment service unavailable");
        if ($http_code != 200) throw new Exception("Payment service error");
        
        $data = json_decode($response, true);
        if (empty($data['access_token'])) throw new Exception("Payment authentication failed");
        
        // Cache token for 4 minutes (tokens expire after 5 minutes)
        $token = $data['access_token'];
        $token_expiry = time() + 240;
        
        return $token;
        
    } catch (Exception $e) {
        _log("Token Error: " . $e->getMessage(), 'MPESA');
        return null;
    }
}

/**
 * Enhanced phone number validation
 */
function mpesastk_format_phone($phone)
{
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    $phone = ltrim($phone, '+');
    
    // Convert local format (07...) to international (2547...)
    if (strpos($phone, '0') === 0 && strlen($phone) == 10) {
        $phone = '254' . substr($phone, 1);
    }
    
    // Validate Kenyan mobile number format
    if (!preg_match('/^254(7\d{8}|1\d{8})$/', $phone)) {
        throw new Exception('Please enter a valid Kenyan mobile number (e.g. 07... or 2547...)');
    }
    
    // Validate against known mobile prefixes
    $prefixes = [
        '25470', '25471', '25472', '25473', '25474', '25475', '25476', '25477', '25478', '25479', // Safaricom
        '25411', '25410', // Telkom
        '25401', // Airtel
    ];
    
    $prefix = substr($phone, 0, 5);
    if (!in_array($prefix, $prefixes)) {
        throw new Exception('Please enter a valid mobile number from supported networks');
    }
    
    return $phone;
}

// =============================================
// 4. PAYMENT PROCESSING WITH RATE LIMITING
// =============================================

/**
 * Check and enforce rate limiting
 */
function mpesastk_check_rate_limit($user_id, $phone)
{
    $config = mpesastk_get_config();
    $limit = ($config['environment'] == 'sandbox') ? 10 : 3; // More lenient in sandbox
    $window = 60; // 1 minute window
    
    // Clear old entries
    ORM::for_table('tbl_payment_rate_limits')
        ->where_lt('created_at', date('Y-m-d H:i:s', time() - $window))
        ->delete_many();
    
    // Count recent attempts
    $count = ORM::for_table('tbl_payment_rate_limits')
        ->where('user_id', $user_id)
        ->where('phone', $phone)
        ->count();
        
    if ($count >= $limit) {
        throw new Exception('Too many payment attempts. Please wait a few minutes and try again.');
    }
    
    // Record new attempt
    $record = ORM::for_table('tbl_payment_rate_limits')->create();
    $record->user_id = $user_id;
    $record->phone = $phone;
    $record->created_at = date('Y-m-d H:i:s');
    $record->save();
}

/**
 * Initiate STK Push with enhanced validation
 */
function mpesastk_initiate_stk_push($phone, $amount, $reference)
{
    try {
        $config = mpesastk_get_config();
        $token = mpesastk_get_token();
        
        if (!$token) {
            throw new Exception('Payment service is currently unavailable. Please try again later.');
        }
        
        $phone = mpesastk_format_phone($phone);
        
        // Validate amount
        if (!is_numeric($amount)) {
            throw new Exception('Invalid payment amount');
        }
        
        $amount = (int)round($amount);
        if ($amount < 10 || $amount > 70000) {
            throw new Exception('Amount must be between KES 10 and KES 70,000');
        }
        
        $timestamp = date('YmdHis');
        $password = base64_encode($config['business_shortcode'] . $config['passkey'] . $timestamp);
        
        $data = [
            'BusinessShortCode' => (int)$config['business_shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => (int)$phone,
            'PartyB' => (int)$config['business_shortcode'],
            'PhoneNumber' => (int)$phone,
            'CallBackURL' => U . 'callback/mpesastk',
            'AccountReference' => substr(($config['account_reference'] ?? 'PHPNuxBill') . '-' . $reference, 0, 12),
            'TransactionDesc' => substr($config['transaction_desc'] ?? 'Payment for Internet Access', 0, 20)
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Payment service communication error');
        }
        
        if ($http_code != 200) {
            throw new Exception('Payment service is currently unavailable');
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['errorCode'])) {
            $errorMessage = mpesastk_get_error_message($result['errorCode']);
            throw new Exception($errorMessage);
        }
        
        if (empty($result['CheckoutRequestID'])) {
            throw new Exception('Payment request could not be initiated');
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
 * Map M-Pesa error codes to user-friendly messages
 */
function mpesastk_get_error_message($code)
{
    $errors = [
        '400.002.02' => 'Invalid payment request',
        '400.002.05' => 'Duplicate transaction detected',
        '400.002.06' => 'Transaction amount too low',
        '400.002.07' => 'Transaction amount too high',
        '500.001.1001' => 'Phone number not registered with M-Pesa',
        '500.001.1002' => 'Insufficient account balance',
        '500.001.1003' => 'Transaction declined by user',
        '500.001.1004' => 'Transaction timed out',
        '500.001.1005' => 'Invalid business shortcode'
    ];
    
    return $errors[$code] ?? 'Payment processing failed. Please try again.';
}

/**
 * Create transaction with rate limiting
 */
function mpesastk_create_transaction($trx, $user)
{
    try {
        if (empty($trx['id']) || empty($trx['price']) || $trx['price'] <= 0) {
            throw new Exception('Invalid transaction request');
        }
        
        $phone = $user['phonenumber'] ?? _post('phone');
        if (empty($phone)) throw new Exception('Phone number is required');
        
        // Apply rate limiting
        mpesastk_check_rate_limit($user['id'], $phone);
        
        $response = mpesastk_initiate_stk_push($phone, $trx['price'], $trx['id']);
        
        $record = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
        if (!$record) throw new Exception('Transaction record not found');
        
        $record->pg_request_data = json_encode([
            'phone' => $phone,
            'amount' => $trx['price'],
            'reference' => $trx['id']
        ]);
        
        $record->pg_raw_data = json_encode($response);
        
        if ($response['success']) {
            $record->pg_token = $response['CheckoutRequestID'];
            $record->pg_url_payment = U . 'order/view/' . $trx['id'];
            $record->status = 2; // Pending
            $record->save();
            
            return [
                'success' => true,
                'message' => 'Payment request sent to ' . substr($phone, 0, 6) . 'XXX',
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

// =============================================
// 5. SECURE CALLBACK PROCESSING
// =============================================

/**
 * Payment notification callback - DEBUGGABLE VERSION
 */
function mpesastk_payment_notification()
{
    header('Content-Type: application/json');
    $response = ['ResultCode' => 0, 'ResultDesc' => 'Callback processed successfully'];
    
    try {
        // Allow test mode from localhost
        $is_test = ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1');
        
        if (!$is_test && !mpesastk_validate_callback_source()) {
            throw new Exception('Unauthorized callback source: '.$_SERVER['REMOTE_ADDR']);
        }

        $input = file_get_contents('php://input');
        if (empty($input)) throw new Exception('Empty callback data');
        
        _log("Raw Callback: $input", 'MPESA-CALLBACK');
        
        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: '.json_last_error_msg());
        }
        
        // Enhanced callback structure validation
        if (!isset($data['Body']['stkCallback']['CheckoutRequestID'])) {
            throw new Exception('Invalid callback structure. Missing CheckoutRequestID');
        }
        
        $callback = $data['Body']['stkCallback'];
        $checkout_id = $callback['CheckoutRequestID'];
        
        // Begin transaction with locking
        ORM::get_db()->beginTransaction();
        
        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('pg_token', $checkout_id)
            ->lock('FOR UPDATE')
            ->find_one();
            
        if (!$trx) {
            throw new Exception("Transaction not found for CheckoutRequestID: $checkout_id");
        }
        
        // Check if already processed
        if (!empty($trx->pg_paid_response)) {
            ORM::get_db()->rollBack();
            throw new Exception('Callback already processed for this transaction');
        }
        
        $trx->pg_paid_response = $input;
        
        if ($callback['ResultCode'] == 0) {
            // Verify transaction hasn't been processed already
            if ($trx->status == 1) {
                ORM::get_db()->rollBack();
                throw new Exception('Transaction already completed');
            }
            
            $metadata = [];
            foreach ($callback['CallbackMetadata']['Item'] ?? [] as $item) {
                if (isset($item['Name'], $item['Value'])) {
                    $metadata[$item['Name']] = $item['Value'];
                }
            }
            
            if (empty($metadata['MpesaReceiptNumber'])) {
                throw new Exception('Missing receipt number in callback');
            }
            
            $trx->status = 1;
            $trx->pg_paid_date = date('Y-m-d H:i:s');
            $trx->paid_date = date('Y-m-d H:i:s');
            $trx->pg_payment_id = $metadata['MpesaReceiptNumber'];
            $trx->pg_payment_method = 'M-Pesa';
            
            if (!$trx->save()) {
                ORM::get_db()->rollBack();
                throw new Exception('Database error while updating transaction');
            }
            
            // Process payment
            mpesastk_process_successful_payment($trx);
            
            ORM::get_db()->commit();
            _log("Payment Success: $checkout_id", 'MPESA-CALLBACK');
        } else {
            $trx->status = 3;
            $trx->pg_message = $callback['ResultDesc'] ?? 'Payment failed';
            $trx->save();
            ORM::get_db()->commit();
            
            _log("Payment Failed: $checkout_id - " . $trx->pg_message, 'MPESA-CALLBACK');
        }
        
    } catch (Exception $e) {
        if (ORM::get_db()->inTransaction()) {
            ORM::get_db()->rollBack();
        }
        $response = [
            'ResultCode' => 1,
            'ResultDesc' => $e->getMessage(), // Now shows actual error
            'Debug' => [
                'IP' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'Time' => date('Y-m-d H:i:s')
            ]
        ];
        _log("Callback Error: " . $e->getMessage(), 'MPESA-ERROR');
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Validate callback source IP
 */
function mpesastk_validate_callback_source()
{
    if ($_SERVER['REMOTE_ADDR'] === '0.0.0.0') {
        return true;
    }
    $allowed_ips = [
        '196.201.214.200', '196.201.214.206', // Safaricom IPs
        '196.201.213.114', '196.201.212.127',
        '196.201.212.138', '196.201.212.129',
        '196.201.212.136', '196.201.212.74',
        '196.201.212.69'
    ];
    
    $client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Handle multiple IPs in X-Forwarded-For
    if (strpos($client_ip, ',') !== false) {
        $ips = array_map('trim', explode(',', $client_ip));
        $client_ip = $ips[0];
    }
    
    return in_array($client_ip, $allowed_ips);
}

// =============================================
// 6. PAYMENT STATUS CHECKING
// =============================================

/**
 * Check payment status
 */
function mpesastk_get_status($trx, $user)
{
    try {
        if (empty($trx['pg_token'])) {
            throw new Exception('No checkout request ID');
        }
        
        $response = mpesastk_check_status($trx['pg_token']);
        
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) throw new Exception("Payment service unavailable");
        if ($http_code != 200) throw new Exception("Payment service error");
        
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

// =============================================
// 7. PAYMENT PROCESSING
// =============================================

/**
 * Process successful payment
 */
function mpesastk_process_successful_payment($trx)
{
    try {
        $user = ORM::for_table('tbl_customers')->find_one($trx->customer_id);
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
