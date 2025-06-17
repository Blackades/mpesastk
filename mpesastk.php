<?php

/**
 * PHP Mikrotik Billing 
 * M-Pesa Bank STK Push API Integration - FINAL VERSION
 **/

// Register the callback handler
if (isset($_GET['_route']) && $_GET['_route'] == 'callback/mpesastk') {
    mpesastk_payment_notification();
    exit;
}

/**
 * Displays the configuration form for M-Pesa Bank STK Push
 */
function mpesastk_show_config()
{
    global $ui;
    $ui->assign('_title', 'M-Pesa Bank STK Push Configuration');

    try {
        $config = ORM::for_table('tbl_appconfig')->where('setting', 'mpesastk_config')->find_one();
        $mpesastk_config = json_decode($config['value'] ?? '{}', true);
        
        $ui->assign('mpesastk_consumer_key', $mpesastk_config['consumer_key'] ?? '');
        $ui->assign('mpesastk_consumer_secret', $mpesastk_config['consumer_secret'] ?? '');
        $ui->assign('mpesastk_business_shortcode', $mpesastk_config['business_shortcode'] ?? '');
        $ui->assign('mpesastk_passkey', $mpesastk_config['passkey'] ?? '');
        $ui->assign('mpesastk_environment', $mpesastk_config['environment'] ?? 'sandbox');
        $ui->assign('mpesastk_account_reference', $mpesastk_config['account_reference'] ?? 'PHPNuxBill');
        $ui->assign('mpesastk_transaction_desc', $mpesastk_config['transaction_desc'] ?? 'Payment for Internet Access');
        $ui->assign('mpesastk_callback_url', U . 'callback/mpesastk');
        
        $ui->display('paymentgateway/mpesastk.tpl');
    } catch (Exception $e) {
        _log('M-Pesa Config Show Error: ' . $e->getMessage(), 'Admin', 0);
        r2(U . 'paymentgateway', 'e', 'Failed to load M-Pesa configuration');
    }
}

/**
 * Saves the M-Pesa Bank STK Push configuration
 */
function mpesastk_save_config()
{
    global $admin;
    
    try {
        $mpesastk_consumer_key = _post('mpesastk_consumer_key');
        $mpesastk_consumer_secret = _post('mpesastk_consumer_secret');
        $mpesastk_business_shortcode = _post('mpesastk_business_shortcode');
        $mpesastk_passkey = _post('mpesastk_passkey');
        $mpesastk_environment = _post('mpesastk_environment');
        $mpesastk_account_reference = _post('mpesastk_account_reference');
        $mpesastk_transaction_desc = _post('mpesastk_transaction_desc');

        if (empty($mpesastk_consumer_key) || empty($mpesastk_consumer_secret) || empty($mpesastk_business_shortcode)) {
            throw new Exception('Consumer Key, Consumer Secret, and Business Short Code are required');
        }

        $d = ORM::for_table('tbl_appconfig')->where('setting', 'mpesastk_config')->find_one();
        if (!$d) {
            $d = ORM::for_table('tbl_appconfig')->create();
            $d->setting = 'mpesastk_config';
        }
        
        $d->value = json_encode([
            'consumer_key' => $mpesastk_consumer_key,
            'consumer_secret' => $mpesastk_consumer_secret,
            'business_shortcode' => $mpesastk_business_shortcode,
            'passkey' => $mpesastk_passkey,
            'environment' => $mpesastk_environment,
            'account_reference' => $mpesastk_account_reference,
            'transaction_desc' => $mpesastk_transaction_desc
        ]);
        
        if (!$d->save()) {
            throw new Exception('Failed to save configuration');
        }
        
        _log($admin['username'] . ' Updated M-Pesa Bank STK Push Configuration', 'Admin', $admin['id']);
        r2(U . 'paymentgateway/mpesastk', 's', 'Configuration Saved Successfully');
        
    } catch (Exception $e) {
        _log('M-Pesa Config Save Error: ' . $e->getMessage(), 'Admin', $admin['id'] ?? 0);
        r2(U . 'paymentgateway/mpesastk', 'e', $e->getMessage());
    }
}

/**
 * Gets M-Pesa configuration with caching
 */
function mpesastk_get_config() 
{
    static $config = null;
    
    if ($config === null) {
        try {
            $record = ORM::for_table('tbl_appconfig')
                ->where('setting', 'mpesastk_config')
                ->find_one();
                
            if (!$record) {
                throw new Exception('M-Pesa configuration not found');
            }
            
            $config = json_decode($record->value, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid M-Pesa configuration format');
            }
            
        } catch (Exception $e) {
            _log('M-Pesa Config Error: ' . $e->getMessage(), 'M-Pesa');
            $config = [];
        }
    }
    
    return $config;
}

/**
 * Validates M-Pesa configuration
 */
function mpesastk_validate_config()
{
    $config = mpesastk_get_config();
    
    if (empty($config['consumer_key']) || 
        empty($config['consumer_secret']) || 
        empty($config['business_shortcode'])) {
        r2(U . 'paymentgateway', 'e', 'M-Pesa configuration incomplete');
    }
    
    return $config;
}

