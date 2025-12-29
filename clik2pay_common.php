<?php

function json_input(): array
{
    $c = file_get_contents('php://input');
    return json_decode($c, true) ?: [];
}

function c2p_config_for_city($city)
{
    $map = [
        'Montreal' => 'LocationLouelec',
        'Toronto' => 'WeeveElectricVehicles',
        'Ottawa' => 'WeeveElectricVehicles',
        'Vancouver' => 'WeeveSubscription',
    ];

    if (!isset($map[$city])) {
        throw new Exception("Unsupported city: $city");
    }

    $key = $map[$city];
    $all = [
        'LocationLouelec' => [
            'API_BASE' => 'https://api.clik2pay.com/open/v1',
            'AUTH_URL' => 'https://api-auth.clik2pay.com/oauth2/token',
            'API_KEY' => '2nzV40',
            'CLIENT_ID' => '41v',
            'CLIENT_SEC' => '17tq64',
        ],
        'WeeveElectricVehicles' => [
            'API_BASE' => 'https://api.clik2pay.com/open/v1',
            'AUTH_URL' => 'https://api-auth.clik2pay.com/oauth2/token',
            'API_KEY' => 'QCIi4',
            'CLIENT_ID' => '1t3h',
            'CLIENT_SEC' => '88dn',
        ],
        'WeeveSubscription' => [
            'API_BASE' => 'https://api.clik2pay.com/open/v1',
            'AUTH_URL' => 'https://api-auth.clik2pay.com/oauth2/token',
            'API_KEY' => 'q8k7',
            'CLIENT_ID' => '4sp',
            'CLIENT_SEC' => 'c3nh',
        ],
    ];

    if (!isset($all[$key])) {
        throw new Exception("Configuration missing for $key");
    }
    return $all[$key];
}

function c2p_curl($method, $url, $headers, $body = null)
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $info['http_code'], 'resp' => $res, 'err' => $err];
}

function bearer_token($C2P)
{
    // Simple caching
    $tmp = sys_get_temp_dir();
    $f = $tmp . '/token_' . md5($C2P['CLIENT_ID']) . '.json';
    if (file_exists($f)) {
        $data = json_decode(file_get_contents($f), true);
        if ($data && $data['exp'] > time() + 30) {
            return $data['token'];
        }
    }

    $auth = base64_encode($C2P['CLIENT_ID'] . ':' . $C2P['CLIENT_SEC']);
    $r = c2p_curl('POST', $C2P['AUTH_URL'], [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ], 'grant_type=client_credentials&scope=payment_request%2Fall');

    if ($r['code'] < 200 || $r['code'] >= 300) {
        throw new Exception("Auth failed: " . $r['code'] . " " . $r['resp']);
    }

    $j = json_decode($r['resp'], true);
    if (!isset($j['access_token'])) {
        throw new Exception("No access_token in auth response");
    }

    $exp = time() + ($j['expires_in'] ?? 3600);
    file_put_contents($f, json_encode(['token' => $j['access_token'], 'exp' => $exp]));
    return $j['access_token'];
}
