<?php
// gateway/proto.php — پارس هدرهای VLESS و Trojan و رمزنگاری AEAD شادوساکس
// منطق یک‌به‌یک با protocol/vless/vless.py و protocol/trojan/trojan.py و protocol/shadowsocks/shadowsocks.py

declare(strict_types=1);

// ── VLESS ────────────────────────────────────────────────────────────────────
// ورودی: اولین چانک باینری. خروجی: [command, address, port, payload]
//  version(1)+uuid(16)+addon_len(1)+addons+cmd(1)+port(2)+atyp(1)+addr+payload

function parse_vless_header(string $chunk): array
{
    if (strlen($chunk) < 24) {
        throw new RuntimeException('chunk too small');
    }
    $pos = 1;               // version
    $pos += 16;             // uuid
    $addonLen = ord($chunk[$pos]);
    $pos += 1 + $addonLen;  // addons
    $command = ord($chunk[$pos]); $pos += 1;
    $port = unpack('n', substr($chunk, $pos, 2))[1]; $pos += 2;
    $atyp = ord($chunk[$pos]); $pos += 1;
    if ($atyp === 1) {
        $address = implode('.', unpack('C4', substr($chunk, $pos, 4))); $pos += 4;
    } elseif ($atyp === 2) {
        $dlen = ord($chunk[$pos]); $pos += 1;
        $address = substr($chunk, $pos, $dlen); $pos += $dlen;
    } elseif ($atyp === 4) {
        $ab = unpack('C16', substr($chunk, $pos, 16)); $pos += 16;
        $parts = [];
        for ($i = 1; $i <= 16; $i += 2) {
            $parts[] = sprintf('%02x%02x', $ab[$i], $ab[$i + 1]);
        }
        $address = implode(':', $parts);
    } else {
        throw new RuntimeException("unknown addr type: $atyp");
    }
    return [$command, $address, $port, substr($chunk, $pos)];
}

// ── Trojan ───────────────────────────────────────────────────────────────────
// 56 bytes hex(sha224(password)) + CRLF + cmd(1) + atyp(1) + addr + port(2) + CRLF + payload

const TROJAN_HEADER_MIN = 56 + 2 + 1 + 1 + 2 + 2; // بدون addr

function trojan_hash(string $password): string
{
    return hash('sha224', $password);
}

function parse_trojan_header(string $chunk): array
{
    if (strlen($chunk) < TROJAN_HEADER_MIN) {
        throw new RuntimeException('chunk too small for trojan header');
    }
    $pwHash = substr($chunk, 0, 56);
    $pos = 56;
    if (substr($chunk, $pos, 2) !== "\r\n") {
        throw new RuntimeException('missing CRLF after hash');
    }
    $pos += 2;
    $command = ord($chunk[$pos]); $pos += 1;
    $atyp = ord($chunk[$pos]); $pos += 1;
    if ($atyp === 1) {
        $address = implode('.', unpack('C4', substr($chunk, $pos, 4))); $pos += 4;
    } elseif ($atyp === 2) {
        $dlen = ord($chunk[$pos]); $pos += 1;
        $address = substr($chunk, $pos, $dlen); $pos += $dlen;
    } elseif ($atyp === 4) {
        $ab = unpack('C16', substr($chunk, $pos, 16)); $pos += 16;
        $parts = [];
        for ($i = 1; $i <= 16; $i += 2) {
            $parts[] = sprintf('%02x%02x', $ab[$i], $ab[$i + 1]);
        }
        $address = implode(':', $parts);
    } else {
        throw new RuntimeException("unknown trojan atyp: $atyp");
    }
    $port = unpack('n', substr($chunk, $pos, 2))[1]; $pos += 2;
    if (substr($chunk, $pos, 2) !== "\r\n") {
        throw new RuntimeException('missing trailing CRLF');
    }
    $pos += 2;
    return [$pwHash, $command, $address, $port, substr($chunk, $pos)];
}

// ── Shadowsocks AEAD (کلاینت→سرور) ──────────────────────────────────────────
// salt(32) + [enc_len(2)+tag(16)] + [enc_payload+tag]... با nonce little-endian counter

const SS_KEY_LEN = 32;
const SS_SALT_LEN = 32;
const SS_NONCE_LEN = 12;
const SS_TAG_LEN = 16;

function ss_cipher_cls(string $name): string
{
    return match ($name) {
        'chacha20-ietf-poly1305' => 'chacha20-ietf-poly1305',
        'aes-256-gcm' => 'aes-256-gcm',
        default => 'chacha20-ietf-poly1305',
    };
}

/** EVP_BytesToKey سازگار با shadowsocks */
function ss_derive_key(string $password, int $key_len = SS_KEY_LEN): string
{
    $d = ''; $prev = '';
    while (strlen($d) < $key_len) {
        $prev = md5($prev . $password, true);
        $d .= $prev;
    }
    return substr($d, 0, $key_len);
}

function ss_hkdf_sha1(string $key, string $salt, string $info, int $length): string
{
    $prk = hash_hmac('sha1', $key, $salt, true);
    $okm = ''; $t = ''; $i = 1;
    while (strlen($okm) < $length) {
        $t = hash_hmac('sha1', $t . $info . chr($i), $prk, true);
        $okm .= $t;
        $i++;
    }
    return substr($okm, 0, $length);
}

function ss_subkey(string $master, string $salt): string
{
    return ss_hkdf_sha1($master, $salt, 'ss-subkey', SS_KEY_LEN);
}

