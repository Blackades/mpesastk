<?php

/**
 *  PHP Mikrotik Billing 
 *  M-Pesa Bank STK Push API Integration - FIXED VERSION
 **/

// Register the callback handler - ADD THIS AT THE TOP
if (isset($_GET['_route']) && $_GET['_route'] == 'callback/mpesastk') {
    // Set a flag to prevent multiple executions
    if (!defined('MPESA_CALLBACK_PROCESSING')) {
        define('MPESA_CALLBACK_PROCESSING', true);
        mpesastk_payment_notification();
    }
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
    if (empty($config['consumer_key']) || empty($config['consumer_secret']) || empty($config['business_shortcode'])) {
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
    
    // Log the response for debugging
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

/**
 * Initiates an STK Push request to the customer's phone
 */
function mpesastk_initiate_stk_push($phone, $amount, $reference)
{
    // Check if there's already a pending transaction for this reference
    $existing = ORM::for_table('tbl_payment_gateway')
        ->where('id', $reference)
        ->where('status', 2) // Pending
        ->where_not_equal('pg_token', '')
        ->find_one();
    
    if ($existing) {
        // If a pending transaction with token exists, don't initiate another STK push
        return [
            'success' => false,
            'message' => 'A payment request is already in progress for this transaction',
            'CheckoutRequestID' => $existing->pg_token // Return the existing token
        ];
    }
    
    $config = mpesastk_get_config();
    $environment = $config['environment'] ?? 'sandbox';
    
    $stkpush_url = $environment == 'sandbox' ? 
        'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' : 
        'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    
    try {
        $token = mpesastk_get_token();
        
        if (isset($log_file)) {
            @file_put_contents($log_file, "Token retrieved: " . ($token ? 'Success' : 'Failed') . "\n", FILE_APPEND);
        }
        
        if (!$token) {
            if (isset($log_file)) {
                @file_put_contents($log_file, "Failed to get access token\n", FILE_APPEND);
            }
            return [
                'success' => false,
                'message' => 'Failed to get access token'
            ];
        }
    } catch (Exception $e) {
        if (isset($log_file)) {
            @file_put_contents($log_file, "ERROR getting token: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        _log('Error getting token: ' . $e->getMessage(), 'M-Pesa');
        return [
            'success' => false,
            'message' => 'Error getting access token: ' . $e->getMessage()
        ];
    }
    
    // Format phone number (remove leading + and ensure 254 prefix)
    $phone = preg_replace('/^\+/', '', $phone);
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
    // For GCP VM, ensure we have a full URL with domain name
    $callback_url = U . 'callback/mpesastk';
    
    try {
        // If running on a server, make sure the callback URL is a full URL
        if (strpos($callback_url, 'http') !== 0) {
            // Try to detect the domain from server variables
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
            
            // Get the base path of the application
            $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
            $base_path = '';
            
            if (!empty($script_name)) {
                $base_path = dirname(dirname($script_name));
                $base_path = ($base_path == '/' || $base_path == '\\') ? '' : $base_path;
            }
            
            // Ensure we have a valid callback URL
            $callback_url = $protocol . $domain . $base_path . '/callback/mpesastk';
        }
    } catch (Exception $e) {
        // If there's an error constructing the callback URL, log it and use the default
        _log('Error constructing callback URL: ' . $e->getMessage(), 'M-Pesa');
        // Use a hardcoded URL as fallback
        $callback_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com') . '/callback/mpesastk';
    }
    
    // Log the callback URL for debugging
    _log('M-Pesa STK Push Callback URL: ' . $callback_url, 'M-Pesa');
    
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
    
    // Log the request data for debugging (without sensitive info)
    $log_data = $data;
    $log_data['Password'] = '[HIDDEN]';
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Log the response for debugging
    _log('M-Pesa STK Push Response - HTTP Code: ' . $http_code . ', Response: ' . substr($response, 0, 500) . ', Error: ' . $curl_error, 'M-Pesa');
    
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
    
    // Add success flag based on response
    if (isset($result['CheckoutRequestID'])) {
        $result['success'] = true;
    } else {
        $result['success'] = false;
    }
    
    return $result;
}

/**
 * Initiates a payment transaction with M-Pesa Bank STK Push - FIXED VERSION
 */
function mpesastk_create_transaction($trx, $user)
{
    try {
        // Validate configuration first
        $config = mpesastk_validate_config();
        
        // Get user's phone number
        $phone = $user['phonenumber'];
        
        // If phone number is empty, use the one from the form
        if (empty($phone)) {
            $phone = _post('phone');
        }
        
        if (empty($phone)) {
            r2(U . 'order/view/' . $trx['id'], 'e', 'Phone number is required for M-Pesa payment');
            return;
        }
        
        // Format and validate phone number
        $phone = preg_replace('/[^0-9+]/', '', $phone); // Remove non-numeric characters except +
        
        // Validate amount
        if ($trx['price'] <= 0) {
            r2(U . 'order/view/' . $trx['id'], 'e', 'Invalid amount');
            return;
        }
        
        // Find the transaction record first
        $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
        if (!$d) {
            r2(U . 'order/view/' . $trx['id'], 'e', 'Transaction not found');
            return;
        }
        
        // Check if there's already a pending transaction with a token
        // This prevents multiple STK pushes for the same transaction
        if ($d->status == 2 && !empty($d->pg_token)) {
            // Format phone for display
            $display_phone = substr($phone, 0, 6) . 'XXX';
            r2(U . 'order/view/' . $trx['id'], 's', 'STK Push already sent to your phone ' . $display_phone . '. Please complete the payment on your phone.');
            return;
        }
        
        // Initiate STK Push
        $response = mpesastk_initiate_stk_push($phone, $trx['price'], $trx['id']);
        
        // Update transaction record
        $d->pg_request_data = json_encode([
            'phone' => $phone,
            'amount' => $trx['price'],
            'reference' => $trx['id']
        ]);
        $d->pg_raw_data = json_encode($response);
        
        // Format phone for display
        $display_phone = substr($phone, 0, 6) . 'XXX';
        
        // Check if we have a CheckoutRequestID (either new or existing)
        if (isset($response['CheckoutRequestID'])) {
            if ($response['success']) {
                // New successful STK push
                $d->pg_token = $response['CheckoutRequestID'];
                $d->pg_url_payment = U . 'order/view/' . $trx['id'];
                $d->status = 2; // Pending
                $d->save();
                
                r2(U . 'order/view/' . $trx['id'], 's', 'STK Push sent to your phone ' . $display_phone . '. Please complete the payment on your phone.');
            } else {
                // Existing STK push in progress
                $d->pg_token = $response['CheckoutRequestID'];
                $d->save();
                
                r2(U . 'order/view/' . $trx['id'], 's', 'Payment request already in progress for your phone ' . $display_phone . '. Please complete the payment on your phone.');
            }
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
    } catch (Exception $e) {
        // Log the exception
        _log('M-Pesa Create Transaction Exception: ' . $e->getMessage() . ' - Line: ' . $e->getLine(), 'M-Pesa');
        
        // Update transaction status to failed
        try {
            $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
            if ($d) {
                $d->pg_message = 'Internal error: ' . $e->getMessage();
                $d->status = 3; // Failed
                $d->save();
            }
        } catch (Exception $e2) {
            _log('M-Pesa Save Exception: ' . $e2->getMessage(), 'M-Pesa');
        }
        
        r2(U . 'order/view/' . $trx['id'], 'e', 'An internal error occurred. Please try again or contact support.');
    }
    }
}

/**
 * Handles the payment notification from M-Pesa - FIXED VERSION
 */
function mpesastk_payment_notification()
{
    // Set proper headers
    header('Content-Type: application/json');
    
    try {
        // Get the request body
        $request_body = file_get_contents('php://input');
        
        // Log the notification
        _log('M-Pesa STK Push Notification Received: ' . $request_body, 'M-Pesa');
        
        // Create a debug log file for troubleshooting
        $log_dir = __DIR__ . '/../../logs';
        // Create logs directory if it doesn't exist
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        $log_file = $log_dir . '/mpesa_callback_' . date('Ymd_His') . '.log';
        @file_put_contents($log_file, "Request Body: \n" . $request_body . "\n\n");
        
        // Validate JSON
        if (empty($request_body)) {
            _log('M-Pesa STK Push Notification - Empty request body', 'M-Pesa');
            @file_put_contents($log_file, "Error: Empty request body\n", FILE_APPEND);
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Empty request body']);
            return;
        }
        
        $notification = json_decode($request_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            _log('M-Pesa STK Push Notification - Invalid JSON: ' . json_last_error_msg(), 'M-Pesa');
            @file_put_contents($log_file, "Error: Invalid JSON - " . json_last_error_msg() . "\n", FILE_APPEND);
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);
            return;
        }
        
        // Log the parsed notification for debugging
        @file_put_contents($log_file, "Parsed Notification: \n" . print_r($notification, true) . "\n\n", FILE_APPEND);
        
        if (isset($notification['Body']['stkCallback'])) {
            $callback = $notification['Body']['stkCallback'];
            $checkout_request_id = $callback['CheckoutRequestID'];
            
            // Log the checkout request ID
            @file_put_contents($log_file, "CheckoutRequestID: " . $checkout_request_id . "\n", FILE_APPEND);
            
            // Find the transaction by checkout request ID
            $trx = ORM::for_table('tbl_payment_gateway')
                ->where('pg_token', $checkout_request_id)
                ->find_one();
            
            if ($trx) {
                // Log transaction found
                @file_put_contents($log_file, "Transaction found: ID=" . $trx->id . "\n", FILE_APPEND);
                
                // Check if transaction is already processed (to prevent double processing)
                if ($trx->status == 1) {
                    @file_put_contents($log_file, "Transaction already processed. Skipping.\n", FILE_APPEND);
                    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Transaction already processed']);
                    return;
                }
                
                // Update the transaction with the notification data
                $trx->pg_paid_response = $request_body;
                
                if ($callback['ResultCode'] == 0) {
                    @file_put_contents($log_file, "Payment successful. Processing...\n", FILE_APPEND);
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
                    try {
                        mpesastk_process_successful_payment($trx);
                        @file_put_contents($log_file, "Payment processed successfully\n", FILE_APPEND);
                    } catch (Exception $pe) {
                        @file_put_contents($log_file, "Error processing payment: " . $pe->getMessage() . "\n", FILE_APPEND);
                        _log('M-Pesa Payment Processing Error - TRX ID: ' . $trx->id . ', Error: ' . $pe->getMessage(), 'M-Pesa');
                    }
                    
                    _log('M-Pesa Payment Successful - TRX ID: ' . $trx->id . ', Receipt: ' . $mpesa_receipt_number, 'M-Pesa');
                } else {
                    // Payment failed
                    $trx->status = 3; // Failed
                    $trx->pg_message = $callback['ResultDesc'];
                    $trx->save();
                    
                    @file_put_contents($log_file, "Payment failed: " . $callback['ResultDesc'] . "\n", FILE_APPEND);
                    _log('M-Pesa Payment Failed - TRX ID: ' . $trx->id . ', Reason: ' . $callback['ResultDesc'], 'M-Pesa');
                }
            } else {
                @file_put_contents($log_file, "Transaction not found for CheckoutRequestID: " . $checkout_request_id . "\n", FILE_APPEND);
                _log('M-Pesa Notification - Transaction not found for CheckoutRequestID: ' . $checkout_request_id, 'M-Pesa');
            }
        } else {
            @file_put_contents($log_file, "Invalid callback format - missing stkCallback\n", FILE_APPEND);
            _log('M-Pesa Notification - Invalid callback format - missing stkCallback', 'M-Pesa');
        }
        
        // Return a success response
        @file_put_contents($log_file, "Returning success response\n", FILE_APPEND);
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
        
    } catch (Exception $e) {
        // Log the exception with more details
        $error_message = 'M-Pesa Notification Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
        _log($error_message, 'M-Pesa');
        
        // Create a log file for this exception if it doesn't exist
        if (!isset($log_file)) {
            $log_dir = __DIR__ . '/../../logs';
            // Create logs directory if it doesn't exist
            if (!is_dir($log_dir)) {
                @mkdir($log_dir, 0755, true);
            }
            $log_file = $log_dir . '/mpesa_error_' . date('Ymd_His') . '.log';
        }
        
        // Log to the debug file
        @file_put_contents($log_file, "Exception: " . $error_message . "\n", FILE_APPEND);
        @file_put_contents($log_file, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
        
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Internal server error']);
    }
}

/**
 * Process successful payment - Add user to system - FIXED VERSION
 */
function mpesastk_process_successful_payment($trx)
{
    // Create a log file for debugging
    try {
        $log_dir = __DIR__ . '/../../logs';
        // Create logs directory if it doesn't exist
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        $log_file = $log_dir . '/mpesa_payment_' . date('Ymd_His') . '_' . $trx->id . '.log';
        @file_put_contents($log_file, "Processing payment for transaction ID: " . $trx->id . "\n");
    } catch (Exception $e) {
        // Silently continue if logging fails
        $log_file = null;
    }
    try {
        $user = ORM::for_table('tbl_customers')->find_one($trx->customer_id);
        $plan = ORM::for_table('tbl_plans')->find_one($trx->plan_id);
        
        // Log user and plan details
        if (isset($log_file)) {
            @file_put_contents($log_file, "User ID: " . ($user ? $user->id : 'Not found') . "\n", FILE_APPEND);
            @file_put_contents($log_file, "Plan ID: " . ($plan ? $plan->id : 'Not found') . "\n", FILE_APPEND);
        }
        
        if ($plan && $user) {
            $date_now = date("Y-m-d H:i:s");
            $date_only = date("Y-m-d");
            $time = date("H:i:s");
            $date_exp = date("Y-m-d", strtotime("+{$plan['validity']} day"));
            
            // Add to Mikrotik if enabled
            if (!empty($trx->routers)) {
                if (isset($log_file)) {
                    @file_put_contents($log_file, "Router: " . $trx->routers . "\n", FILE_APPEND);
                }
                
                try {
                    $mikrotik = Mikrotik::info($trx->routers);
                    if ($mikrotik && $mikrotik['enabled'] == '1') {
                        if (isset($log_file)) {
                            @file_put_contents($log_file, "Mikrotik enabled, type: " . $plan['type'] . "\n", FILE_APPEND);
                        }
                        
                        if ($plan['type'] == 'Hotspot') {
                            Mikrotik::addHotspotUser($mikrotik, $user['username'], $plan, $user['password']);
                            if (isset($log_file)) {
                                @file_put_contents($log_file, "Added Hotspot user: " . $user['username'] . "\n", FILE_APPEND);
                            }
                        } else if ($plan['type'] == 'PPPOE') {
                            Mikrotik::addPpoeUser($mikrotik, $user['username'], $plan, $user['password']);
                            if (isset($log_file)) {
                                @file_put_contents($log_file, "Added PPPOE user: " . $user['username'] . "\n", FILE_APPEND);
                            }
                        }
                    } else {
                        if (isset($log_file)) {
                            @file_put_contents($log_file, "Mikrotik not enabled or not found\n", FILE_APPEND);
                        }
                    }
                } catch (Exception $me) {
                    if (isset($log_file)) {
                        @file_put_contents($log_file, "Mikrotik error: " . $me->getMessage() . "\n", FILE_APPEND);
                    }
                    _log('M-Pesa Mikrotik Error - TRX ID: ' . $trx->id . ', Error: ' . $me->getMessage(), 'M-Pesa');
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
    } catch (Exception $e) {
        if (isset($log_file)) {
            @file_put_contents($log_file, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            @file_put_contents($log_file, "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n", FILE_APPEND);
            @file_put_contents($log_file, "Stack Trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
        }
        _log('Error processing successful payment: ' . $e->getMessage(), 'M-Pesa');
        return false;
    }
}

/**
 * Gets the status of a payment transaction
 */
function mpesastk_get_status($trx, $user)
{
    // Create a log file for debugging
    try {
        $log_dir = __DIR__ . '/../../logs';
        // Create logs directory if it doesn't exist
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        $log_file = $log_dir . '/mpesa_status_' . date('Ymd_His') . '_' . $trx['id'] . '.log';
        @file_put_contents($log_file, "Checking payment status for transaction ID: " . $trx['id'] . "\n");
        @file_put_contents($log_file, "User ID: " . ($user ? $user['id'] : 'Not provided') . "\n", FILE_APPEND);
        @file_put_contents($log_file, "PG Token: " . ($trx['pg_token'] ?? 'Not found') . "\n", FILE_APPEND);
    } catch (Exception $e) {
        // Silently continue if logging fails
        $log_file = null;
    }
    if (empty($trx['pg_token'])) {
        r2(U . 'order/view/' . $trx['id'], 'e', 'No checkout request ID found');
    }
    
    try {
        $response = mpesastk_check_status($trx['pg_token']);
        
        if (isset($log_file)) {
            @file_put_contents($log_file, "Status check response: " . json_encode($response) . "\n", FILE_APPEND);
        }
        
        $d = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
        if (!$d) {
            if (isset($log_file)) {
                @file_put_contents($log_file, "ERROR: Transaction not found in database: " . $trx['id'] . "\n", FILE_APPEND);
            }
            r2(U . 'order/view/' . $trx['id'], 'e', 'Transaction not found');
            return;
        }
        $d->pg_check_data = json_encode($response);
    
    if (isset($response['ResultCode'])) {
        if (isset($log_file)) {
            @file_put_contents($log_file, "Result Code: " . $response['ResultCode'] . "\n", FILE_APPEND);
            @file_put_contents($log_file, "Result Description: " . ($response['ResultDesc'] ?? 'Not provided') . "\n", FILE_APPEND);
        }
        
        if ($response['ResultCode'] == 0) {
            // Payment successful
            if (isset($log_file)) {
                @file_put_contents($log_file, "Payment successful, updating transaction...\n", FILE_APPEND);
            }
            
            try {
                $d->pg_paid_response = json_encode($response);
                $d->pg_paid_date = date('Y-m-d H:i:s');
                $d->paid_date = date('Y-m-d H:i:s');
                $d->status = 1; // Paid
                $d->save();
                
                if (isset($log_file)) {
                    @file_put_contents($log_file, "Transaction updated, processing payment...\n", FILE_APPEND);
                }
                
                // Process the successful payment
                $result = mpesastk_process_successful_payment($d);
                
                if (isset($log_file)) {
                    @file_put_contents($log_file, "Payment processing result: " . ($result === false ? "Failed" : "Success") . "\n", FILE_APPEND);
                }
                
                r2(U . 'order/view/' . $trx['id'], 's', 'Payment successful');
            } catch (Exception $e) {
                if (isset($log_file)) {
                    @file_put_contents($log_file, "ERROR updating transaction: " . $e->getMessage() . "\n", FILE_APPEND);
                    @file_put_contents($log_file, "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n", FILE_APPEND);
                }
                _log('Error updating transaction: ' . $e->getMessage(), 'M-Pesa');
                r2(U . 'order/view/' . $trx['id'], 'e', 'An internal error occurred while processing payment.');
            }
        } else {
            // Payment failed or pending
            if (isset($log_file)) {
                @file_put_contents($log_file, "Payment not successful, code: " . $response['ResultCode'] . "\n", FILE_APPEND);
            }
            
            try {
                $d->pg_message = $response['ResultDesc'];
                if ($response['ResultCode'] != 1032) { // 1032 means request is in progress
                    $d->status = 3; // Failed
                    if (isset($log_file)) {
                        @file_put_contents($log_file, "Setting status to Failed\n", FILE_APPEND);
                    }
                } else {
                    if (isset($log_file)) {
                        @file_put_contents($log_file, "Payment still pending\n", FILE_APPEND);
                    }
                }
                $d->save();
                
                if ($response['ResultCode'] == 1032) {
                    r2(U . 'order/view/' . $trx['id'], 'w', 'Payment is still pending. Please complete the payment on your phone.');
                } else {
                    r2(U . 'order/view/' . $trx['id'], 'e', 'Payment status: ' . $response['ResultDesc']);
                }
            } catch (Exception $e) {
                if (isset($log_file)) {
                    @file_put_contents($log_file, "ERROR updating transaction: " . $e->getMessage() . "\n", FILE_APPEND);
                    @file_put_contents($log_file, "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n", FILE_APPEND);
                }
                _log('Error updating transaction: ' . $e->getMessage(), 'M-Pesa');
                r2(U . 'order/view/' . $trx['id'], 'e', 'An internal error occurred while processing payment status.');
            }
        }
    } else {
        if (isset($log_file)) {
            @file_put_contents($log_file, "No ResultCode in response\n", FILE_APPEND);
        }
        try {
            $d->save();
            r2(U . 'order/view/' . $trx['id'], 'e', 'Failed to check payment status');
        } catch (Exception $e) {
            if (isset($log_file)) {
                @file_put_contents($log_file, "ERROR saving transaction: " . $e->getMessage() . "\n", FILE_APPEND);
            }
            _log('Error saving transaction: ' . $e->getMessage(), 'M-Pesa');
            r2(U . 'order/view/' . $trx['id'], 'e', 'An internal error occurred while checking payment status.');
        }
    }
    } catch (Exception $e) {
        if (isset($log_file)) {
            @file_put_contents($log_file, "CRITICAL ERROR in get_status: " . $e->getMessage() . "\n", FILE_APPEND);
            @file_put_contents($log_file, "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n", FILE_APPEND);
            @file_put_contents($log_file, "Stack Trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
        }
        _log('Critical error in get_status: ' . $e->getMessage(), 'M-Pesa');
        r2(U . 'order/view/' . $trx['id'], 'e', 'An internal error occurred. Please try again or contact support.');
    }
}

/**
 * Checks the status of an STK Push request
 */
function mpesastk_check_status($checkout_request_id)
{
    // Create a log file for debugging
    try {
        $log_dir = __DIR__ . '/../../logs';
        // Create logs directory if it doesn't exist
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        $log_file = $log_dir . '/mpesa_check_status_' . date('Ymd_His') . '_' . substr($checkout_request_id, 0, 10) . '.log';
        @file_put_contents($log_file, "Checking status for CheckoutRequestID: " . $checkout_request_id . "\n");
    } catch (Exception $e) {
        // Silently continue if logging fails
        $log_file = null;
    }
    $config = mpesastk_get_config();
    $environment = $config['environment'] ?? 'sandbox';
    
    $query_url = $environment == 'sandbox' ? 
        'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' : 
        'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
    
    try {
        $token = mpesastk_get_token();
        
        if (isset($log_file)) {
            @file_put_contents($log_file, "Token retrieved: " . ($token ? 'Success' : 'Failed') . "\n", FILE_APPEND);
        }
        
        if (!$token) {
            if (isset($log_file)) {
                @file_put_contents($log_file, "Failed to get access token\n", FILE_APPEND);
            }
            return [
                'success' => false,
                'message' => 'Failed to get access token'
            ];
        }
    } catch (Exception $e) {
        if (isset($log_file)) {
            @file_put_contents($log_file, "ERROR getting token: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        _log('Error getting token: ' . $e->getMessage(), 'M-Pesa');
        return [
            'success' => false,
            'message' => 'Error getting access token: ' . $e->getMessage()
        ];
    }
    
    try {
        // Generate timestamp
        $timestamp = date('YmdHis');
        
        // Generate password
        $password = base64_encode($config['business_shortcode'] . $config['passkey'] . $timestamp);
        
        // Prepare request data
        $data = [
            'BusinessShortCode' => (int)$config['business_shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkout_request_id
        ];
        
        if (isset($log_file)) {
            @file_put_contents($log_file, "Request data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
            @file_put_contents($log_file, "API URL: " . $query_url . "\n", FILE_APPEND);
        }
        
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
        
        if (isset($log_file)) {
            @file_put_contents($log_file, "HTTP Code: " . $http_code . "\n", FILE_APPEND);
            @file_put_contents($log_file, "Response: " . $response . "\n", FILE_APPEND);
        }
        
        if ($response === false) {
            $curl_error = curl_error($ch);
            if (isset($log_file)) {
                @file_put_contents($log_file, "CURL Error: " . $curl_error . "\n", FILE_APPEND);
            }
            _log('CURL Error in check_status: ' . $curl_error, 'M-Pesa');
            return [
                'success' => false,
                'message' => 'CURL Error: ' . $curl_error
            ];
        }
    } catch (Exception $e) {
        if (isset($log_file)) {
            @file_put_contents($log_file, "ERROR in API call: " . $e->getMessage() . "\n", FILE_APPEND);
            @file_put_contents($log_file, "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n", FILE_APPEND);
        }
        _log('Error in API call: ' . $e->getMessage(), 'M-Pesa');
        return [
            'success' => false,
            'message' => 'Error in API call: ' . $e->getMessage()
        ];
    }
    curl_close($ch);
    
    // Log the response for debugging
    _log('M-Pesa Status Check Response - HTTP Code: ' . $http_code . ', Response: ' . $response, 'M-Pesa');
    
    try {
        if ($http_code != 200) {
            if (isset($log_file)) {
                @file_put_contents($log_file, "HTTP Error: " . $http_code . "\n", FILE_APPEND);
            }
            return [
                'success' => false,
                'message' => 'HTTP Error: ' . $http_code
            ];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();
            if (isset($log_file)) {
                @file_put_contents($log_file, "JSON Error: " . $json_error . "\n", FILE_APPEND);
                @file_put_contents($log_file, "Raw Response: " . $response . "\n", FILE_APPEND);
            }
            _log('JSON Error in check_status: ' . $json_error, 'M-Pesa');
            return [
                'success' => false,
                'message' => 'JSON Error: ' . $json_error
            ];
        }
        
        if (isset($log_file)) {
            @file_put_contents($log_file, "Parsed Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
        }
        
        return $result;
    } catch (Exception $e) {
        if (isset($log_file)) {
            @file_put_contents($log_file, "ERROR processing response: " . $e->getMessage() . "\n", FILE_APPEND);
            @file_put_contents($log_file, "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n", FILE_APPEND);
        }
        _log('Error processing response: ' . $e->getMessage(), 'M-Pesa');
        return [
            'success' => false,
            'message' => 'Error processing response: ' . $e->getMessage()
        ];
    }
}

?>
