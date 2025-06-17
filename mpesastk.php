<?php

/**
 * PHP Mikrotik Billing 
 * M-Pesa Bank STK Push API Integration - FIXED VERSION
 **/

// Register the callback handler
if (isset($_GET['_route']) && $_GET['_route'] == 'callback/mpesastk') {
    if (!defined('MPESA_CALLBACK_PROCESSING')) {
        define('MPESA_CALLBACK_PROCESSING', true);
        mpesastk_payment_notification();
    }
    exit;
}

function mpesastk_show_config()
{
    global $ui;
    $ui->assign('_title', 'M-Pesa Bank STK Push Configuration');

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
}

function mpesastk_save_config()
{
    global $admin;
    $mpesastk_consumer_key = _post('mpesastk_consumer_key');
    $mpesastk_consumer_secret = _post('mpesastk_consumer_secret');
    $mpesastk_business_shortcode = _post('mpesastk_business_shortcode');
    $mpesastk_passkey = _post('mpesastk_passkey');
    $mpesastk_environment = _post('mpesastk_environment');
    $mpesastk_account_reference = _post('mpesastk_account_reference');
    $mpesastk_transaction_desc = _post('mpesastk_transaction_desc');

    if ($mpesastk_consumer_key != '' && $mpesastk_consumer_secret != '' && $mpesastk_business_shortcode != '') {
        $d = ORM::for_table('tbl_appconfig')->where('setting', 'mpesastk_config')->find_one();
        if ($d) {
            $d->value = json_encode([
                'consumer_key' => $mpesastk_consumer_key,
                'consumer_secret' => $mpesastk_consumer_secret,
                'business_shortcode' => $mpesastk_business_shortcode,
                'passkey' => $mpesastk_passkey,
                'environment' => $mpesastk_environment,
                'account_reference' => $mpesastk_account_reference,
                'transaction_desc' => $mpesastk_transaction_desc
            ]);
            $d->save();
        } else {
            $d = ORM::for_table('tbl_appconfig')->create();
            $d->setting = 'mpesastk_config';
            $d->value = json_encode([
                'consumer_key' => $mpesastk_consumer_key,
                'consumer_secret' => $mpesastk_consumer_secret,
                'business_shortcode' => $mpesastk_business_shortcode,
                'passkey' => $mpesastk_passkey,
                'environment' => $mpesastk_environment,
                'account_reference' => $mpesastk_account_reference,
                'transaction_desc' => $mpesastk_transaction_desc
            ]);
            $d->save();
        }
        _log($admin['username'] . ' Updated M-Pesa Bank STK Push Payment Gateway Configuration', 'Admin', $admin['id']);
        r2(U . 'paymentgateway/mpesastk', 's', 'M-Pesa Bank STK Push Payment Gateway Configuration Saved Successfully');
    } else {
        r2(U . 'paymentgateway/mpesastk', 'e', 'Please enter Consumer Key, Consumer Secret, and Business Short Code');
    }
}

function mpesastk_get_config() {
    static $mpesastk_config = null;
    
    if ($mpesastk_config === null) {
        $config = ORM::for_table('tbl_appconfig')->where('setting', 'mpesastk_config')->find_one();
        $mpesastk_config = $config ? json_decode($config['value'], true) : [];
    }
    
    return $mpesastk_config;
}

function mpesastk_validate_config()
{
    $config = mpesastk_get_config();
    if (empty($config['consumer_key']) || empty($config['consumer_secret']) || empty($config['business_shortcode'])) {
        r2(U . 'paymentgateway', 'e', 'M-Pesa Bank STK Push Payment Gateway is not configured yet');
    }
    return $config;
}

