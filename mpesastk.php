<?php

/**
 *  PHP Mikrotik Billing 
 *  M-Pesa Bank STK Push API Integration - FIXED VERSION
 **/

/**
 * Displays the configuration form for M-Pesa Bank STK Push
 */
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

/**
 * Saves the M-Pesa Bank STK Push configuration
 */
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

// M-Pesa Bank STK Push API Configuration
$mpesastk_config = [];
$config = ORM::for_table('tbl_appconfig')->where('setting', 'mpesastk_config')->find_one();
if ($config) {
    $mpesastk_config = json_decode($config['value'], true);
}

$mpesastk_consumer_key = $mpesastk_config['consumer_key'] ?? '';
$mpesastk_consumer_secret = $mpesastk_config['consumer_secret'] ?? '';
$mpesastk_business_shortcode = $mpesastk_config['business_shortcode'] ?? '';
$mpesastk_passkey = $mpesastk_config['passkey'] ?? '';
$mpesastk_callback_url = U . 'callback/mpesastk';
$mpesastk_environment = $mpesastk_config['environment'] ?? 'sandbox';
$mpesastk_account_reference = $mpesastk_config['account_reference'] ?? 'PHPNuxBill';
$mpesastk_transaction_desc = $mpesastk_config['transaction_desc'] ?? 'Payment for Internet Access';

// API URLs
$mpesastk_auth_url = $mpesastk_environment == 'sandbox' ? 
    'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' : 
    'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

$mpesastk_stkpush_url = $mpesastk_environment == 'sandbox' ? 
    'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' : 
    'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

$mpesastk_query_url = $mpesastk_environment == 'sandbox' ? 
    'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' : 
    'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';

/**
 * Validates the M-Pesa Bank STK Push configuration
 */
function mpesastk_validate_config()
{
    $config = ORM::for_table('tbl_appconfig')->where('setting', 'mpesastk_config')->find_one();
    if (!$config) {
        r2(U . 'paymentgateway', 'e', 'M-Pesa Bank STK Push Payment Gateway is not configured yet');
    }
}

/**
 * Gets an access token from the M-Pesa API
 */
