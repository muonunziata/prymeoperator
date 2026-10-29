<?php
/**
 * Dependency-free test runner for the WordPress-independent classes.
 * Usage: php tests/run.php
 */

require __DIR__ . '/../src/Autoloader.php';
PrymeOperator\Autoloader::register( __DIR__ . '/../src/' );

$failures = 0;
$passes   = 0;

function check( string $name, callable $fn ): void {
	global $failures, $passes;
	try {
		$fn();
		$passes++;
		echo "  ok   {$name}\n";
	} catch ( Throwable $e ) {
		$failures++;
		echo "  FAIL {$name}: {$e->getMessage()}\n";
	}
}

function assert_true( bool $cond, string $msg = 'assertion failed' ): void {
	if ( ! $cond ) {
		throw new RuntimeException( $msg );
	}
}

function assert_throws( callable $fn, string $class ): void {
	try {
		$fn();
	} catch ( Throwable $e ) {
		if ( $e instanceof $class ) {
			return;
		}
		throw new RuntimeException( 'Expected ' . $class . ', got ' . get_class( $e ) );
	}
	throw new RuntimeException( 'Expected ' . $class . ', nothing thrown' );
}

foreach ( glob( __DIR__ . '/*Test.php' ) as $file ) {
	echo basename( $file ) . "\n";
	require $file;
}

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
