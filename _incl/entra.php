<?php
// Microsoft Entra / Graph: core OAuth2 + OIDC library (curl+openssl).
//
// Provides:
//   - certificate client assertion (JWT signed with our private key) for
//     confidential-client auth in BOTH the auth-code exchange and app-only flow;
//   - app-only token acquisition (client_credentials) for Graph Mail.Send;
//   - id_token validation against the tenant JWKS (RS256) for SSO.
//
// Config comes from entra-config.php. The private key never leaves this server.

require_once __DIR__ . '/simple-mysqli.php'; // not used directly, but keeps include order sane for callers

function entra_cfg(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/entra-config.php';
    return $c;
}

// --- base64url ---------------------------------------------------------------
function entra_b64url(string $d): string {
    return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}
function entra_b64url_decode(string $d): string {
    return base64_decode(strtr($d, '-_', '+/') . str_repeat('=', (4 - strlen($d) % 4) % 4));
}

// --- endpoints ---------------------------------------------------------------
function entra_authorize_endpoint(): string { return rtrim(entra_cfg()['authority'], '/') . '/oauth2/v2.0/authorize'; }
function entra_token_endpoint(): string     { return rtrim(entra_cfg()['authority'], '/') . '/oauth2/v2.0/token'; }

// --- HTTP helpers ------------------------------------------------------------
function entra_http(string $url, ?array $postForm = null, array $headers = []): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 15];
    if ($postForm !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($postForm);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    // curl_close() is deprecated from PHP 8.5 and a no-op since 8.0.
    if ($body === false) { $e = curl_error($ch); throw new RuntimeException("Entra request failed: {$e}"); }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException("Entra returned non-JSON (HTTP {$code}): " . substr($body, 0, 300));
    if ($code >= 400) {
        $msg = $data['error_description'] ?? $data['error'] ?? substr($body, 0, 300);
        throw new RuntimeException("Entra error (HTTP {$code}): {$msg}");
    }
    return $data;
}
function entra_get_json(string $url): array { return entra_http($url); }

// --- certificate client assertion -------------------------------------------
/** base64url SHA-1 thumbprint of the public cert, for the JWT `x5t` header. */
function entra_cert_x5t(): string {
    $pem = file_get_contents(entra_cfg()['cert_pub']);
    if ($pem === false) throw new RuntimeException('cannot read Entra public cert');
    $raw = openssl_x509_fingerprint($pem, 'sha1', true);
    if ($raw === false) throw new RuntimeException('cannot fingerprint Entra cert');
    return entra_b64url($raw);
}

/** A JWT proving we hold the private key, addressed to $audience (the token endpoint). */
function entra_client_assertion(string $audience): string {
    $cfg = entra_cfg();
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'x5t' => entra_cert_x5t()];
    $payload = [
        'aud' => $audience,
        'iss' => $cfg['client_id'],
        'sub' => $cfg['client_id'],
        'jti' => bin2hex(random_bytes(16)),
        'nbf' => $now - 60,
        'exp' => $now + 300,
    ];
    $signingInput = entra_b64url(json_encode($header)) . '.' . entra_b64url(json_encode($payload));
    $key = openssl_pkey_get_private('file://' . $cfg['cert_key']);
    if (!$key) throw new RuntimeException('cannot load Entra private key (' . $cfg['cert_key'] . ')');
    $sig = '';
    if (!openssl_sign($signingInput, $sig, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('failed to sign client assertion');
    }
    return $signingInput . '.' . entra_b64url($sig);
}

// --- app-only token (client_credentials) -------------------------------------
function entra_app_token(string $scope = 'https://graph.microsoft.com/.default'): string {
    static $cache = [];
    if (isset($cache[$scope]) && $cache[$scope]['exp'] > time() + 60) return $cache[$scope]['tok'];
    $endpoint = entra_token_endpoint();
    $res = entra_http($endpoint, [
        'client_id'             => entra_cfg()['client_id'],
        'scope'                 => $scope,
        'grant_type'            => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion'      => entra_client_assertion($endpoint),
    ]);
    $cache[$scope] = ['tok' => $res['access_token'], 'exp' => time() + (int)($res['expires_in'] ?? 3600)];
    return $res['access_token'];
}

