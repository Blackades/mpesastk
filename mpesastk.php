<?php

/**
 *  PHP Mikrotik Billing 
 *  M-Pesa Bank STK Push API Integration - ENHANCED VERSION
 **/

// Register the callback handler - ADD THIS AT THE TOP
// This ensures the callback is processed immediately without going through the main application flow
if (isset($_GET['_route']) && $_GET['_route'] == 'callback/mpesastk') {
    // Set appropriate headers for API response
    header('Content-Type: application/json');
    
    // Process the callback
    mpesastk_payment_notification();
    
    // Exit immediately to prevent further processing
    exit;
}

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
    
    // Make sure callback URL doesn't contain 'MPESA' as it might be rejected
    $callback_url = U . 'callback/mpesastk';
    $ui->assign('mpesastk_callback_url', $callback_url);

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
function mpesastk_get_config() {
    static $mpesastk_config = null;

    if ($mpesastk_config === null) {
        $config = ORM::for_table('tbl_appconfig')->where('setting', 'mpesastk_config')->find_one();
        $mpesastk_config = $config ? json_decode($config['value'], true) : [];
    }

    return $mpesastk_config;
}

/**
 * Validates the M-Pesa Bank STK Push configuration
 */
function mpesastk_validate_config()
{
    $config = mpesastk_get_config();
    if (empty($config) || empty($config['consumer_key']) || empty($config['consumer_secret']) || empty($config['business_shortcode'])) {
        r2(U . 'paymentgateway', 'e', 'M-Pesa Bank STK Push Payment Gateway is not configured yet');
    }
    return $config;
}

/**
 * Gets an access token from the M-Pesa API
 */
function mpesastk_get_token()
{
    $config = mpesastk_get_config();
    $environment = $config['environment'] ?? 'sandbox';

    $url = $environment == 'sandbox' ?
        'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' :
        'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

    $credentials = base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);

    $ch = curl_init($url);
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
    _log('M-Pesa Token Response - HTTP Code: ' . $http_code . ', Response: ' . $response . ', Error: ' . $curl_error, 'M-Pesa');

    $result = json_decode($response, true);
    return $result['access_token'] ?? null;
}

/**
 * Initiates an STK Push request to the customer's phone
 */
function mpesastk_initiate_stk_push($phone, $amount, $reference)
{
    $config = mpesastk_get_config();
    $environment = $config['environment'] ?? 'sandbox';

    $stkpush_url = $environment == 'sandbox' ?
        'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' :
        'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

    $token = mpesastk_get_token();
    if (!$token) {
        return [
            'success' => false,
            'message' => 'Failed to get access token'
        ];
    }

    // Format phone number
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $phone = preg_replace('/^0/', '254', $phone);
    if (!preg_match('/^254/', $phone)) {
        $phone = '254' . $phone;
    }

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
    $password = base64_encode($config['business_shortcode'] . $config['passkey'] . $timestamp);

    // Prepare request data
    $data = [
        'BusinessShortCode' => $config['business_shortcode'],
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => round($amount),
        'PartyA' => $phone,
        'PartyB' => $config['business_shortcode'],
        'PhoneNumber' => $phone,
        'CallBackURL' => U . 'callback/mpesastk',
        'AccountReference' => $config['account_reference'] . ' - ' . $reference,
        'TransactionDesc' => $config['transaction_desc'] ?? 'Payment for Internet Access'
    ];

    // Log request data for debugging (exclude sensitive info)
    $log_data = $data;
    unset($log_data['Password']);
    _log('M-Pesa STK Push Request: ' . json_encode($log_data), 'M-Pesa');

    $ch = curl_init($stkpush_url);
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
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Log the response for debugging
    _log('M-Pesa STK Push Response - HTTP Code: ' . $http_code . ', Response: ' . substr($response, 0, 500) . ', Error: ' . $curl_error, 'M-Pesa');

    $result = json_decode($response, true);

    if ($result && isset($result['ResponseCode']) && $result['ResponseCode'] == '0') {
        return [
            'success' => true,
            'message' => 'STK Push initiated successfully',
            'checkout_request_id' => $result['CheckoutRequestID'] ?? null,
            'merchant_request_id' => $result['MerchantRequestID'] ?? null
        ];
    } else {
        return [
            'success' => false,
            'message' => $result['errorMessage'] ?? 'Failed to initiate STK Push',
            'response' => $result
        ];
    }
}

