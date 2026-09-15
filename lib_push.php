<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_push.php — Web Push nativo (VAPID + aes128gcm, RFC 8291/8188/8292).
 *
 * Sem dependências externas (usa openssl/hash_hkdf do PHP 7.3+). As chaves VAPID
 * são geradas uma vez e guardadas em configuracoes (onibus_vapid_pub/priv).
 *
 * Uso:
 *   $vp = push_vapid_public();                 // chave pública p/ o navegador (base64url)
 *   push_enviar($endpoint, $p256dh, $auth, $json);  // envia 1 push
 */

/* ------------------------------- base64url ------------------------------ */
function push_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function push_b64url_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return (string) base64_decode($s);
}

/* --------------------------- Chaves VAPID (cfg) -------------------------- */
/** Gera (uma vez) e devolve ['pub'=>rawB64url, 'priv'=>PEM, 'sub'=>mailto]. */
function push_vapid_keys(): array
{
    $pub  = (string) cfg_get('onibus_vapid_pub', '');
    $priv = (string) cfg_get('onibus_vapid_priv', '');
    if ($pub === '' || $priv === '') {
        $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($res, $privPem);
        $d = openssl_pkey_get_details($res);
        $x = str_pad((string) $d['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string) $d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $rawPub = "\x04" . $x . $y;
        $pub  = push_b64url_encode($rawPub);
        $priv = (string) $privPem;
        cfg_set('onibus_vapid_pub', $pub);
        cfg_set('onibus_vapid_priv', $priv);
    }
    $sub = (string) cfg_get('onibus_vapid_sub', '');
    if ($sub === '') {
        $sub = 'mailto:' . (defined('DEV_EMAIL') ? DEV_EMAIL : 'contato@publishdev.com.br');
    }
    return ['pub' => $pub, 'priv' => $priv, 'sub' => $sub];
}
function push_vapid_public(): string
{
    return push_vapid_keys()['pub'];
}

/* Monta uma chave pública EC (PEM) a partir do ponto bruto de 65 bytes. */
function push_pub_from_raw(string $raw65): string
{
    // SubjectPublicKeyInfo (P-256) + BIT STRING: prefixo termina em ...0004 (ponto não comprimido)
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004')
        . substr($raw65, 1); // remove o 0x04 do ponto (já incluído no prefixo)
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return $pem;
}

/* Converte assinatura ECDSA DER -> raw R||S (64 bytes) para JWT ES256. */
function push_der_to_raw(string $der): string
{
    $off = 0;
    if (ord($der[$off++]) !== 0x30) {
        return '';
    }
    $len = ord($der[$off++]);
    if ($len & 0x80) {
        $off += ($len & 0x7f);
    }
    $readInt = function () use ($der, &$off) {
        $off++; // 0x02
        $l = ord($der[$off++]);
        $v = substr($der, $off, $l);
        $off += $l;
        return ltrim($v, "\0");
    };
    $r = $readInt();
    $s = $readInt();
    return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
}

/* JWT VAPID (ES256) para o endpoint (audience = origem do push service). */
function push_vapid_jwt(string $audience): string
{
    $k = push_vapid_keys();
    $header  = push_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = push_b64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => $k['sub'],
    ]));
    $data = $header . '.' . $payload;
    $sig = '';
    openssl_sign($data, $sig, $k['priv'], OPENSSL_ALGO_SHA256);
    return $data . '.' . push_b64url_encode(push_der_to_raw($sig));
}

/* Origem (scheme://host) de uma URL — audience do VAPID. */
function push_origin(string $url): string
{
    $p = parse_url($url);
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
}

/**
 * Envia 1 push. Retorna [ok, status, erro]. status 404/410 = inscrição morta (remover).
 * $payloadJson é a string JSON que o Service Worker recebe em event.data.
 */
function push_enviar(string $endpoint, string $p256dhB64, string $authB64, string $payloadJson): array
{
    if (!function_exists('openssl_pkey_derive')) {
        return ['ok' => false, 'status' => 0, 'erro' => 'sem openssl_pkey_derive'];
    }
    $uaPub = push_b64url_decode($p256dhB64);          // 65 bytes
    $authS = push_b64url_decode($authB64);            // 16 bytes
    if (strlen($uaPub) !== 65 || strlen($authS) < 16) {
        return ['ok' => false, 'status' => 0, 'erro' => 'chaves da inscrição inválidas'];
    }

    // par efêmero do servidor (as = application server)
    $as = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $asd = openssl_pkey_get_details($as);
    $asPub = "\x04" . str_pad((string) $asd['ec']['x'], 32, "\0", STR_PAD_LEFT)
                    . str_pad((string) $asd['ec']['y'], 32, "\0", STR_PAD_LEFT);

    // ECDH(as_priv, ua_pub)
    $uaKey = openssl_pkey_get_public(push_pub_from_raw($uaPub));
    if ($uaKey === false) {
        return ['ok' => false, 'status' => 0, 'erro' => 'falha ao ler chave da inscrição'];
    }
    $ecdh = openssl_pkey_derive($uaKey, $as);
    if ($ecdh === false) {
        return ['ok' => false, 'status' => 0, 'erro' => 'ECDH falhou'];
    }

    // IKM (RFC 8291): HKDF(salt=auth, ikm=ecdh, info="WebPush: info\0"+uaPub+asPub, 32)
    $keyInfo = "WebPush: info\x00" . $uaPub . $asPub;
    $ikm = hash_hkdf('sha256', $ecdh, 32, $keyInfo, $authS);

    // conteúdo (RFC 8188 aes128gcm) com salt aleatório de 16 bytes
    $salt = random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    $plain = $payloadJson . "\x02"; // delimitador de último registro
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) {
        return ['ok' => false, 'status' => 0, 'erro' => 'falha ao cifrar'];
    }
    $rs = 4096;
    $header = $salt . pack('N', $rs) . chr(strlen($asPub)) . $asPub;
    $body = $header . $cipher . $tag;

    $jwt = push_vapid_jwt(push_origin($endpoint));
    $headers = [
        'Authorization: vapid t=' . $jwt . ', k=' . push_vapid_public(),
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'TTL: 1800',
    ];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'erro' => $err ?: (string) $resp];
}