function mpesastk_get_token()
{
    $config = mpesastk_get_config();
    $environment = $config['environment'] ?? 'sandbox';
    
    $auth_url = $environment == 'sandbox' ? 
        'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' : 
        'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    
    $credentials = base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);
    
    $ch = curl_init($auth_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $credentials
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    _log('M-Pesa Token Request - HTTP Code: ' . $http_code . ', Response: ' . substr($response, 0, 200) . ', Error: ' . $curl_error, 'M-Pesa');
    
    if ($curl_error) {
        _log('M-Pesa Token CURL Error: ' . $curl_error, 'M-Pesa');
        return null;
    }
    
    if ($http_code != 200) {
        _log('M-Pesa Token HTTP Error: ' . $http_code, 'M-Pesa');
        return null;
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['access_token'])) {
        return $result['access_token'];
    }
    
    _log('M-Pesa Token Parse Error: ' . json_encode($result), 'M-Pesa');
    return null;
}

function mpesastk_initiate_stk_push($phone, $amount, $reference)
{
    // Check for existing pending transaction
    $existing = ORM::for_table('tbl_payment_gateway')
        ->where('id', $reference)
        ->where('status', 2)
        ->where_not_equal('pg_token', '')
        ->find_one();
    
    if ($existing) {
        return [
            'success' => false,
            'message' => 'A payment request is already in progress',
            'CheckoutRequestID' => $existing->pg_token
        ];
    }
    
    $config = mpesastk_get_config();
    $environment = $config['environment'] ?? 'sandbox';
    
    $stkpush_url = $environment == 'sandbox' ? 
        'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' : 
        'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    
    try {
        $token = mpesastk_get_token();
        
        if (!$token) {
            return [
                'success' => false,
                'message' => 'Failed to get access token'
            ];
        }
    } catch (Exception $e) {
        _log('Error getting token: ' . $e->getMessage(), 'M-Pesa');
        return [
            'success' => false,
            'message' => 'Error getting access token: ' . $e->getMessage()
        ];
    }
    
    // Format phone number
    $phone = preg_replace('/^\+/', '', $phone);
    $phone = preg_replace('/^0/', '254', $phone);
    if (!preg_match('/^254/', $phone)) {
        $phone = '254' . $phone;
    }
    
    if (!preg_match('/^254[0-9]{9}$/', $phone)) {
        return [
            'success' => false,
            'message' => 'Invalid phone number format'
        ];
    }
    
    $timestamp = date('YmdHis');
    $password = base64_encode($config['business_shortcode'] . $config['passkey'] . $timestamp);
    
    // Build callback URL
    $callback_url = U . 'callback/mpesastk';
    if (strpos($callback_url, 'http') !== 0) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $callback_url = $protocol . $domain . $callback_url;
    }
    
    $data = [
        'BusinessShortCode' => (int)$config['business_shortcode'],
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => (int)round($amount),
        'PartyA' => (int)$phone,
        'PartyB' => (int)$config['business_shortcode'],
        'PhoneNumber' => (int)$phone,
        'CallBackURL' => $callback_url,
        'AccountReference' => ($config['account_reference'] ?? 'PHPNuxBill') . '-' . $reference,
        'TransactionDesc' => $config['transaction_desc'] ?? 'Payment for Internet Access'
    ];
    
    $ch = curl_init($stkpush_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    _log('M-Pesa STK Push Response - HTTP Code: ' . $http_code . ', Response: ' . substr($response, 0, 500), 'M-Pesa');
    
    if ($curl_error) {
        return [
            'success' => false,
            'message' => 'CURL Error: ' . $curl_error
        ];
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'message' => 'Invalid JSON response from M-Pesa API'
        ];
    }
    
    if (isset($result['CheckoutRequestID'])) {
        $result['success'] = true;
    } else {
        $result['success'] = false;
    }
    
    return $result;
}

