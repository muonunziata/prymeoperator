<?php
namespace PrymeOperator\Operator;

/**
 * The plugin's private working folder (trash, versions, upload chunks).
 * It is never listed in the explorer and is denied to web requests.
 */
final class Vault {

	private string $base;

	public function __construct( string $base ) {
		$this->base = rtrim( str_replace( '\\', '/', $base ), '/' );
	}

	public function base(): string {
		return $this->base;
	}

	public function dir( string $name ): string {
		$this->ensure();
		$dir = $this->base . '/' . $name;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		return $dir;
	}

	public function contains( string $absolute ): bool {
		$absolute = rtrim( str_replace( '\\', '/', $absolute ), '/' );
		$real     = realpath( $this->base );
		$bases    = array_unique( array_filter( [ $this->base, $real ? str_replace( '\\', '/', $real ) : '' ] ) );
		foreach ( $bases as $base ) {
			if ( $absolute === $base || str_starts_with( $absolute, $base . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	public function ensure(): void {
		if ( is_dir( $this->base ) && is_file( $this->base . '/.htaccess' ) ) {
			return;
		}
		if ( ! is_dir( $this->base ) ) {
			mkdir( $this->base, 0755, true );
		}
		file_put_contents( $this->base . '/.htaccess', "Require all denied\nDeny from all\n" );
		file_put_contents( $this->base . '/index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents( $this->base . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
	}
}
