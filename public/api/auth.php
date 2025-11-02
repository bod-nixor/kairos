<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

function upsert_role_and_get_id(PDO $pdo, string $roleName): int {
  // Ensure roles.name is UNIQUE in your schema (you already set that earlier)
  $stmt = $pdo->prepare("
    INSERT INTO roles (name) VALUES (:name)
    ON DUPLICATE KEY UPDATE role_id = LAST_INSERT_ID(role_id)
  ");
  $stmt->execute([':name' => $roleName]);
  return (int)$pdo->lastInsertId();
}

/**
 * Minimal JWT verify for Google ID token:
 * - Fetch Google's certs by 'kid' (cached 5 min)
 * - Verify signature + exp/iat + aud + iss
 * - Enforce hd (domain) against ALLOWED_DOMAIN
 */
function get_google_certs(): array {
  static $cache = null, $expires = 0;
  if ($cache && time() < $expires) return $cache;

  $ch = curl_init('https://www.googleapis.com/oauth2/v3/certs');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
  ]);
  $body = curl_exec($ch);
  if (!$body) throw new Exception('Failed to fetch Google certs');
  $info = curl_getinfo($ch);
  curl_close($ch);

  $cache = json_decode($body, true);
  $ttl = 300;
  if (!empty($info['download_content_length'])) $ttl = 300;
  $expires = time() + $ttl;
  return $cache ?? [];
}

function base64url_decode_str(string $s): string {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  return base64_decode($s);
}

function verify_google_id_token(string $idToken, string $clientId): array {
  [$h64, $p64, $s64] = explode('.', $idToken);
  $header = json_decode(base64url_decode_str($h64), true);
  $payload = json_decode(base64url_decode_str($p64), true);
  $sig     = base64url_decode_str($s64);

  if (!$header || !$payload) throw new Exception('Invalid token');
  if (empty($header['kid'])) throw new Exception('No kid');
  if (!in_array($header['alg'], ['RS256'], true)) throw new Exception('Bad alg');

  // Verify aud, iss, exp
  $now = time();
  if (empty($payload['aud']) || $payload['aud'] !== $clientId) throw new Exception('Bad aud');
  if (empty($payload['iss']) || !in_array($payload['iss'], ['https://accounts.google.com', 'accounts.google.com'], true)) {
    throw new Exception('Bad iss');
  }
  if (empty($payload['exp']) || $payload['exp'] < $now) throw new Exception('Token expired');

  // Find cert by kid
  $certs = get_google_certs();
  if (empty($certs['keys'])) throw new Exception('No certs');
  $key = null;
  foreach ($certs['keys'] as $k) {
    if ($k['kid'] === $header['kid']) { $key = $k; break; }
  }
  if (!$key) throw new Exception('No matching cert');

  // Build public key
  $pem = null;
  if ($key['kty'] === 'RSA' && !empty($key['n']) && !empty($key['e'])) {
    $mod = base64url_decode_str($key['n']);
    $exp = base64url_decode_str($key['e']);
    $rsa = openssl_pkey_get_details(openssl_pkey_new([
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
      'private_key_bits' => 2048
    ]));
    // Create an RSA public key from n/e
    $seq = asn1_sequence(
      asn1_sequence(asn1_object_identifier("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"), asn1_null()),
      asn1_bit_string(encode_rsa_mod_exp($mod, $exp))
    );
    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($seq), 64, "\n")."-----END PUBLIC KEY-----\n";
  }
  if (!$pem) throw new Exception('Failed to build public key');

  // Verify signature
  $ok = openssl_verify("$h64.$p64", $sig, $pem, OPENSSL_ALGO_SHA256);
  if ($ok !== 1) throw new Exception('Bad signature');

  return $payload;
}

/* Helpers to build SubjectPublicKeyInfo (quick ASN.1 builders) */
function asn1_len($l){ if($l<128)return chr($l); $s=''; while($l){$s=chr($l&0xff).$s;$l>>=8;} return chr(0x80|strlen($s)).$s; }
function asn1_tlv($t,$v){ return $t.asn1_len(strlen($v)).$v; }
function asn1_sequence(...$parts){ return asn1_tlv("\x30", implode('', $parts)); }
function asn1_object_identifier($oid){ return asn1_tlv("\x06", $oid); }
function asn1_null(){ return "\x05\x00"; }
function asn1_bit_string($s){ return asn1_tlv("\x03", "\x00".$s); }
function asn1_integer($i){ if($i==='' )$i="\x00"; if(ord($i[0])>0x7f){$i="\x00".$i;} return asn1_tlv("\x02",$i); }
function encode_rsa_mod_exp($n,$e){ return asn1_sequence(asn1_integer($n), asn1_integer($e)); }

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$credential = $input['credential'] ?? '';

if (!$credential) json_out(['success'=>false, 'error'=>'missing credential'], 400);

try {
  $clientId = env('GOOGLE_CLIENT_ID');
  if (!is_string($clientId) || $clientId === '') {
    throw new Exception('Google client ID is not configured');
  }

  $payload = verify_google_id_token($credential, $clientId);

  // Domain check
  $hd = $payload['hd'] ?? '';
  if (strcasecmp($hd, ALLOWED_DOMAIN) !== 0) {
    throw new Exception('Unauthorized domain');
  }

  // Upsert user into DB
  $email   = $payload['email'] ?? '';
  $name    = $payload['name'] ?? '';
  $picture = $payload['picture'] ?? '';
  $sub     = $payload['sub'] ?? ''; // Google unique user ID

  if (!$email || !$sub) throw new Exception('Invalid payload');

  $pdo = db();
  
  // Ensure default role exists and get its id
  $defaultRoleId = upsert_role_and_get_id($pdo, DEFAULT_ROLE_NAME);
  
  $stmt = $pdo->prepare("
    INSERT INTO users (google_id, email, name, picture_url, is_active, role_id)
    VALUES (:gid, :email, :name, :pic, 1, :role_id)
    ON DUPLICATE KEY UPDATE
      name = VALUES(name),
      picture_url = VALUES(picture_url),
      is_active = 1,
      role_id = COALESCE(users.role_id, VALUES(role_id))
  ");
  
  $stmt->execute([
    ':gid'      => $sub,
    ':email'    => $email,
    ':name'     => $name,
    ':pic'      => $picture,
    ':role_id'  => $defaultRoleId,
  ]);

  // Load user (to get user_id and role_id)
  $u = $pdo->prepare("SELECT user_id, email, name, picture_url, role_id FROM users WHERE email = :email LIMIT 1");
  $u->execute([':email'=>$email]);
  $user = $u->fetch();

  $_SESSION['user'] = $user;

  json_out(['success'=>true, 'user'=>$user]);
} catch (Throwable $e) {
  json_out(['success'=>false, 'error'=>$e->getMessage()], 401);
}