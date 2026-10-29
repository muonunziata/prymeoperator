<?php
namespace PrymeOperator\Core;

use PrymeOperator\Operator\Trash;
use PrymeOperator\Operator\Vault;
use PrymeOperator\Operator\Versions;
use PrymeOperator\Security\Guard;
use PrymeOperator\Server\Explorer;
use PrymeOperator\Server\Operations;

/**
 * Builds the filesystem services for the current user.
 */
final class Services {

	public static function vault(): Vault {
		return new Vault( (string) apply_filters( 'prymeoperator_vault_dir', WP_CONTENT_DIR . '/prymeoperator-private' ) );
	}

	public static function trash(): Trash {
		return new Trash( self::vault() );
	}

	public static function explorer(): Explorer {
		return new Explorer( Guard::jail(), self::vault(), Guard::policy()->show_hidden() );
	}

	public static function operations(): Operations {
		$vault = self::vault();
		return new Operations(
			Guard::jail(),
			Guard::policy(),
			$vault,
			new Trash( $vault ),
			new Versions( $vault, (int) Settings::get( 'versions_keep' ) ),
			get_current_user_id()
		);
	}
}
