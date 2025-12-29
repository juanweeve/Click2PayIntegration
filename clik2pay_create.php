<?php
declare(strict_types=1);


// 1. Core Config & Session (Must be included first to share session path)
require __DIR__ . '/config.php';
require_login(); // Ensure user is logged in

require __DIR__ . '/auth.php';            // defines db()
require __DIR__ . '/token.php';           // defines make_token()
require __DIR__ . '/clik2pay_common.php'; // defines helpers like c2p_config_for_city, bearer_token, c2p_curl

// Validate Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

/* =========================
   SALESFORCE CREDENTIALS
   ========================= */

// ----- Salesforce Production Credentials -----
$sf_client_id = '3n';
$sf_client_secret = '630';
$sf_redirect_uri = 'callback.php';
$sf_refresh_token = '5AT1';

// Montreal only for now (as requested)
$sf_client_location_id_montreal = 'a0Xa50000015Wl3EAE';

/* =========================
   SALESFORCE HELPERS
   ========================= */

function sf_getAccessToken(string $client_id, string $client_secret, string $refresh_token, string $redirect_uri): array
{
    $url = "https://login.salesforce.com/services/oauth2/token"; // production

    $post = http_build_query([
        'grant_type' => 'refresh_token',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
        'redirect_uri' => $redirect_uri
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['error' => 'curl_error', 'error_description' => $curlErr, 'http' => $httpCode];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['error' => 'decode_error', 'error_description' => 'Invalid token JSON', 'http' => $httpCode, 'body' => $response];
    }

    return $decoded;
}

function sf_request(string $method, string $url, string $access_token, ?array $jsonBody = null): array
{
    $ch = curl_init($url);
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json",
        "Accept: application/json",
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];

    if ($jsonBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'http' => $code, 'error' => 'curl_error', 'details' => $curlErr];
    }

    $decoded = json_decode($resp, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'http' => $code, 'error' => 'decode_error', 'raw' => $resp];
    }

    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'http' => $code, 'error' => 'sf_error', 'body' => $decoded];
    }

    return ['ok' => true, 'http' => $code, 'body' => $decoded];
}

function sf_findContactByEmail(string $instance_url, string $access_token, string $email): array
{
    // Returns: ['contactId' => ?, 'accountId' => ?]
    if ($email === '') {
        return ['contactId' => null, 'accountId' => null];
    }
    $emailEsc = addslashes($email);
    $soql = "SELECT Id, AccountId FROM Contact WHERE Email = '{$emailEsc}' LIMIT 1";
    $url = $instance_url . "/services/data/v62.0/query?q=" . urlencode($soql);

    $res = sf_request('GET', $url, $access_token, null);
    if (!$res['ok']) {
        return ['contactId' => null, 'accountId' => null];
    }
    $records = $res['body']['records'] ?? [];
    $row = $records[0] ?? null;

    return [
        'contactId' => $row['Id'] ?? null,
        'accountId' => $row['AccountId'] ?? null,
    ];
}

/* =========================
   2. Validate Inputs
   ========================= */

$city = trim($_POST['CustomerCity'] ?? '');
$name = trim($_POST['CustomerName'] ?? '');
$email = trim($_POST['CustomerEmail'] ?? '');
$amount = (float) ($_POST['Amount'] ?? 0);
$plate = trim($_POST['PlateNumber'] ?? '');
$inv = trim($_POST['InvoiceNumber'] ?? '');
$mobile = trim($_POST['MobileNumber'] ?? '');
$username = trim($_POST['username'] ?? '');
$ClientPreferredLanguage = trim($_POST['ClientPreferredLanguage'] ?? ''); // en or fr
$sfAccountID = trim($_POST['sfAccountID'] ?? '');   // from form hidden
$sfVehicleID = trim($_POST['sfVehicleID'] ?? '');   // from form hidden
$PaymentType = trim($_POST['PaymentType'] ?? '');   // Deposit/Repo/Collections/Bounce
$sfCaseID = ''; // will be set after we create the Case

$errors = [];
if (!$city)
    $errors[] = 'Customer City is required';
if (!$name)
    $errors[] = 'Customer Name is required';
if (!$email)
    $errors[] = 'Customer Email is required';
if (!$inv)
    $errors[] = 'Invoice Number is required';
if ($amount <= 0)
    $errors[] = 'Amount must be greater than 0';
if (!$PaymentType)
    $errors[] = 'Payment Type is required';