/**
 * Gets access token from M-Pesa API
 */
function mpesastk_get_token()
{
    $config = mpesastk_validate_config();
    
    try {
        $environment = $config['environment'] ?? 'sandbox';
        $auth_url = ($environment == 'sandbox') ? 
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' : 
            'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        
        $credentials = base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $auth_url,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $credentials],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('CURL Error: ' . $error);
        }
        
        if ($http_code != 200) {
            throw new Exception('HTTP Error: ' . $http_code);
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response');
        }
        
        if (empty($result['access_token'])) {
            throw new Exception('No access token received');
        }
        
        return $result['access_token'];
        
    } catch (Exception $e) {
        _log('M-Pesa Token Error: ' . $e->getMessage(), 'M-Pesa');
        return null;
    }
}

/**
 * Formats phone number for M-Pesa
 */
function mpesastk_format_phone($phone)
{
    // Remove all non-digit characters except +
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Remove leading + if present
    $phone = ltrim($phone, '+');
    
    // Convert to 254 format if starts with 0
    if (substr($phone, 0, 1) === '0') {
        $phone = '254' . substr($phone, 1);
    }
    
    // Ensure it starts with 254
    if (!preg_match('/^254\d{9}$/', $phone)) {
        throw new Exception('Invalid phone number format');
    }
    
    return $phone;
}

/**
 * Initiates STK Push request
 */