/**
 * Initiates a payment transaction with M-Pesa Bank STK Push - ENHANCED VERSION
 */
function mpesastk_create_transaction($trx, $user)
{
    global $config;
    
    // Validate configuration
    $config = mpesastk_validate_config();

    // Get phone number from user profile or request
    $phone = $user['phonenumber'];
    if (empty($phone)) {
        $phone = _post('phone');
    }

    // Validate phone number
    if (empty($phone)) {
        r2(U . 'order/view/' . $trx['id'], 'e', 'Phone number is required for M-Pesa payment');
    }

    // Format phone number for display
    $display_phone = $phone;
    if (strlen($phone) > 4) {
        $display_phone = substr($phone, 0, -4) . 'XXXX';
    }

    // Check if transaction already exists and is in a final state (paid or failed)
    $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
    if ($d && ($d->status == 'paid' || $d->status == 'completed')) {
        // Transaction already paid, redirect to success page
        r2(U . 'order/view/' . $trx['id'], 's', 'Payment has already been completed successfully.');
        return;
    }
    
    // Check if there's a pending transaction less than 2 minutes old
    if ($d && $d->status == 'pending') {
        $created_time = strtotime($d->created_date);
        $current_time = time();
        $time_diff = $current_time - $created_time;
        
        // If less than 2 minutes, don't initiate a new STK push
        if ($time_diff < 120) { // 120 seconds = 2 minutes
            r2(U . 'order/view/' . $trx['id'], 'w', 'STK Push already sent to your phone ' . $display_phone . '. Please complete the payment on your phone or wait ' . (120 - $time_diff) . ' seconds to try again.');
            return;
        }
    }

    // Create or update transaction record
    if (!$d) {
        $d = ORM::for_table('tbl_payment_gateway')->create();
        $d->id = $trx['id'];
        $d->gateway = 'mpesastk';
        $d->status = 'pending';
        $d->created_date = date('Y-m-d H:i:s');
    } else {
        // Update existing record
        $d->status = 'pending';
        $d->created_date = date('Y-m-d H:i:s');
    }

    // Initiate STK Push
    $response = mpesastk_initiate_stk_push($phone, $trx['price'], $trx['id']);

    if ($response['success']) {
        // Update transaction record
        $d->amount = $trx['price'];
        $d->currency = $config['currency_code'];
        $d->method = 'M-Pesa';
        $d->client_id = $user['id'];
        $d->payer_email = $user['email'];
        $d->payer_phone = $phone;
        $d->pg_url = '';
        $d->pg_url_payment = U . 'order/view/' . $trx['id'];
        $d->pg_request = json_encode($response);
        $d->pg_token = $response['checkout_request_id'];
        $d->save();

        r2(U . 'order/view/' . $trx['id'], 's', 'STK Push sent to your phone ' . $display_phone . '. Please complete the payment on your phone.');
    } else {
        $error_msg = 'Failed to initiate STK Push';
        if (isset($response['message'])) {
            $error_msg = $response['message'];
        } elseif (isset($response['response']['errorMessage'])) {
            $error_msg = $response['response']['errorMessage'];
        }

        // Update transaction record with error
        $d->status = 'failed';
        $d->pg_request = json_encode($response);
        $d->save();

        r2(U . 'order/view/' . $trx['id'], 'e', $error_msg);
    }
}

/**
 * Updates an existing transaction with M-Pesa Bank STK Push
 */
function mpesastk_update_transaction($trx, $user)
{
    // Just call create_transaction as the logic is the same
    mpesastk_create_transaction($trx, $user);
}

/**
 * Handles the payment notification from M-Pesa - ENHANCED VERSION
 */
