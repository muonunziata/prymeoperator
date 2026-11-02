<?php
namespace PrymeOperator\Library;

/**
 * Pure folder-tree rules, independent of WordPress so they can be unit tested.
 *
 * A row is ['id' => int, 'parent_id' => int, 'name' => string]; parent 0 is the top level.
 */
final class Tree {

	public const MAX_NAME = 190;

	/**
	 * Cleans a folder name. Returns '' when nothing usable is left.
	 */
	public static function clean_name( string $name ): string {
		$name = preg_replace( '/[\x00-\x1F\x7F]+/u', '', $name ) ?? '';
		$name = trim( preg_replace( '/\s+/u', ' ', strip_tags( $name ) ) ?? '' );
		return mb_substr( $name, 0, self::MAX_NAME );
	}

	/**
	 * IDs of every folder below $id (children, grandchildren…), never $id itself.
	 *
	 * @param array<int, array{id:int, parent_id:int, name:string}> $rows
	 * @return int[]
	 */
	public static function descendants( array $rows, int $id ): array {
		$children = [];
		foreach ( $rows as $row ) {
			$children[ (int) $row['parent_id'] ][] = (int) $row['id'];
		}
		$out   = [];
		$stack = $children[ $id ] ?? [];
		while ( $stack ) {
			$next = array_pop( $stack );
			if ( isset( $out[ $next ] ) ) {
				continue; // Corrupt data with a loop must not hang us.
			}
			$out[ $next ] = $next;
			foreach ( $children[ $next ] ?? [] as $child ) {
				$stack[] = $child;
			}
		}
		return array_values( $out );
	}

	/**
	 * A folder can go under any folder except itself and its own descendants.
	 *
	 * @param array<int, array{id:int, parent_id:int, name:string}> $rows
	 */
	public static function can_move( array $rows, int $id, int $new_parent ): bool {
		if ( $new_parent === 0 ) {
			return true;
		}
		if ( $new_parent === $id || ! self::exists( $rows, $new_parent ) ) {
			return false;
		}
		return ! in_array( $new_parent, self::descendants( $rows, $id ), true );
	}

	/**
	 * @param array<int, array{id:int, parent_id:int, name:string}> $rows
	 */
	public static function exists( array $rows, int $id ): bool {
		foreach ( $rows as $row ) {
			if ( (int) $row['id'] === $id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns $name, or "$name (2)", "$name (3)"… so that no sibling under
	 * $parent has the same name (case-insensitive). $except is the folder
	 * being renamed or moved, which may keep its own name.
	 *
	 * @param array<int, array{id:int, parent_id:int, name:string}> $rows
	 */
	public static function unique_name( array $rows, int $parent, string $name, int $except = 0 ): string {
		$taken = [];
		foreach ( $rows as $row ) {
			if ( (int) $row['parent_id'] === $parent && (int) $row['id'] !== $except ) {
				$taken[ mb_strtolower( (string) $row['name'] ) ] = true;
			}
		}
		if ( ! isset( $taken[ mb_strtolower( $name ) ] ) ) {
			return $name;
		}
		$base = preg_replace( '/ \(\d+\)$/u', '', $name ) ?? $name;
		for ( $i = 2; ; $i++ ) {
			$candidate = mb_substr( $base, 0, self::MAX_NAME - 8 ) . " ({$i})";
			if ( ! isset( $taken[ mb_strtolower( $candidate ) ] ) ) {
				return $candidate;
			}
		}
	}

	/**
	 * Path of names from the top level down to $id, e.g. ["Fotos", "2024"].
	 *
	 * @param array<int, array{id:int, parent_id:int, name:string}> $rows
	 * @return string[]
	 */
	public static function path( array $rows, int $id ): array {
		$by_id = [];
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}
		$out  = [];
		$seen = [];
		while ( $id && isset( $by_id[ $id ] ) && ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			array_unshift( $out, (string) $by_id[ $id ]['name'] );
			$id = (int) $by_id[ $id ]['parent_id'];
		}
		return $out;
	}
}