function ss_nonce(int $counter): string
{
    return str_pad(pack('P', $counter), SS_NONCE_LEN, "\0"); // little-endian u64 → 12 bytes
}

/** رمزنگاری یک چانک کامل (طول+payload) با AEAD */
function ss_encrypt_chunk(string $cipher, string $key, string $payload, int &$counter): string
{
    $ln = pack('n', strlen($payload));
    if ($cipher === 'aes-256-gcm') {
        $enc = openssl_encrypt($ln, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, ss_nonce($counter), $tag);
        if ($enc === false) throw new RuntimeException('enc len fail');
        $enc2 = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, ss_nonce($counter + 1), $tag2);
        if ($enc2 === false) throw new RuntimeException('enc payload fail');
    } else {
        // chacha20-ietf-poly1305 با sodium
        $nonce = ss_nonce($counter);
        $enc = sodium_crypto_aead_chacha20poly1305_ietf_encrypt($ln, '', $nonce, $key);
        $enc2 = sodium_crypto_aead_chacha20poly1305_ietf_encrypt($payload, '', ss_nonce($counter + 1), $key);
    }
    $counter += 2;
    return $enc . $enc2;
}

/**
 * جریان رمزگشایی استریمی کلاینت→سرور.
 * کلاس حالت‌دار: با feed() بایت می‌گیرد و tryDecrypt() هر چانک کامل را می‌دهد.
 */
class SsDecryptor
{
    private string $buf = '';
    private ?string $key = null;
    private string $cipher;
    private string $master;
    private int $nonce = 0;
    public string $decSalt = '';

    public function __construct(string $cipher, string $masterKey)
    {
        $this->cipher = ss_cipher_cls($name = $cipher);
        $this->master = $masterKey;
    }

    public function feed(string $data): void
    {
        $this->buf .= $data;
    }

    private function ensureKey(): bool
    {
        if ($this->key !== null) return true;
        if (strlen($this->buf) < SS_SALT_LEN) return false;
        $this->decSalt = substr($this->buf, 0, SS_SALT_LEN);
        $this->buf = substr($this->buf, SS_SALT_LEN);
        $this->key = ss_subkey($this->master, $this->decSalt);
        return true;
    }

    /** @return string[] چانک‌های کامل رمزگشایی‌شده */
    public function tryDecrypt(): array
    {
        if (!$this->ensureKey()) return [];
        $out = [];
        while (true) {
            $need = 2 + SS_TAG_LEN;
            if (strlen($this->buf) < $need) break;
            $encLen = substr($this->buf, 0, $need);
            if ($this->cipher === 'aes-256-gcm') {
                $tag = substr($encLen, 2, SS_TAG_LEN);
                $lenBin = openssl_decrypt(substr($encLen, 0, 2), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, ss_nonce($this->nonce), $tag);
            } else {
                $lenBin = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($encLen, '', ss_nonce($this->nonce), $this->key);
            }
            if ($lenBin === false) throw new RuntimeException('AEAD decrypt (length) failed — bad key/tag');
            $this->nonce++;
            $length = unpack('n', $lenBin)[1];
            if ($length > 0x3FFF) throw new RuntimeException('invalid SS AEAD chunk length');
            $needAll = 2 + SS_TAG_LEN + $length + SS_TAG_LEN;
            if (strlen($this->buf) < $needAll) break;
            $encPayload = substr($this->buf, 2 + SS_TAG_LEN, $length + SS_TAG_LEN);
            $this->buf = substr($this->buf, $needAll);
            if ($this->cipher === 'aes-256-gcm') {
                $tag2 = substr($encPayload, $length, SS_TAG_LEN);
                $payload = openssl_decrypt(substr($encPayload, 0, $length), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, ss_nonce($this->nonce), $tag2);
            } else {
                $payload = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($encPayload, '', ss_nonce($this->nonce), $this->key);
            }
            if ($payload === false) throw new RuntimeException('AEAD decrypt (payload) failed');
            $this->nonce++;
            $out[] = $payload;
        }
        return $out;
    }
}

/** پارس SOCKS5-like addr: atyp+addr+port → [address, port, consumed] */
function parse_socks5_addr(string $buf): array
{
    if (strlen($buf) < 2) throw new RuntimeException('too short');
    $atyp = ord($buf[0]);
    $pos = 1;
    if ($atyp === 1) {
        if (strlen($buf) < $pos + 6) throw new RuntimeException('short ipv4');
        $address = implode('.', unpack('C4', substr($buf, $pos, 4))); $pos += 4;
    } elseif ($atyp === 2) {
        $dlen = ord($buf[$pos]); $pos += 1;
        if (strlen($buf) < $pos + $dlen + 2) throw new RuntimeException('short domain');
        $address = substr($buf, $pos, $dlen); $pos += $dlen;
    } elseif ($atyp === 4) {
        if (strlen($buf) < $pos + 18) throw new RuntimeException('short ipv6');
        $ab = unpack('C16', substr($buf, $pos, 16)); $pos += 16;
        $parts = [];
        for ($i = 1; $i <= 16; $i += 2) $parts[] = sprintf('%02x%02x', $ab[$i], $ab[$i + 1]);
        $address = implode(':', $parts);
    } else {
        throw new RuntimeException("unknown atyp $atyp");
    }
    $port = unpack('n', substr($buf, $pos, 2))[1]; $pos += 2;
    return [$address, $port, $pos];
}
