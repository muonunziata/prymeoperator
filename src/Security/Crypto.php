<?php
namespace PrymeOperator\Security;

/**
 * Authenticated encryption for secrets stored in the database (cloud tokens).
 * Uses libsodium secretbox; the key is derived from the site's salts unless
 * one is passed in explicitly (tests).
 */
final class Crypto {

	private const PREFIX = 'po1:';

	private string $key;

	public function __construct( ?string $key = null ) {
		if ( null === $key ) {
			$key = sodium_crypto_generichash( wp_salt( 'auth' ) . '|prymeoperator', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}
		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'Invalid key length.' );
		}
		$this->key = $key;
	}

	public function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key ) );
	}

	/**
	 * Returns null when the value was tampered with or encrypted with another key.
	 */
	public function decrypt( string $encoded ): ?string {
		if ( ! str_starts_with( $encoded, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );
		return false === $plain ? null : $plain;
	}
}