function mpesastk_create_transaction($trx, $user)
{
    try {
        $config = mpesastk_validate_config();
        
        $phone = $user['phonenumber'] ?? _post('phone');
        
        if (empty($phone)) {
            r2(U . 'order/view/' . $trx['id'], 'e', 'Phone number is required');
            return;
        }
        
        if ($trx['price'] <= 0) {
            r2(U . 'order/view/' . $trx['id'], 'e', 'Invalid amount');
            return;
        }
        
        $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
        if (!$d) {
            r2(U . 'order/view/' . $trx['id'], 'e', 'Transaction not found');
            return;
        }
        
        if ($d->status == 2 && !empty($d->pg_token)) {
            $display_phone = substr($phone, 0, 6) . 'XXX';
            r2(U . 'order/view/' . $trx['id'], 's', 'STK Push already sent to ' . $display_phone);
            return;
        }
        
        $response = mpesastk_initiate_stk_push($phone, $trx['price'], $trx['id']);
        
        $d->pg_request_data = json_encode([
            'phone' => $phone,
            'amount' => $trx['price'],
            'reference' => $trx['id']
        ]);
        $d->pg_raw_data = json_encode($response);
        
        if (isset($response['CheckoutRequestID'])) {
            if ($response['success']) {
                $d->pg_token = $response['CheckoutRequestID'];
                $d->pg_url_payment = U . 'order/view/' . $trx['id'];
                $d->status = 2;
                $d->save();
                
                r2(U . 'order/view/' . $trx['id'], 's', 'STK Push sent to your phone');
            } else {
                $d->pg_token = $response['CheckoutRequestID'];
                $d->save();
                
                r2(U . 'order/view/' . $trx['id'], 's', 'Payment request in progress');
            }
        } else {
            $error_msg = $response['message'] ?? 'Failed to initiate STK Push';
            $d->pg_message = $error_msg;
            $d->status = 3;
            $d->save();
            
            r2(U . 'order/view/' . $trx['id'], 'e', $error_msg);
        }
    } catch (Exception $e) {
        _log('M-Pesa Create Transaction Error: ' . $e->getMessage(), 'M-Pesa');
        
        try {
            $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
            if ($d) {
                $d->pg_message = 'Internal error: ' . $e->getMessage();
                $d->status = 3;
                $d->save();
            }
        } catch (Exception $e2) {
            _log('M-Pesa Save Error: ' . $e2->getMessage(), 'M-Pesa');
        }
        
        r2(U . 'order/view/' . $trx['id'], 'e', 'An error occurred. Please try again.');
    }
}

function mpesastk_payment_notification()
{
    header('Content-Type: application/json');
    
    try {
        $request_body = file_get_contents('php://input');
        _log('M-Pesa Notification: ' . $request_body, 'M-Pesa');
        
        if (empty($request_body)) {
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Empty request']);
            return;
        }
        
        $notification = json_decode($request_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);
            return;
        }
        
        if (isset($notification['Body']['stkCallback'])) {
            $callback = $notification['Body']['stkCallback'];
            $checkout_request_id = $callback['CheckoutRequestID'];
            
            $trx = ORM::for_table('tbl_payment_gateway')
                ->where('pg_token', $checkout_request_id)
                ->find_one();
            
            if ($trx) {
                $trx->pg_paid_response = $request_body;
                
                if ($callback['ResultCode'] == 0) {
                    $item = $callback['CallbackMetadata']['Item'];
                    $receipt_number = null;
                    
                    foreach ($item as $meta) {
                        if ($meta['Name'] == 'MpesaReceiptNumber') {
                            $receipt_number = $meta['Value'];
                            break;
                        }
                    }
                    
                    $trx->pg_paid_date = date('Y-m-d H:i:s');
                    $trx->paid_date = date('Y-m-d H:i:s');
                    $trx->pg_payment_id = $receipt_number;
                    $trx->pg_payment_method = 'M-Pesa';
                    $trx->status = 1;
                    $trx->save();
                    
                    mpesastk_process_successful_payment($trx);
                    _log('Payment Successful - TRX ID: ' . $trx->id, 'M-Pesa');
                } else {
                    $trx->status = 3;
                    $trx->pg_message = $callback['ResultDesc'];
                    $trx->save();
                    _log('Payment Failed - TRX ID: ' . $trx->id, 'M-Pesa');
                }
            }
        }
        
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
    } catch (Exception $e) {
        _log('Notification Error: ' . $e->getMessage(), 'M-Pesa');
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Error processing']);
    }
}