if (!empty($errors)) {
    die('Validation Error: ' . implode(', ', $errors));
}

$amountStr = number_format($amount, 2, '.', '');

/* =========================
   3. Clik2Pay Configuration & Auth
   ========================= */
try {
    $C2P = c2p_config_for_city($city);
    $token = bearer_token($C2P); // Gets cached token or new one
} catch (Exception $e) {
    die("Configuration/Auth Error: " . $e->getMessage());
}

/* =========================
   4. Build Display Message
   ========================= */
$rawMsg = "Payment {$amountStr} CAD - Weeve {$city}";
$msg = preg_replace('/[^0-9A-Za-z \-\.\,\*\:\%\(\)\p{L}]/u', '', $rawMsg);
$msg = preg_replace('/\s+/u', ' ', $msg);
$displayMessage = trim($msg) ?: "Payment {$amountStr} CAD - Weeve";

/* =========================
   5. Create Payment Request Payload
   ========================= */

// Timestamp in required format: YYYYMMDDtHHMMSS
$timestamp = date('Ymd\THis');
// Random 4-digit number
$random = random_int(1000, 9999);
// Final merchantTransactionId
$transactionId = "Pay{$timestamp}r{$random}";


// Due Date in required format: YYYY-MM-DD
$today = date('Y-m-d');
$dueDate = date('Y-m-d', strtotime($today . ' + 4 days'));


$payload = [
    'merchantTransactionId' => $transactionId,
    'amount' => $amountStr,
    'type' => 'ECOMM',
    'deliveryMode' => 'NONE',
    'payer' => [
        'name' => $name,
        'email' => $email,
        'mobileNumber' => $mobile,
        'preferredLanguage' => $ClientPreferredLanguage,
    ],
    'displayMessage' => $displayMessage,
    'invoiceNumber' => $inv,
    'businessUnit' => $city,
    'dueDate' => $dueDate,
];

/* =========================
   6. Execute Click2Pay API Call
   ========================= */
