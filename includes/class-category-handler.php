<?php

namespace Vikingbad;

defined( 'ABSPATH' ) || exit;

class Category_Handler {

	private $cache = [];
	private $map   = [];

	public function __construct() {
		$this->map = get_option( 'vikingbad_category_map', [] );
	}

	/**
	 * Resolve API categories to term IDs.
	 *
	 * Checks the saved category mapping first. If an API category is mapped
	 * to a WC category, uses that. Otherwise falls back to creating/finding
	 * by name with hierarchy.
	 *
	 * @param array $categories Array of category objects from the API.
	 * @return int[] Array of term IDs.
	 */
	public function resolve( array $categories ): array {
		$term_ids  = [];
		$parent_id = 0;

		// Sort by level to ensure parents are created before children.
		usort( $categories, function ( $a, $b ) {
			return ( $a['level'] ?? 1 ) - ( $b['level'] ?? 1 );
		} );

		foreach ( $categories as $category ) {
			$name = is_array( $category ) ? ( $category['name'] ?? '' ) : $category;
			$name = trim( $name );

			if ( empty( $name ) ) {
				continue;
			}

			// Check mapping first.
			if ( isset( $this->map[ $name ] ) ) {
				$mapped_id = (int) $this->map[ $name ];
				if ( $mapped_id > 0 && term_exists( $mapped_id, 'product_cat' ) ) {
					$term_ids[] = $mapped_id;
					$parent_id  = $mapped_id;
					continue;
				}
			}

			// Fallback: create/find by name with hierarchy.
			$level   = (int) ( $category['level'] ?? 1 );
			$slug    = $category['slug'] ?? '';
			$parent  = $level > 1 ? $parent_id : 0;
			$term_id = $this->get_or_create( $name, $slug, $parent );

			if ( $term_id ) {
				$term_ids[] = $term_id;
				$parent_id  = $term_id;
			}
		}

		return $term_ids;
	}

	private function get_or_create( string $name, string $slug, int $parent ): int {
		$cache_key = $name . '::' . $parent;

		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}

		// Query by both name AND parent to avoid duplicates.
		$existing = get_terms( [
			'taxonomy'   => 'product_cat',
			'name'       => $name,
			'parent'     => $parent,
			'hide_empty' => false,
			'number'     => 1,
		] );

		if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
			$term_id                   = $existing[0]->term_id;
			$this->cache[ $cache_key ] = $term_id;
			return $term_id;
		}

		$args = [ 'parent' => $parent ];
		if ( ! empty( $slug ) ) {
			$args['slug'] = $slug;
		}

		$result = wp_insert_term( $name, 'product_cat', $args );

		if ( is_wp_error( $result ) ) {
			if ( $result->get_error_code() === 'term_exists' ) {
				$existing_id               = (int) $result->get_error_data();
				$this->cache[ $cache_key ] = $existing_id;
				return $existing_id;
			}
			return 0;
		}

		$this->cache[ $cache_key ] = $result['term_id'];
		return $result['term_id'];
	}
}