function mpesastk_initiate_stk_push($phone, $amount, $reference)
{
    try {
        $config = mpesastk_validate_config();
        $environment = $config['environment'] ?? 'sandbox';
        
        $stkpush_url = ($environment == 'sandbox') ? 
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' : 
            'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        
        $token = mpesastk_get_token();
        if (!$token) {
            throw new Exception('Failed to get access token');
        }
        
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
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $stkpush_url,
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
        
        if ($error) {
            throw new Exception('CURL Error: ' . $error);
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response');
        }
        
        if (empty($result['CheckoutRequestID'])) {
            $errorMsg = $result['errorMessage'] ?? $result['message'] ?? 'Unknown error';
            throw new Exception('STK Push failed: ' . $errorMsg);
        }
        
        return [
            'success' => true,
            'CheckoutRequestID' => $result['CheckoutRequestID'],
            'ResponseCode' => $result['ResponseCode'] ?? 0,
            'ResponseDescription' => $result['ResponseDescription'] ?? 'Request accepted for processing'
        ];
        
    } catch (Exception $e) {
        _log('STK Push Error: ' . $e->getMessage(), 'M-Pesa');
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Creates M-Pesa transaction
 */
function mpesastk_create_transaction($trx, $user)
{
    try {
        // Validate input
        if (empty($trx['id']) || empty($trx['price']) || $trx['price'] <= 0) {
            throw new Exception('Invalid transaction amount');
        }
        
        // Get phone number
        $phone = $user['phonenumber'] ?? _post('phone');
        if (empty($phone)) {
            throw new Exception('Phone number is required');
        }
        
        // Initiate STK Push
        $response = mpesastk_initiate_stk_push($phone, $trx['price'], $trx['id']);
        
        // Update transaction record
        $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
        if (!$d) {
            throw new Exception('Transaction not found');
        }
        
        $d->pg_request_data = json_encode([
            'phone' => $phone,
            'amount' => $trx['price'],
            'reference' => $trx['id']
        ]);
        
        $d->pg_raw_data = json_encode($response);
        
        if ($response['success']) {
            $d->pg_token = $response['CheckoutRequestID'];
            $d->pg_url_payment = U . 'order/view/' . $trx['id'];
            $d->status = 2; // Pending
            $d->save();
            
            // Format phone for display
            $display_phone = substr($phone, 0, 6) . 'XXX';
            
            return [
                'success' => true,
                'message' => 'STK Push sent to ' . $display_phone . '. Please complete payment on your phone.',
                'checkout_request_id' => $response['CheckoutRequestID']
            ];
        } else {
            $d->pg_message = $response['message'] ?? 'STK Push failed';
            $d->status = 3; // Failed
            $d->save();
            
            throw new Exception($d->pg_message);
        }
        
    } catch (Exception $e) {
        _log('Transaction Error: ' . $e->getMessage(), 'M-Pesa');
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Handles M-Pesa payment notification
 */
function mpesastk_payment_notification()
{
    header('Content-Type: application/json');
    
    try {
        $request_body = file_get_contents('php://input');
        if (empty($request_body)) {
            throw new Exception('Empty notification body');
        }
        
        _log('M-Pesa Notification: ' . $request_body, 'M-Pesa');
        
        $notification = json_decode($request_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON notification');
        }
        
        if (!isset($notification['Body']['stkCallback'])) {
            throw new Exception('Invalid notification format');
        }
        
        $callback = $notification['Body']['stkCallback'];
        $checkout_request_id = $callback['CheckoutRequestID'] ?? '';
        
        if (empty($checkout_request_id)) {
            throw new Exception('Missing CheckoutRequestID');
        }
        
        // Find transaction
        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('pg_token', $checkout_request_id)
            ->find_one();
            
        if (!$trx) {
            throw new Exception('Transaction not found');
        }
        
        $trx->pg_paid_response = $request_body;
        
        if ($callback['ResultCode'] == 0) {
            // Successful payment
            $metadata = $callback['CallbackMetadata']['Item'] ?? [];
            $payment_data = [];
            
            foreach ($metadata as $item) {
                $payment_data[$item['Name']] = $item['Value'] ?? null;
            }
            
            $trx->pg_paid_date = date('Y-m-d H:i:s');
            $trx->paid_date = date('Y-m-d H:i:s');
            $trx->pg_payment_id = $payment_data['MpesaReceiptNumber'] ?? '';
            $trx->pg_payment_method = 'M-Pesa';
            $trx->status = 1; // Paid
            $trx->save();
            
            // Process successful payment
            mpesastk_process_successful_payment($trx);
            
            _log('Payment Successful - TRX: ' . $trx->id . ', Receipt: ' . $trx->pg_payment_id, 'M-Pesa');
        } else {
            // Failed payment
            $trx->status = 3; // Failed
            $trx->pg_message = $callback['ResultDesc'] ?? 'Payment failed';
            $trx->save();
            
            _log('Payment Failed - TRX: ' . $trx->id . ', Reason: ' . $trx->pg_message, 'M-Pesa');
        }
        
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
        
    } catch (Exception $e) {
        _log('Notification Error: ' . $e->getMessage(), 'M-Pesa');
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => $e->getMessage()]);
    }
}

/**
 * Processes successful payment
 */
function mpesastk_process_successful_payment($trx)
{
    try {
        $user = ORM::for_table('tbl_customers')->find_one($trx->customer_id);
        $plan = ORM::for_table('tbl_plans')->find_one($trx->plan_id);
        
        if (!$user || !$plan) {
            throw new Exception('User or plan not found');
        }
        
        $date_now = date("Y-m-d H:i:s");
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
        
        _log('User Activated - ' . $user['username'] . ' on ' . $plan['name_plan'], 'M-Pesa');
        
    } catch (Exception $e) {
        _log('Payment Processing Error: ' . $e->getMessage(), 'M-Pesa');
        throw $e;
    }
}

/**
 * Checks payment status
 */
function mpesastk_get_status($trx, $user)
{
    try {
        if (empty($trx['pg_token'])) {
            throw new Exception('No checkout request ID found');
        }
        
        $response = mpesastk_check_status($trx['pg_token']);
        
        $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
        if (!$d) {
            throw new Exception('Transaction not found');
        }
        
        $d->pg_check_data = json_encode($response);
        
        if (isset($response['ResultCode'])) {
            if ($response['ResultCode'] == 0) {
                // Payment successful
                $d->pg_paid_response = json_encode($response);
                $d->pg_paid_date = date('Y-m-d H:i:s');
                $d->paid_date = date('Y-m-d H:i:s');
                $d->status = 1; // Paid
                $d->save();
                
                mpesastk_process_successful_payment($d);
                r2(U . 'order/view/' . $trx['id'], 's', 'Payment successful');
                
            } else {
                // Payment failed or pending
                $d->pg_message = $response['ResultDesc'] ?? 'Unknown status';
                
                if ($response['ResultCode'] != 1032) { // 1032 = in progress
                    $d->status = 3; // Failed
                }
                
                $d->save();
                
                if ($response['ResultCode'] == 1032) {
                    r2(U . 'order/view/' . $trx['id'], 'w', 'Payment pending. Complete on your phone.');
                } else {
                    r2(U . 'order/view/' . $trx['id'], 'e', 'Payment failed: ' . $d->pg_message);
                }
            }
        } else {
            throw new Exception('Invalid status response');
        }
        
    } catch (Exception $e) {
        _log('Status Check Error: ' . $e->getMessage(), 'M-Pesa');
        r2(U . 'order/view/' . $trx['id'], 'e', $e->getMessage());
    }
}

/**
 * Checks STK Push status
 */
function mpesastk_check_status($checkout_request_id)
{
    try {
        $config = mpesastk_validate_config();
        $environment = $config['environment'] ?? 'sandbox';
        
        $query_url = ($environment == 'sandbox') ? 
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' : 
            'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
        
        $token = mpesastk_get_token();
        if (!$token) {
            throw new Exception('Failed to get access token');
        }
        
        $timestamp = date('YmdHis');
        $password = base64_encode($config['business_shortcode'] . $config['passkey'] . $timestamp);
        
        $data = [
            'BusinessShortCode' => (int)$config['business_shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkout_request_id
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $query_url,
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
        
        if ($error) {
            throw new Exception('CURL Error: ' . $error);
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response');
        }
        
        if (!isset($result['ResultCode'])) {
            throw new Exception('Invalid status response');
        }
        
        return $result;
        
    } catch (Exception $e) {
        _log('Status Query Error: ' . $e->getMessage(), 'M-Pesa');
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}