function mpesastk_process_successful_payment($trx)
{
    try {
        $user = ORM::for_table('tbl_customers')->find_one($trx->customer_id);
        $plan = ORM::for_table('tbl_plans')->find_one($trx->plan_id);
        
        if ($plan && $user) {
            $date_exp = date("Y-m-d", strtotime("+{$plan['validity']} day"));
            
            if (!empty($trx->routers)) {
                try {
                    $mikrotik = Mikrotik::info($trx->routers);
                    if ($mikrotik && $mikrotik['enabled'] == '1') {
                        if ($plan['type'] == 'Hotspot') {
                            Mikrotik::addHotspotUser($mikrotik, $user['username'], $plan, $user['password']);
                        } else if ($plan['type'] == 'PPPOE') {
                            Mikrotik::addPpoeUser($mikrotik, $user['username'], $plan, $user['password']);
                        }
                    }
                } catch (Exception $me) {
                    _log('Mikrotik Error: ' . $me->getMessage(), 'M-Pesa');
                }
            }
            
            Balance::plus($user['id'], $plan['price']);
            
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
            
            $u = ORM::for_table('tbl_customers')->find_one($user['id']);
            $u->expiration = $date_exp;
            $u->save();
        }
    } catch (Exception $e) {
        _log('Payment Processing Error: ' . $e->getMessage(), 'M-Pesa');
        return false;
    }
}

function mpesastk_get_status($trx, $user)
{
    if (empty($trx['pg_token'])) {
        r2(U . 'order/view/' . $trx['id'], 'e', 'No checkout ID found');
    }
    
    try {
        $response = mpesastk_check_status($trx['pg_token']);
        
        $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
        if (!$d) {
            r2(U . 'order/view/' . $trx['id'], 'e', 'Transaction not found');
            return;
        }
        
        $d->pg_check_data = json_encode($response);
        
        if (isset($response['ResultCode'])) {
            if ($response['ResultCode'] == 0) {
                $d->pg_paid_response = json_encode($response);
                $d->pg_paid_date = date('Y-m-d H:i:s');
                $d->paid_date = date('Y-m-d H:i:s');
                $d->status = 1;
                $d->save();
                
                mpesastk_process_successful_payment($d);
                r2(U . 'order/view/' . $trx['id'], 's', 'Payment successful');
            } else {
                $d->pg_message = $response['ResultDesc'];
                if ($response['ResultCode'] != 1032) {
                    $d->status = 3;
                }
                $d->save();
                
                if ($response['ResultCode'] == 1032) {
                    r2(U . 'order/view/' . $trx['id'], 'w', 'Payment pending');
                } else {
                    r2(U . 'order/view/' . $trx['id'], 'e', $response['ResultDesc']);
                }
            }
        } else {
            $d->save();
            r2(U . 'order/view/' . $trx['id'], 'e', 'Failed to check status');
        }
    } catch (Exception $e) {
        _log('Status Check Error: ' . $e->getMessage(), 'M-Pesa');
        r2(U . 'order/view/' . $trx['id'], 'e', 'Error checking status');
    }
}

function mpesastk_check_status($checkout_request_id)
{
    $config = mpesastk_get_config();
    $environment = $config['environment'] ?? 'sandbox';
    
    $query_url = $environment == 'sandbox' ? 
        'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' : 
        'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
    
    try {
        $token = mpesastk_get_token();
        
        if (!$token) {
            return [
                'success' => false,
                'message' => 'Failed to get token'
            ];
        }
        
        $timestamp = date('YmdHis');
        $password = base64_encode($config['business_shortcode'] . $config['passkey'] . $timestamp);
        
        $data = [
            'BusinessShortCode' => (int)$config['business_shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkout_request_id
        ];
        
        $ch = curl_init($query_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code != 200) {
            return [
                'success' => false,
                'message' => 'HTTP Error: ' . $http_code
            ];
        }
        
        return json_decode($response, true);
    } catch (Exception $e) {
        _log('Status Check Exception: ' . $e->getMessage(), 'M-Pesa');
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}