$resp = c2p_curl(
    'POST',
    $C2P['API_BASE'] . '/payment-requests',
    [
        'x-api-key: ' . $C2P['API_KEY'],
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

if ($resp['code'] < 200 || $resp['code'] >= 300) {
    // If Click2Pay fails: do NOT create Salesforce Case (as requested)
    die("Clik2Pay API Error ({$resp['code']}): " . $resp['resp']);
}

$out = json_decode($resp['resp'], true);
if (!$out || !isset($out['id'], $out['paymentLink'])) {
    die("Invalid API Response: " . $resp['resp']);
}

/* =========================
   7. Extract Click2Pay Fields
   ========================= */
$c2pId = $out['id'];
$paymentLink = $out['paymentLink'];
$status = $out['status'] ?? 'CREATED';
$createdBy = $out['createdBy'] ?? '';
$shortCode = $out['shortCode'] ?? '';
$shortLink = $out['shortLink'] ?? ($shortCode ? "https://pay.clik2pay.com/r/{$shortCode}" : '');
$cancelLink = "https://api.clik2pay.com/open/v1/payment-requests/{$c2pId}/cancellation";

/* =========================
   7.5 Create Salesforce Case + FeedItem
   ========================= */

// Get SF token
$sfToken = sf_getAccessToken($sf_client_id, $sf_client_secret, $sf_refresh_token, $sf_redirect_uri);
if (empty($sfToken['access_token']) || empty($sfToken['instance_url'])) {
    die("ERROR getting Salesforce token: " . json_encode($sfToken));
}
$sf_access_token = $sfToken['access_token'];
$sf_instance_url = $sfToken['instance_url'];

// If account not provided, attempt lookup from email (optional fallback)
$contactId = null;
if ($sfAccountID === '') {
    $found = sf_findContactByEmail($sf_instance_url, $sf_access_token, $email);
    $contactId = $found['contactId'] ?? null;
    $sfAccountID = $found['accountId'] ?? '';
} else {
    // Still attempt contact lookup for linking ContactId (nice to have)
    $found = sf_findContactByEmail($sf_instance_url, $sf_access_token, $email);
    $contactId = $found['contactId'] ?? null;
}

// Build Case (Montreal only for now)
$caseSubject = "Payment Request - {$PaymentType} - {$name} - Amount: {$amountStr}";
$caseDescription = "Payment request created via internal portal.\n"
    . "Type: {$PaymentType}\n"
    . "Amount: {$amountStr} CAD\n"
    . "Invoice: {$inv}\n"
    . "City: {$city}\n"
    . "Click2Pay: " . ($shortLink ?: $paymentLink) . "\n";

$caseData = [
    "Origin" => "Other",
    "Status" => "Open",
    "Subject" => $caseSubject,
    "Description" => $caseDescription,
    "Priority" => "Low",
    "SuppliedEmail" => $email,
    "Client_Location__c" => $sf_client_location_id_montreal, // Montreal only (requested)
    "Reason" => "Finance",
    "Reason_for_Case_Sub_Categories__c" => "Payments",
    "Department__c" => "Accounting",
    "Final_Resolution_Notes__c" => "Payment request created ({$PaymentType}) for {$name}.",
    "Request_Type__c" => "Outbound",
    "Resolution_Notes__c" => "Payment request created ({$PaymentType}) for {$name}.",
    "Support_Level__c" => "Level 0: Call Center",
];

if ($sfVehicleID !== '') {
    $caseData["Vehicle_Inventory__c"] = $sfVehicleID;
}
if ($contactId) {
    $caseData["ContactId"] = $contactId;
}
if ($sfAccountID !== '') {
    $caseData["AccountId"] = $sfAccountID;
}

// Create Case
$caseUrl = $sf_instance_url . "/services/data/v62.0/sobjects/Case/";
$caseRes = sf_request('POST', $caseUrl, $sf_access_token, $caseData);

if (!$caseRes['ok'] || empty($caseRes['body']['id'])) {
    die("Failed to create Salesforce Case: " . json_encode($caseRes));
}

$sfCaseID = $caseRes['body']['id'];

// Create FeedItem
$now = date('Y-m-d H:i:s');

$feedBody = "Payment Request Created\n\n"
    . "Date: {$now}\n\n"
    . "Customer:\n"
    . "Name: {$name}\n"
    . "Email: {$email}\n"
    . "Phone: {$mobile}\n\n"
    . "Payment Details:\n"
    . "Type: {$PaymentType}\n"
    . "Amount: {$amountStr} CAD\n"
    . "Invoice: {$inv}\n\n"
    . "Click2Pay Link:\n"
    . ($shortLink ?: $paymentLink) . "\n\n"
    . "Created by: {$username}";

$feedData = [
    "ParentId" => $sfCaseID,
    "Body" => $feedBody
];

$feedUrl = $sf_instance_url . "/services/data/v62.0/sobjects/FeedItem/";
$feedRes = sf_request('POST', $feedUrl, $sf_access_token, $feedData);

// Feed is nice-to-have; do not fail whole flow if it errors
// (But you can change to hard-fail if you want)
if (!$feedRes['ok']) {
    // Optional: log it
    // error_log("Salesforce FeedItem failed: " . json_encode($feedRes));
}

/* =========================
   8. Save to Database
   ========================= */

$sql = "INSERT INTO payment_links 
(
  CustomerCity,
  CustomerName,
  CustomerEmail,
  CustomerPhone,
  Amount,
  DateOfCreation,
  PaymentDate,
  PaymentStatus,
  PlateNumber,
  InvoiceNumber,
  Clik2payId,
  PaymentLink,
  ShortCode,
  ShortLink,
  CancelLink,
  CreatedBy,
  username,
  sfAccountID,
  sfVehicleID,
  PaymentType,
  sfCaseID,
  Language
)
VALUES
(
  ?, ?, ?, ?, ?, NOW(), NULL,
  ?, ?, ?, ?, ?,
  ?, ?, ?, ?, ?,
  ?, ?, ?, ?, ?
)
";

$stmt = db()->prepare($sql);
if (!$stmt) {
    die("Database Prepare Error: " . db()->error);
}

$stmt->bind_param(
    'ssssdsssssssssssssss',
    $city,
    $name,
    $email,
    $mobile,
    $amount,
    $status,
    $plate,
    $inv,
    $c2pId,
    $paymentLink,
    $shortCode,
    $shortLink,
    $cancelLink,
    $createdBy,
    $username,
    $sfAccountID,
    $sfVehicleID,
    $PaymentType,
    $sfCaseID,
    $ClientPreferredLanguage
);

if (!$stmt->execute()) {
    die("Database Insert Error: " . $stmt->error);
}

/* =========================
   9. Redirect
   ========================= */
header("Location: /pay/payment-details.php?clik2pay=" . urlencode($c2pId));
exit;
