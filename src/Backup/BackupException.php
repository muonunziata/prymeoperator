<?php
namespace PrymeOperator\Backup;

/**
 * A backup or restore step that cannot be done. Messages are English source
 * strings translated at the REST boundary; the code is the HTTP status.
 */
final class BackupException extends \RuntimeException {

	public function __construct( string $message, int $code = 400 ) {
		parent::__construct( $message, $code );
	}
}
