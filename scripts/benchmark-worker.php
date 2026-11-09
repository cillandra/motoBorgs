<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
/** Five independent HTTP sessions use this worker only against loopback benchmark servers. */
if (($argv[1] ?? '') === '--verify') {
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== 'moto_benchmark') {
        throw new RuntimeException('Benchmark verification refuses this database.');
    }
    $row = DB::selectOne('SELECT (SELECT COUNT(*) FROM variants) AS variants,(SELECT COUNT(*) FROM sales) AS sales,(SELECT COUNT(DISTINCT user_id) FROM sessions WHERE user_id IS NOT NULL) AS signed_in_users,(SELECT COUNT(*) FROM variants v LEFT JOIN (SELECT variant_id,SUM(delta) AS balance FROM stock_movements GROUP BY variant_id) m ON m.variant_id=v.id WHERE v.stock<>COALESCE(m.balance,0)) AS stock_mismatches,VERSION() AS database_version');
    echo json_encode((array) $row + ['php' => PHP_VERSION, 'laravel' => $app->version()], JSON_THROW_ON_ERROR);
    exit;
}
[$script,$origin,$number,$iterations,$destination,$barrier] = $argv;
if (! preg_match('~^http://127\.0\.0\.1:81\d\d$~D', $origin) || ! ctype_digit($number)) {
    throw new RuntimeException('Only local benchmark origins are allowed.');
}
$cookie = $destination.'.cookies';
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false]);
function request(string $path, array|string|null $body = null): array
{
    global $curl,$origin;
    $headers = [];
    $json = is_array($body);
    curl_setopt_array($curl, [CURLOPT_URL => $origin.$path, CURLOPT_POST => $body !== null, CURLOPT_HTTPHEADER => $json ? ['Accept: application/json', 'Content-Type: application/json'] : [], CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$headers) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }

        return strlen($line);
    }]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $json ? json_encode($body) : $body);
    }
    $start = hrtime(true);
    $response = curl_exec($curl);
    $duration = (hrtime(true) - $start) / 1e6;
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($response === false || $status >= 400) {
        throw new RuntimeException('Benchmark HTTP '.$status.' at '.$path.': '.substr((string) $response, 0, 160));
    }

    return ['body' => $response, 'status' => $status, 'headers' => $headers, 'duration' => $duration];
}
function token(string $html): string
{
    if (! preg_match('/name="(?:_token|csrf-token)" (?:value|content)="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('CSRF token unavailable.');
    }

    return html_entity_decode($match[1]);
}
try {
    $login = request('/login');
    request('/login', http_build_query(['username' => 'bench-staff-'.$number, 'password' => 'Synthetic benchmark passphrase 2026!', '_token' => token($login['body'])]));
    request('/inventory');
    request('/sales');
    file_put_contents($barrier.'.ready.'.$number, 'ready');
    $deadline = microtime(true) + 60;
    while (! is_file($barrier.'.go') && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (! is_file($barrier.'.go')) {
        throw new RuntimeException('Benchmark start barrier timed out.');
    }
    $result = ['worker' => (int) $number, 'page_ms' => [], 'search_ms' => [], 'checkout_http_ms' => [], 'checkout_commit_ms' => [], 'sales' => []];
    $start = date('Y-m-d', strtotime('-90 days'));
    $end = date('Y-m-d');
    for ($index = 0; $index < (int) $iterations; $index++) {
        foreach (['/inventory', '/sales?start='.$start.'&end='.$end, '/dashboard'] as $path) {
            $result['page_ms'][] = request($path)['duration'];
        }
        foreach (['/inventory?q=BENCH-09', '/sales?q=Benchmark&start='.$start.'&end='.$end] as $path) {
            $result['search_ms'][] = request($path)['duration'];
        }
        $pos = request('/pos');
        $csrf = token($pos['body']);
        preg_match('/data-cart="(\d+)" data-version="(\d+)"/', $pos['body'], $cart);
        if (count($cart) !== 3) {
            throw new RuntimeException('POS draft unavailable.');
        }
        $mutation = request('/pos/carts/'.$cart[1], ['_token' => $csrf, 'version' => (int) $cart[2], 'action' => 'add', 'variant_id' => (int) $number, 'quantity' => 1]);
        $quote = json_decode($mutation['body'], true, 512, JSON_THROW_ON_ERROR)['quote'];
        $checkout = request('/checkout/'.$cart[1], ['_token' => $csrf, 'version' => $quote['version'], 'request_key' => (string) Str::uuid(), 'method' => 'cash', 'received' => '100.00', 'verified' => true]);
        if ($checkout['status'] !== 201) {
            throw new RuntimeException('Checkout did not create exactly one new sale.');
        }
        preg_match('/checkout;dur=([0-9.]+)/', $checkout['headers']['server-timing'] ?? '', $timing);
        if (! isset($timing[1])) {
            throw new RuntimeException('Checkout commit timing unavailable.');
        }
        $result['checkout_http_ms'][] = $checkout['duration'];
        $result['checkout_commit_ms'][] = (float) $timing[1];
        $result['sales'][] = json_decode($checkout['body'], true)['sale_id'];
    }
    curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    $result['session_hash'] = hash_file('sha256', $cookie);
    file_put_contents($destination, json_encode($result, JSON_THROW_ON_ERROR));
} finally {
    curl_close($curl);
    @unlink($cookie);
}