// --- JWKS + id_token validation ---------------------------------------------
/**
 * The tenant's signing keys, cached for a day.
 *
 * NOT IN /tmp, and that was the original home. These keys are what decides
 * whether an id_token is genuine, and /tmp on this box is shared (the php-fpm
 * unit runs with PrivateTmp=no) at a path anybody could predict from the tenant
 * id. Cached security material does not belong somewhere every other account on
 * the server can write. Forging a token would still need the response from the
 * token endpoint, which arrives over TLS, so this was never a one-step break --
 * it is simply the wrong place to keep the thing that answers "is this key
 * ours". Same directory as the session store, same 0700, same owner.
 */
function entra_jwks(bool $forceRefresh = false): array {
    $cfg = entra_cfg();
    $dir = defined('AUTH_SESSION_PATH') && is_dir(AUTH_SESSION_PATH) ? AUTH_SESSION_PATH : sys_get_temp_dir();
    $cacheFile = $dir . '/entra-jwks-' . $cfg['tenant_id'] . '.json';
    if (!$forceRefresh && is_readable($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        $j = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($j) && !empty($j['keys'])) return $j;
    }
    $conf = entra_get_json(rtrim($cfg['authority'], '/') . '/v2.0/.well-known/openid-configuration');
    $jwks = entra_get_json($conf['jwks_uri']);
    // Written then tightened: file_put_contents obeys the umask, and this must
    // not be left readable by anyone but the pool user.
    if (@file_put_contents($cacheFile, json_encode($jwks)) !== false) @chmod($cacheFile, 0600);
    return $jwks;
}

/** Convert a JWKS entry (uses its x5c cert, which MS always provides) to a PEM public key. */
function entra_jwk_to_pem(array $jwk): string {
    if (empty($jwk['x5c'][0])) throw new RuntimeException('JWK has no x5c');
    $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($jwk['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
    $pub = openssl_pkey_get_public($cert);
    if (!$pub) throw new RuntimeException('cannot load JWK cert');
    return openssl_pkey_get_details($pub)['key'];
}

/**
 * Validate an id_token: RS256 signature against JWKS, then iss/aud/tid/exp/nbf/nonce.
 * @return array the decoded, verified claims.
 */
function entra_validate_id_token(string $jwt, string $expectedNonce): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new RuntimeException('malformed id_token');
    [$h64, $p64, $s64] = $parts;
    $header = json_decode(entra_b64url_decode($h64), true);
    $claims = json_decode(entra_b64url_decode($p64), true);
    if (!is_array($header) || !is_array($claims)) throw new RuntimeException('unparseable id_token');
    if (($header['alg'] ?? '') !== 'RS256') throw new RuntimeException('unexpected id_token alg');

    $kid = $header['kid'] ?? '';
    $pem = null;
    foreach (entra_jwks()['keys'] ?? [] as $k) { if (($k['kid'] ?? '') === $kid) { $pem = entra_jwk_to_pem($k); break; } }
    if ($pem === null) { // key rotation, refresh once
        foreach (entra_jwks(true)['keys'] ?? [] as $k) { if (($k['kid'] ?? '') === $kid) { $pem = entra_jwk_to_pem($k); break; } }
    }
    if ($pem === null) throw new RuntimeException('no matching JWKS key for id_token');

    if (openssl_verify($h64 . '.' . $p64, entra_b64url_decode($s64), $pem, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('id_token signature invalid');
    }

    $cfg = entra_cfg();
    $now = time();
    $skew = 120;
    if (($claims['iss'] ?? '') !== 'https://login.microsoftonline.com/' . $cfg['tenant_id'] . '/v2.0') {
        throw new RuntimeException('id_token: bad iss');
    }
    if (($claims['aud'] ?? '') !== $cfg['client_id']) throw new RuntimeException('id_token: bad aud');
    if (($claims['tid'] ?? '') !== $cfg['tenant_id']) throw new RuntimeException('id_token: bad tid');
    if ((int)($claims['exp'] ?? 0) < $now - $skew)   throw new RuntimeException('id_token expired');
    if ((int)($claims['nbf'] ?? 0) > $now + $skew)   throw new RuntimeException('id_token not yet valid');
    if (!hash_equals($expectedNonce, (string)($claims['nonce'] ?? ''))) throw new RuntimeException('id_token nonce mismatch');
    return $claims;
}
