<?php
namespace PrymeOperator\Security;

/**
 * What the current user may do: which operations, which file types and how
 * large an upload. Built once per request by Guard, plain PHP so it is tested
 * without WordPress.
 */
final class Policy {

	/**
	 * @param string[] $permissions     Allowed operations (see Settings::PERMISSIONS).
	 * @param string[] $blocked         Blocked extensions or full file names.
	 * @param bool     $bypass_blocked  Administrators may handle blocked types.
	 * @param int      $max_upload      Bytes; 0 means no plugin limit.
	 */
	public function __construct(
		private array $permissions,
		private array $blocked,
		private bool $bypass_blocked = false,
		private int $max_upload = 0,
		private bool $is_admin = false,
		private bool $show_hidden = false,
		private array $denied = [],
	) {}

	/**
	 * $denied wins over everything, administrators included (used for
	 * DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT).
	 */
	public function allows( string $permission ): bool {
		if ( in_array( $permission, $this->denied, true ) ) {
			return false;
		}
		return $this->is_admin || in_array( $permission, $this->permissions, true );
	}

	public function is_admin(): bool {
		return $this->is_admin;
	}

	public function show_hidden(): bool {
		return $this->show_hidden;
	}

	public function max_upload(): int {
		return $this->max_upload;
	}

	/**
	 * Every dotted part counts, so "shell.php.jpg" is blocked when "php" is:
	 * some servers execute files by any extension in the name.
	 */
	public function is_blocked( string $name ): bool {
		if ( $this->bypass_blocked ) {
			return false;
		}
		$lower = strtolower( basename( str_replace( '\\', '/', $name ) ) );
		foreach ( $this->blocked as $blocked ) {
			if ( $lower === $blocked || str_ends_with( $lower, '.' . $blocked ) ) {
				return true;
			}
		}
		$parts = explode( '.', ltrim( $lower, '.' ) );
		array_shift( $parts );
		return [] !== array_intersect( $parts, $this->blocked );
	}

	/**
	 * Text files that can change how the site runs (code, server config).
	 */
	public static function is_code( string $name ): bool {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		return in_array( $ext, [ 'php', 'phtml', 'inc', 'js', 'htaccess', 'ini' ], true ) || '.htaccess' === strtolower( basename( $name ) );
	}
}