function mpesastk_payment_notification()
{
    // Get the request body
    $request_body = file_get_contents('php://input');
    
    // Log the notification for debugging
    _log('M-Pesa STK Push Notification Received: ' . $request_body, 'M-Pesa');

    // Validate request body
    if (empty($request_body)) {
        _log('M-Pesa STK Push Notification - Empty request body', 'M-Pesa');
        http_response_code(400);
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Empty request body']);
        exit;
    }

    // Parse JSON
    $notification = json_decode($request_body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        _log('M-Pesa STK Push Notification - Invalid JSON: ' . json_last_error_msg(), 'M-Pesa');
        http_response_code(400);
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON format']);
        exit;
    }

    if (isset($notification['Body']['stkCallback'])) {
        $callback = $notification['Body']['stkCallback'];
        $checkout_request_id = $callback['CheckoutRequestID'];

        // Find the transaction by checkout request ID
        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('pg_token', $checkout_request_id)
            ->find_one();

        if (!$trx) {
            _log('M-Pesa STK Push Notification - Transaction not found for CheckoutRequestID: ' . $checkout_request_id, 'M-Pesa');
            http_response_code(404);
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Transaction not found']);
            exit;
        }

        // Check if transaction is already processed
        if ($trx->status == 'paid' || $trx->status == 'completed') {
            _log('M-Pesa STK Push Notification - Transaction already processed: ' . $checkout_request_id, 'M-Pesa');
            http_response_code(200);
            echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Transaction already processed']);
            exit;
        }

        if ($callback['ResultCode'] == 0) {
            // Payment successful
            $amount = null;
            $mpesa_receipt_number = null;
            $transaction_date = null;
            $phone_number = null;

            // Extract metadata
            if (isset($callback['CallbackMetadata']['Item']) && is_array($callback['CallbackMetadata']['Item'])) {
                foreach ($callback['CallbackMetadata']['Item'] as $meta) {
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
            }

            // Update transaction record
            $trx->status = 'paid';
            $trx->paid_date = date('Y-m-d H:i:s');
            $trx->pg_response = $request_body;
            $trx->pg_payment_id = $mpesa_receipt_number;
            $trx->pg_payment_method = 'M-Pesa';
            $trx->save();

            // Process the successful payment
            try {
                mpesastk_process_successful_payment($trx);
                _log('M-Pesa Payment Successful - TRX ID: ' . $trx->id . ', Receipt: ' . $mpesa_receipt_number, 'M-Pesa');
            } catch (Exception $e) {
                _log('Error processing payment: ' . $e->getMessage(), 'M-Pesa');
            }
        } else {
            // Payment failed
            $trx->status = 'failed';
            $trx->pg_response = $request_body;
            $trx->save();

            _log('M-Pesa Payment Failed - TRX ID: ' . $trx->id . ', Reason: ' . $callback['ResultDesc'], 'M-Pesa');
        }

        // Return success response
        http_response_code(200);
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
        exit;
    }

    // Invalid notification format
    _log('M-Pesa STK Push Notification - Invalid format', 'M-Pesa');
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid notification format']);
    exit;
}

/**
 * Process successful payment - Add user to system - ENHANCED VERSION
 */
function mpesastk_process_successful_payment($trx)
{
    try {
        // Check if transaction is already processed to prevent duplicate processing
        $recharge = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', $trx->client_id)
            ->where('payment_method', 'M-Pesa')
            ->where('pg_transaction_id', $trx->id)
            ->find_one();
            
        if ($recharge) {
            _log('M-Pesa Payment Processing - Transaction already processed. TRX ID: ' . $trx->id, 'M-Pesa');
            return;
        }
        
        // Get plan and user details
        $plan = ORM::for_table('tbl_plans')->find_one($trx->plan_id);
        $user = ORM::for_table('tbl_customers')->find_one($trx->client_id);

        if (!$plan || !$user) {
            _log('M-Pesa Payment Processing - Plan or User not found. Plan ID: ' . $trx->plan_id . ', User ID: ' . $trx->client_id, 'M-Pesa');
            return;
        }

        // Add user to system
        $mikrotik = Mikrotik::info($plan->routers);
        $client = Mikrotik::getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);

        if ($client) {
            Mikrotik::addUser($client, $user, $plan);

            // Add to activity log
            $d = ORM::for_table('tbl_user_recharges')->create();
            $d->customer_id = $user->id;
            $d->username = $user->username;
            $d->plan_id = $plan->id;
            $d->namebp = $plan->name_plan;
            $d->recharged_on = date('Y-m-d H:i:s');
            $d->expiration = date('Y-m-d H:i:s', strtotime('+' . $plan->validity . ' ' . $plan->validity_unit));
            $d->time = $plan->validity;
            $d->amount = $trx->amount;
            $d->gateway = 'M-Pesa Bank STK Push';
            $d->payment_method = 'M-Pesa';
            $d->pg_transaction_id = $trx->id; // Store transaction ID to prevent duplicate processing
            $d->routers = $plan->routers;
            $d->type = 'Hotspot';
            $d->save();

            // Update user balance
            Balance::plus($user->id, $trx->amount);

            _log('M-Pesa Payment Processing - User added successfully. User: ' . $user->username, 'M-Pesa');
        } else {
            _log('M-Pesa Payment Processing - Failed to connect to Mikrotik. Router: ' . $plan->routers, 'M-Pesa');
        }
    } catch (Exception $e) {
        _log('Error processing successful payment: ' . $e->getMessage(), 'M-Pesa');
        throw $e; // Re-throw to be caught by the caller
    }
}

