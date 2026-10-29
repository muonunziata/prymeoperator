<?php
namespace PrymeOperator\Operator;

/**
 * Append-only audit trail: who did what, to which target, when and from where.
 */
final class Audit {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'prymeoperator_audit';
	}

	public static function log( string $action, string $target, array $meta = [] ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'user_id'    => get_current_user_id(),
				'action'     => substr( $action, 0, 64 ),
				'target'     => $target,
				'meta'       => $meta ? wp_json_encode( $meta ) : null,
				// Only REMOTE_ADDR: forwarded headers are client-controlled.
				'ip'         => substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 45 ),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( int $per_page = 50, int $page = 1, string $action = '' ): array {
		global $wpdb;
		$table    = self::table();
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		$where = '1=1';
		$args  = [];
		if ( '' !== $action ) {
			$where .= ' AND action = %s';
			$args[] = $action;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, [ $per_page, $offset ] ) ),
			ARRAY_A
		);
		// phpcs:enable

		$items = array_map(
			static function ( array $row ): array {
				$user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
				return [
					'id'         => (int) $row['id'],
					'user'       => $user ? $user->display_name : '',
					'action'     => $row['action'],
					'target'     => $row['target'],
					'meta'       => $row['meta'] ? json_decode( $row['meta'], true ) : null,
					'ip'         => $row['ip'],
					'created_at' => mysql_to_rfc3339( $row['created_at'] ),
				];
			},
			$rows ?: []
		);

		return [ 'items' => $items, 'total' => $total ];
	}
}
