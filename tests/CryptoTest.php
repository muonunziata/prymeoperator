<?php
use PrymeOperator\Security\Crypto;

$key    = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
$crypto = new Crypto( $key );

check( 'round-trips a secret', fn () => assert_true( $crypto->decrypt( $crypto->encrypt( 'token-123' ) ) === 'token-123' ) );
check( 'ciphertext differs each time', fn () => assert_true( $crypto->encrypt( 'x' ) !== $crypto->encrypt( 'x' ) ) );
check( 'tampered value returns null', function () use ( $crypto ) {
	$enc    = $crypto->encrypt( 'token' );
	$enc[-2] = 'A' === $enc[-2] ? 'B' : 'A';
	assert_true( null === $crypto->decrypt( $enc ) );
} );
check( 'other key returns null', fn () => assert_true( null === ( new Crypto( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) )->decrypt( $crypto->encrypt( 'token' ) ) ) );
check( 'plaintext legacy value returns null', fn () => assert_true( null === $crypto->decrypt( 'sl.plain-token' ) ) );
