<?php
namespace PrymeOperator\Library;

/**
 * A folder operation that cannot be done. Messages are English source strings
 * translated at the REST boundary; the code is the HTTP status.
 */
final class LibraryException extends \RuntimeException {

	public function __construct( string $message, int $code = 400 ) {
		parent::__construct( $message, $code );
	}
}