function mpesastk_get_token()
{
    global $mpesastk_auth_url, $mpesastk_consumer_key, $mpesastk_consumer_secret;
    
    $credentials = base64_encode($mpesastk_consumer_key . ':' . $mpesastk_consumer_secret);
    
    $ch = curl_init($mpesastk_auth_url);
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
    
    // Log the response for debugging
    _log('M-Pesa Token Request - HTTP Code: ' . $http_code . ', Response: ' . $response . ', Error: ' . $curl_error, 'M-Pesa');
    
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

/**
 * Initiates an STK Push request to the customer's phone
 */
function mpesastk_initiate_stk_push($phone, $amount, $reference)
{
    global $mpesastk_stkpush_url, $mpesastk_business_shortcode, $mpesastk_passkey, 
           $mpesastk_account_reference, $mpesastk_transaction_desc, $mpesastk_callback_url;
    
    $token = mpesastk_get_token();
    
    if (!$token) {
        return [
            'success' => false,
            'message' => 'Failed to get access token'
        ];
    }
    
    // Format phone number (remove leading 0 or +254 and add 254)
    $phone = preg_replace('/^0/', '254', preg_replace('/^\+254/', '254', $phone));
    
    // Validate phone number format
    if (!preg_match('/^254[0-9]{9}$/', $phone)) {
        return [
            'success' => false,
            'message' => 'Invalid phone number format'
        ];
    }
    
    // Generate timestamp
    $timestamp = date('YmdHis');
    
    // Generate password
    $password = base64_encode($mpesastk_business_shortcode . $mpesastk_passkey . $timestamp);
    
    // Prepare request data - FIXED: Ensure all values are correct types
    $data = [
        'BusinessShortCode' => (int)$mpesastk_business_shortcode, // Convert to integer
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => (int)round($amount), // Ensure integer
        'PartyA' => (int)$phone, // Convert to integer
        'PartyB' => (int)$mpesastk_business_shortcode, // Convert to integer
        'PhoneNumber' => (int)$phone, // Convert to integer
        'CallBackURL' => $mpesastk_callback_url,
        'AccountReference' => $mpesastk_account_reference . '-' . $reference,
        'TransactionDesc' => $mpesastk_transaction_desc
    ];
    
    // Log the request data for debugging
    _log('M-Pesa STK Push Request: ' . json_encode($data), 'M-Pesa');
    
    $ch = curl_init($mpesastk_stkpush_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Increased timeout for STK Push
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Log the response for debugging
    _log('M-Pesa STK Push Response - HTTP Code: ' . $http_code . ', Response: ' . $response . ', Error: ' . $curl_error, 'M-Pesa');
    
    if ($curl_error) {
        return [
            'success' => false,
            'message' => 'CURL Error: ' . $curl_error
        ];
    }
    
    $result = json_decode($response, true);
    
    // Add success flag based on response
    if (isset($result['CheckoutRequestID'])) {
        $result['success'] = true;
    } else {
        $result['success'] = false;
    }
    
    return $result;
}

/**
 * Checks the status of an STK Push request
 */
function mpesastk_check_status($checkout_request_id)
{
    global $mpesastk_query_url, $mpesastk_business_shortcode, $mpesastk_passkey;
    
    $token = mpesastk_get_token();
    
    if (!$token) {
        return [
            'success' => false,
            'message' => 'Failed to get access token'
        ];
    }
    
    // Generate timestamp
    $timestamp = date('YmdHis');
    
    // Generate password
    $password = base64_encode($mpesastk_business_shortcode . $mpesastk_passkey . $timestamp);
    
    // Prepare request data
    $data = [
        'BusinessShortCode' => (int)$mpesastk_business_shortcode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $checkout_request_id
    ];
    
    $ch = curl_init($mpesastk_query_url);
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
    
    // Log the response for debugging
    _log('M-Pesa Status Check Response - HTTP Code: ' . $http_code . ', Response: ' . $response, 'M-Pesa');
    
    $result = json_decode($response, true);
    return $result ?: ['success' => false, 'message' => 'Invalid response'];
}

/**
 * Initiates a payment transaction with M-Pesa Bank STK Push
 */
function mpesastk_create_transaction($trx, $user)
{
    // Validate configuration first
    mpesastk_validate_config();
    
    // Get user's phone number
    $phone = $user['phonenumber'];
    
    // If phone number is empty, use the one from the form
    if (empty($phone)) {
        $phone = _post('phone');
    }
    
    if (empty($phone)) {
        r2(U . 'order/view/' . $trx['id'], 'e', 'Phone number is required for M-Pesa payment');
    }
    
    // Format and validate phone number
    $phone = preg_replace('/[^0-9]/', '', $phone); // Remove non-numeric characters
    $phone = preg_replace('/^0/', '254', preg_replace('/^254/', '254', $phone));
    
    if (!preg_match('/^254[0-9]{9}$/', $phone)) {
        r2(U . 'order/view/' . $trx['id'], 'e', 'Invalid phone number format. Please use format 254XXXXXXXXX, 07XXXXXXXX or +254XXXXXXXXX');
    }
    
    // Validate amount
    if ($trx['price'] <= 0) {
        r2(U . 'order/view/' . $trx['id'], 'e', 'Invalid amount');
    }
    
    // Initiate STK Push
    $response = mpesastk_initiate_stk_push($phone, $trx['price'], $trx['id']);
    
    // Update transaction record
    $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
    if (!$d) {
        r2(U . 'order/view/' . $trx['id'], 'e', 'Transaction not found');
    }
    
    $d->pg_request_data = json_encode([
        'phone' => $phone,
        'amount' => $trx['price'],
        'reference' => $trx['id']
    ]);
    $d->pg_raw_data = json_encode($response);
    
    if (isset($response['CheckoutRequestID']) && $response['success']) {
        $d->pg_token = $response['CheckoutRequestID'];
        $d->pg_url_payment = U . 'order/view/' . $trx['id'];
        $d->status = 2; // Pending
        $d->save();
        
        r2(U . 'order/view/' . $trx['id'], 's', 'STK Push sent to your phone ' . substr($phone, 0, 6) . 'XXX. Please complete the payment on your phone.');
    } else {
        $error_msg = 'Failed to initiate STK Push';
        if (isset($response['errorMessage'])) {
            $error_msg .= ': ' . $response['errorMessage'];
        } elseif (isset($response['message'])) {
            $error_msg .= ': ' . $response['message'];
        }
        
        $d->pg_message = $error_msg;
        $d->status = 3; // Failed
        $d->save();
        
        r2(U . 'order/view/' . $trx['id'], 'e', $error_msg);
    }
}

/**
 * Handles the payment notification from M-Pesa
 */
function mpesastk_payment_notification()
{
    // Get the request body
    $request_body = file_get_contents('php://input');
    
    // Log the notification
    _log('M-Pesa STK Push Notification Received: ' . $request_body, 'M-Pesa');
    
    // Validate JSON
    $notification = json_decode($request_body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        _log('M-Pesa STK Push Notification - Invalid JSON: ' . json_last_error_msg(), 'M-Pesa');
        http_response_code(400);
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);
        return;
    }
    
    if (isset($notification['Body']['stkCallback'])) {
        $callback = $notification['Body']['stkCallback'];
        $checkout_request_id = $callback['CheckoutRequestID'];
        
        // Find the transaction by checkout request ID
        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('pg_token', $checkout_request_id)
            ->find_one();
        
        if ($trx) {
            // Update the transaction with the notification data
            $trx->pg_paid_response = $request_body;
            
            if ($callback['ResultCode'] == 0) {
                // Payment successful
                $item = $callback['CallbackMetadata']['Item'];
                $amount = null;
                $mpesa_receipt_number = null;
                $transaction_date = null;
                $phone_number = null;
                
                foreach ($item as $meta) {
                    if ($meta['Name'] == 'Amount') {
                        $amount = $meta['Value'];
                    } else if ($meta['Name'] == 'MpesaReceiptNumber') {
                        $mpesa_receipt_number = $meta['Value'];
                    } else if ($meta['Name'] == 'TransactionDate') {
                        $transaction_date = $meta['Value'];
                    } else if ($meta['Name'] == 'PhoneNumber') {
                        $phone_number = $meta['Value'];
                    }
                }
                
                $trx->pg_paid_date = date('Y-m-d H:i:s');
                $trx->paid_date = date('Y-m-d H:i:s');
                $trx->pg_payment_id = $mpesa_receipt_number;
                $trx->pg_payment_method = 'M-Pesa';
                $trx->status = 1; // Paid
                $trx->save();
                
                // Process the successful payment
                mpesastk_process_successful_payment($trx);
                
                _log('M-Pesa Payment Successful - TRX ID: ' . $trx->id . ', Receipt: ' . $mpesa_receipt_number, 'M-Pesa');
            } else {
                // Payment failed
                $trx->status = 3; // Failed
                $trx->pg_message = $callback['ResultDesc'];
                $trx->save();
                
                _log('M-Pesa Payment Failed - TRX ID: ' . $trx->id . ', Reason: ' . $callback['ResultDesc'], 'M-Pesa');
            }
        } else {
            _log('M-Pesa Notification - Transaction not found for CheckoutRequestID: ' . $checkout_request_id, 'M-Pesa');
        }
    }
    
    // Return a success response
    header('Content-Type: application/json');
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
}

/**
 * Process successful payment - Add user to system
 */
function mpesastk_process_successful_payment($trx)
{
    $user = ORM::for_table('tbl_customers')->find_one($trx->customer_id);
    $plan = ORM::for_table('tbl_plans')->find_one($trx->plan_id);
    
    if ($plan && $user) {
        $date_now = date("Y-m-d H:i:s");
        $date_only = date("Y-m-d");
        $time = date("H:i:s");
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
        
        // Update user's balance
        Balance::plus($user['id'], $plan['price']);
        
        // Create recharge record
        $recharge = ORM::for_table('tbl_user_recharges')->create();
        $recharge->customer_id = $user['id'];
        $recharge->username = $user['username'];
        $recharge->plan_id = $plan['id'];
        $recharge->namebp = $plan['name_plan'];
        $recharge->recharged_on = $date_only;
        $recharge->recharged_time = $time;
        $recharge->expiration = $date_exp;
        $recharge->time = $plan['validity'];
        $recharge->amount = $plan['price'];
        $recharge->gateway = 'M-Pesa Bank STK Push';
        $recharge->payment_method = 'M-Pesa';
        $recharge->routers = $trx->routers;
        $recharge->type = 'Customer';
        $recharge->save();
        
        // Update user's expiration date
        $u = ORM::for_table('tbl_customers')->find_one($user['id']);
        $u->expiration = $date_exp;
        $u->save();
        
        _log('User activated successfully - User: ' . $user['username'] . ', Plan: ' . $plan['name_plan'], 'M-Pesa');
    }
}

/**
 * Gets the status of a payment transaction
 */
function mpesastk_get_status($trx, $user)
{
    if (empty($trx['pg_token'])) {
        r2(U . 'order/view/' . $trx['id'], 'e', 'No checkout request ID found');
    }
    
    $response = mpesastk_check_status($trx['pg_token']);
    
    $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
    $d->pg_check_data = json_encode($response);
    
    if (isset($response['ResultCode'])) {
        if ($response['ResultCode'] == 0) {
            // Payment successful
            $d->pg_paid_response = json_encode($response);
            $d->pg_paid_date = date('Y-m-d H:i:s');
            $d->paid_date = date('Y-m-d H:i:s');
            $d->status = 1; // Paid
            $d->save();
            
            // Process the successful payment
            mpesastk_process_successful_payment($d);
            
            r2(U . 'order/view/' . $trx['id'], 's', 'Payment successful');
        } else {
            // Payment failed or pending
            $d->pg_message = $response['ResultDesc'];
            if ($response['ResultCode'] != 1032) { // 1032 means request is in progress
                $d->status = 3; // Failed
            }
            $d->save();
            
            if ($response['ResultCode'] == 1032) {
                r2(U . 'order/view/' . $trx['id'], 'w', 'Payment is still pending. Please complete the payment on your phone.');
            } else {
                r2(U . 'order/view/' . $trx['id'], 'e', 'Payment status: ' . $response['ResultDesc']);
            }
        }
    } else {
        $d->save();
        r2(U . 'order/view/' . $trx['id'], 'e', 'Failed to check payment status');
    }
}