/**
 * Gets the status of a payment transaction
 */
function mpesastk_get_status($trx, $user)
{
    // Check if transaction exists
    if (empty($trx['pg_token'])) {
        r2(U . 'order/view/' . $trx['id'], 'e', 'Invalid transaction');
    }

    // Find the transaction
    $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
    if (!$d) {
        r2(U . 'order/view/' . $trx['id'], 'e', 'Transaction not found');
    }
    
    // If transaction is already paid, don't check status again
    if ($d->status == 'paid' || $d->status == 'completed') {
        r2(U . 'order/view/' . $trx['id'], 's', 'Payment has already been completed successfully.');
        return;
    }

    // Check the status with M-Pesa
    $response = mpesastk_check_status($trx['pg_token']);

    if ($response && isset($response['ResultCode'])) {
        if ($response['ResultCode'] == 0) {
            // Payment successful
            $d->status = 'paid';
            $d->paid_date = date('Y-m-d H:i:s');
            $d->pg_response = json_encode($response);
            $d->save();

            // Process the successful payment
            try {
                mpesastk_process_successful_payment($d);
                r2(U . 'order/view/' . $trx['id'], 's', 'Payment successful');
            } catch (Exception $e) {
                r2(U . 'order/view/' . $trx['id'], 'e', 'Error processing payment: ' . $e->getMessage());
            }
        } else {
            // Payment failed or pending
            if ($response['ResultCode'] == 1032) {
                // Transaction canceled by user
                $d->status = 'canceled';
                $d->pg_response = json_encode($response);
                $d->save();
                r2(U . 'order/view/' . $trx['id'], 'e', 'Transaction canceled by user');
            } else if ($response['ResultCode'] == 1037 || $response['ResultCode'] == 1) {
                // Transaction still pending
                r2(U . 'order/view/' . $trx['id'], 'w', 'Payment is still pending. Please complete the payment on your phone.');
            } else {
                // Other error
                $d->status = 'failed';
                $d->pg_response = json_encode($response);
                $d->save();
                r2(U . 'order/view/' . $trx['id'], 'e', 'Payment status: ' . $response['ResultDesc']);
            }
        }
    } else {
        r2(U . 'order/view/' . $trx['id'], 'e', 'Failed to check payment status');
    }
}

/**
 * Checks the status of an STK Push request
 */
function mpesastk_check_status($checkout_request_id)
{
    $config = mpesastk_get_config();
    $environment = $config['environment'] ?? 'sandbox';

    $url = $environment == 'sandbox' ?
        'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' :
        'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';

    $token = mpesastk_get_token();
    if (!$token) {
        return ['success' => false, 'message' => 'Failed to get access token'];
    }

    // Generate timestamp
    $timestamp = date('YmdHis');

    // Generate password
    $password = base64_encode($config['business_shortcode'] . $config['passkey'] . $timestamp);

    // Prepare request data
    $data = [
        'BusinessShortCode' => $config['business_shortcode'],
        'Password' => $password,
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $checkout_request_id
    ];

    $ch = curl_init($url);
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

?>
