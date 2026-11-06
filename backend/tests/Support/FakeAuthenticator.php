<?php

declare(strict_types=1);

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use LogicException;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * A passkey, in software: what a phone or password manager does when the
 * browser asks it to make a credential (navigator.credentials.create()) or to
 * sign in with one (navigator.credentials.get()). It answers with a real P-256
 * key, "none" attestation, as passkeys do, and real signatures, so the server
 * checks it as it would any passkey. Answers are what the browser's
 * PublicKeyCredential.toJSON() writes.
 */
final class FakeAuthenticator
{
    private readonly OpenSSLAsymmetricKey $key;

    /** The credential's id, as the browser names it. */
    public readonly string $credentialId;

    /** The account handle the options it was made with gave. */
    private string $userHandle = '';

    private int $counter = 0;

    /**
     * @param string $aaguid Who made it, as a passkey says (all zeros: it doesn't say).
     */
    public function __construct(
        private readonly string $rpId = 'gallery.test',
        private readonly string $origin = GALLERY_ORIGIN,
        private readonly string $aaguid = '00000000-0000-0000-0000-000000000000',
    ) {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'config' => dirname(__DIR__) . '/Fixtures/openssl.cnf',
        ]);
        $this->key = $key instanceof OpenSSLAsymmetricKey ? $key : throw new LogicException('OpenSSL could not make a key: ' . openssl_error_string());
        $this->credentialId = random_bytes(16);
    }

    /**
     * Make the credential these options ask for (POST /account/passkeys/options).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function create(array $options, ?string $origin = null): array
    {
        $user = is_array($options['user'] ?? null) ? $options['user'] : [];
        $this->userHandle = Base64UrlSafe::decodeNoPadding((string) ($user['id'] ?? ''));

        $details = openssl_pkey_get_details($this->key);
        $point = is_array($details) && is_array($details['ec'] ?? null) ? $details['ec'] : [];
        $coordinate = static fn(mixed $value): string => str_pad(is_string($value) ? $value : '', 32, "\0", STR_PAD_LEFT);
        $publicKey = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)) // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)) // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)) // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($coordinate($point['x'] ?? null)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($coordinate($point['y'] ?? null)));

        $authenticatorData = hash('sha256', $this->rpId, true)
            . chr(0x01 | 0x04 | 0x40) // user present, user verified, credential data included
            . pack('N', $this->counter)
            . hex2bin(str_replace('-', '', $this->aaguid))
            . pack('n', strlen($this->credentialId)) . $this->credentialId
            . $publicKey;
        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authenticatorData));

        return $this->answer([
            'clientDataJSON' => self::encode($this->clientData('webauthn.create', $options, $origin)),
            'attestationObject' => self::encode((string) $attestation),
            'transports' => ['internal'],
        ]);
    }

    /**
     * Sign in with the credential, answering these options (POST /auth/passkey/options).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function get(array $options, ?string $origin = null): array
    {
        $this->counter++;
        $clientData = $this->clientData('webauthn.get', $options, $origin);
        $authenticatorData = hash('sha256', $this->rpId, true) . chr(0x01 | 0x04) . pack('N', $this->counter);
        openssl_sign($authenticatorData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $this->answer([
            'clientDataJSON' => self::encode($clientData),
            'authenticatorData' => self::encode($authenticatorData),
            'signature' => self::encode(is_string($signature) ? $signature : ''),
            'userHandle' => self::encode($this->userHandle),
        ]);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function answer(array $response): array
    {
        $id = self::encode($this->credentialId);

        return ['id' => $id, 'rawId' => $id, 'type' => 'public-key', 'response' => $response, 'clientExtensionResults' => [], 'authenticatorAttachment' => 'platform'];
    }

    /** @param array<string, mixed> $options */
    private function clientData(string $type, array $options, ?string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $options['challenge'] ?? '',
            'origin' => $origin ?? $this->origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function encode(string $bytes): string
    {
        return Base64UrlSafe::encodeUnpadded($bytes);
    }
}
