<?php

namespace Vikingbad;

defined( 'ABSPATH' ) || exit;

class Product_Importer {

	private $mapper;
	private $category_handler;
	private $image_handler;
	private $api_client;
	private $logger;

	private $created = 0;
	private $updated = 0;
	private $failed  = 0;
	private $skipped = 0;
	private $errors  = [];
	private $skipped_products = [];

	public function __construct( Logger $logger, API_Client $api_client ) {
		$this->mapper           = new Product_Mapper();
		$this->category_handler = new Category_Handler();
		$this->image_handler    = new Image_Handler( $logger );
		$this->api_client       = $api_client;
		$this->logger           = $logger;
	}

	/**
	 * Import products from a list endpoint page.
	 * Fetches full details per product, groups by name, and creates
	 * variable products when multiple variants share the same name.
	 */
	public function import( array $products ): array {
		$this->created = 0;
		$this->updated = 0;
		$this->failed  = 0;
		$this->skipped = 0;
		$this->errors  = [];
		$this->skipped_products = [];

		// 1. Fetch full details for each product.
		$full_products = [];
		foreach ( $products as $summary ) {
			$ean = $summary['ean'] ?? '';
			if ( empty( $ean ) ) {
				$sku  = $summary['sku'] ?? 'unknown';
				$name = $summary['name'] ?? 'unknown';
				$desc = $summary['description'] ?? '';
				$this->add_skipped( $sku, $name, $desc, 'No EAN' );
				continue;
			}

			$full = $this->api_client->get_product( $ean );
			if ( is_wp_error( $full ) ) {
				$this->add_error( "Failed to fetch EAN {$ean}: " . $full->get_error_message() );
				continue;
			}

			$full_products[] = $full;

			// Collect discovered categories for the settings page mapping.
			$this->collect_api_categories( $full );
		}

		// 2. Group by name.
		$groups = [];
		foreach ( $full_products as $p ) {
			$name = $p['name'] ?? '';
			if ( empty( $name ) ) {
				$this->add_error( 'Skipped product with no name.' );
				continue;
			}
			$groups[ $name ][] = $p;
		}

		// 3. Process each group.
		foreach ( $groups as $name => $group ) {
			$this->process_group( $name, $group );
		}

		return [
			'created'          => $this->created,
			'updated'          => $this->updated,
			'failed'           => $this->failed,
			'skipped'          => $this->skipped,
			'errors'           => $this->errors,
			'skipped_products' => $this->skipped_products,
		];
	}

	/**
	 * Process a group of products that share the same name.
	 */
	private function process_group( string $name, array $products ): void {
		try {
			// Check if a WC product already exists for this group.
			$existing = $this->find_group_product( $name );

			if ( count( $products ) === 1 && ! $existing ) {
				// Single product, no existing group — create as simple.
				// If a future page has another variant with the same name,
				// it will convert this to variable.
				$this->import_simple( $products[0] );
				return;
			}

			if ( $existing && $existing->is_type( 'variable' ) ) {
				// Variable product exists — update parent and add/update variations.
				$this->update_variable_parent( $existing, $products );
				foreach ( $products as $data ) {
					$this->import_variation( $existing, $data );
				}
				return;
			}

			if ( $existing && $existing->is_type( 'simple' ) ) {
				// Existing simple product — convert to variable.
				$variable = $this->convert_simple_to_variable( $existing );
				if ( $variable ) {
					$this->update_variable_parent( $variable, $products );
					foreach ( $products as $data ) {
						$this->import_variation( $variable, $data );
					}
				}
				return;
			}

			// Multiple products in this group, no existing — create variable.
			if ( count( $products ) > 1 ) {
				$variable = $this->create_variable_product( $name, $products );
				if ( $variable ) {
					foreach ( $products as $data ) {
						$this->import_variation( $variable, $data );
					}
				}
				return;
			}

			// Fallback: single product.
			$this->import_simple( $products[0] );

		} catch ( \Exception $e ) {
			$this->add_error( "Error processing group '{$name}': " . $e->getMessage() );
		}
	}

	/**
	 * Find an existing WC product (variable or simple) by group name meta.
	 */
	private function find_group_product( string $name ): ?\WC_Product {
		$posts = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'meta_key'       => '_vikingbad_group_name',
			'meta_value'     => $name,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );

		if ( empty( $posts ) ) {
			return null;
		}

		return wc_get_product( $posts[0] ) ?: null;
	}

	/**
	 * Find an existing WC product by name (post_title).
	 * Used as a fallback when no SKU match is found,
	 * e.g. for manually-added products without SKUs.
	 */
	private function find_product_by_name( string $name ): int {
		if ( empty( $name ) ) {
			return 0;
		}

		global $wpdb;

		$product_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'product'
				   AND post_status IN ('publish','draft','pending','private')
				   AND post_title = %s
				 LIMIT 1",
				$name
			)
		);

		return (int) $product_id;
	}

	/**
	 * Import a single product as WC_Product_Simple.
	 */
	private function import_simple( array $data ): void {
		$sku = $data['sku'] ?? '';
		if ( empty( $sku ) ) {
			$this->add_error( 'Skipped product: no SKU.' );
			return;
		}

		$existing_id = wc_get_product_id_by_sku( $sku );

		// Fallback: match by product name if no SKU match.
		if ( ! $existing_id ) {
			$existing_id = $this->find_product_by_name( $data['name'] ?? '' );
			if ( $existing_id ) {
				$this->logger->info( "Matched SKU {$sku} to existing product by name (ID: {$existing_id})" );
			}
		}

		$is_update = $existing_id > 0;
		$product   = $is_update ? wc_get_product( $existing_id ) : new \WC_Product_Simple();

		if ( ! $product ) {
			$this->add_error( "Could not load product for SKU: {$sku}" );
			return;
		}

		// If existing product is a variation, it belongs to a variable — skip simple import.
		if ( $product->is_type( 'variation' ) ) {
			$this->logger->info( "SKU {$sku} is already a variation, skipping simple import." );
			return;
		}

		$product = $this->mapper->map( $data, $product );
		$product->update_meta_data( '_vikingbad_group_name', $data['name'] ?? '' );
		$product->update_meta_data( '_vikingbad_variant_description', sanitize_text_field( $data['description'] ?? '' ) );

		// Categories (merge — preserves manually added categories).
		$this->sync_categories( $product, $data['categories'] ?? [] );

		$product_id = $product->save();

		if ( ! $product_id ) {
			$this->add_error( "Failed to save simple product SKU: {$sku}" );
			return;
		}

		// Images.
		$image_urls = $this->extract_image_urls( $data );
		if ( ! empty( $image_urls ) ) {
			$this->image_handler->import( $image_urls, $product_id );
		}

		if ( $is_update ) {
			$this->updated++;
			$this->logger->info( "Updated simple product SKU: {$sku} (ID: {$product_id})" );
		} else {
			$this->created++;
			$this->logger->info( "Created simple product SKU: {$sku} (ID: {$product_id})" );
		}
	}

	/**
	 * Create a new variable product parent.
	 */
	private function create_variable_product( string $name, array $products ): ?\WC_Product_Variable {
		$variable = new \WC_Product_Variable();

		$descriptions = $this->collect_variant_descriptions( $products );
		$variable     = $this->mapper->map_parent( $products[0], $variable, $descriptions );
		$variable->update_meta_data( '_vikingbad_group_name', $name );

		// Categories (merge — preserves manually added categories).
		$this->sync_categories( $variable, $products[0]['categories'] ?? [] );

		$variable_id = $variable->save();

		if ( ! $variable_id ) {
			$this->add_error( "Failed to create variable product: {$name}" );
			return null;
		}

		$this->created++;
		$this->logger->info( "Created variable product: {$name} (ID: {$variable_id})" );

		return wc_get_product( $variable_id );
	}

	/**
	 * Update an existing variable product parent with shared data and attribute options.
	 */
	private function update_variable_parent( \WC_Product_Variable $variable, array $products ): void {
		// Collect all variant descriptions (existing + new).
		$existing_attr = $variable->get_attribute( Product_Mapper::VARIATION_ATTRIBUTE );
		$existing_opts = ! empty( $existing_attr ) ? array_map( 'trim', explode( '|', $existing_attr ) ) : [];

		$new_descriptions = $this->collect_variant_descriptions( $products );
		$all_descriptions = array_unique( array_merge( $existing_opts, $new_descriptions ) );

		$variable = $this->mapper->map_parent( $products[0], $variable, $all_descriptions );
		$variable->update_meta_data( '_vikingbad_group_name', $products[0]['name'] ?? '' );

		// Categories (merge — preserves manually added categories).
		$this->sync_categories( $variable, $products[0]['categories'] ?? [] );

		$variable->save();
	}

	/**
	 * Import or update a single variation under a variable product.
	 */
	private function import_variation( \WC_Product_Variable $parent, array $data ): void {
		$sku = $data['sku'] ?? '';
		if ( empty( $sku ) ) {
			$this->add_error( 'Skipped variation: no SKU.' );
			return;
		}

		$existing_id = wc_get_product_id_by_sku( $sku );
		$is_update   = $existing_id > 0;

		if ( $is_update ) {
			$variation = wc_get_product( $existing_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
				// SKU exists but isn't a variation — it's a leftover simple product.
				// This shouldn't normally happen after conversion, but handle gracefully.
				$this->logger->warning( "SKU {$sku} exists but isn't a variation. Skipping." );
				return;
			}
		} else {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
		}

		$variation = $this->mapper->map_variation_product( $data, $variation );
		$variation->set_status( 'publish' );

		$variation_id = $variation->save();

		if ( ! $variation_id ) {
			$this->add_error( "Failed to save variation SKU: {$sku}" );
			return;
		}

		// Variation image (first image only).
		$image_urls = $this->extract_image_urls( $data );
		if ( ! empty( $image_urls ) ) {
			$this->image_handler->import_variation_image( $image_urls[0], $variation_id );
		}

		if ( $is_update ) {
			$this->updated++;
			$this->logger->info( "Updated variation SKU: {$sku} (ID: {$variation_id})" );
		} else {
			$this->created++;
			$this->logger->info( "Created variation SKU: {$sku} (ID: {$variation_id})" );
		}
	}

	/**
	 * Convert an existing simple product to a variable product.
	 * The old simple's data becomes the first variation.
	 */
	private function convert_simple_to_variable( \WC_Product $simple ): ?\WC_Product_Variable {
		$product_id = $simple->get_id();
		$old_sku    = $simple->get_sku();

		$this->logger->info( "Converting simple product '{$simple->get_name()}' (ID: {$product_id}) to variable." );

		// Save the old simple product's variant-specific data before conversion.
		$old_variant_data = [
			'sku'         => $old_sku,
			'price'       => $simple->get_regular_price(),
			'description' => $simple->get_meta( '_vikingbad_variant_description' ) ?: $old_sku,
			'ean'         => $simple->get_meta( '_ean' ),
			'nrf'         => $simple->get_meta( '_nrf' ),
			'net_price'   => $simple->get_meta( '_vikingbad_net_price' ),
			'expected_at' => $simple->get_meta( '_vikingbad_expected_at' ),
			'image_id'    => $simple->get_image_id(),
		];

		// Clear SKU from the product before type change (to avoid duplicate SKU error).
		$simple->set_sku( '' );
		$simple->save();

		// Change product type to variable.
		wp_set_object_terms( $product_id, 'variable', 'product_type' );
		wc_delete_product_transients( $product_id );

		// Clear variant-specific data from the parent.
		delete_post_meta( $product_id, '_regular_price' );
		delete_post_meta( $product_id, '_price' );

		// Reload as variable product.
		$variable = wc_get_product( $product_id );
		if ( ! $variable || ! $variable->is_type( 'variable' ) ) {
			$this->add_error( "Failed to convert product ID {$product_id} to variable." );
			return null;
		}

		// Create a variation from the old simple product's data.
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_sku( $old_variant_data['sku'] );
		$variation->set_regular_price( $old_variant_data['price'] );
		$variation->set_status( 'publish' );
		$variation->set_manage_stock( false );
		$variation->set_stock_status( 'instock' );

		$variation->set_attributes( [
			sanitize_title( Product_Mapper::VARIATION_ATTRIBUTE ) => $old_variant_data['description'],
		] );

		if ( ! empty( $old_variant_data['ean'] ) ) {
			$variation->update_meta_data( '_ean', $old_variant_data['ean'] );
		}
		if ( ! empty( $old_variant_data['nrf'] ) ) {
			$variation->update_meta_data( '_nrf', $old_variant_data['nrf'] );
		}
		if ( ! empty( $old_variant_data['net_price'] ) ) {
			$variation->update_meta_data( '_vikingbad_net_price', $old_variant_data['net_price'] );
		}
		if ( ! empty( $old_variant_data['expected_at'] ) ) {
			$variation->update_meta_data( '_vikingbad_expected_at', $old_variant_data['expected_at'] );
		}

		if ( ! empty( $old_variant_data['image_id'] ) ) {
			$variation->set_image_id( $old_variant_data['image_id'] );
		}

		$variation->save();
		$this->logger->info( "Created variation from old simple — SKU: {$old_variant_data['sku']}" );

		return $variable;
	}

	/**
	 * Collect variant description values from a group of products.
	 */
	private function collect_variant_descriptions( array $products ): array {
		$descriptions = [];
		foreach ( $products as $p ) {
			$desc = sanitize_text_field( $p['description'] ?? '' );
			if ( ! empty( $desc ) ) {
				$descriptions[] = $desc;
			}
		}
		return array_unique( $descriptions );
	}

	/**
	 * Extract large image URLs from API product data.
	 */
	private function extract_image_urls( array $data ): array {
		$urls = [];
		foreach ( $data['images'] ?? [] as $image ) {
			$url = $image['large'] ?? $image['medium'] ?? $image['small'] ?? '';
			if ( ! empty( $url ) ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * Collect unique categories from a product and merge into the saved option.
	 */
	private function collect_api_categories( array $product_data ): void {
		$existing = get_option( 'vikingbad_api_categories', [] );

		// Index existing by name for quick lookup.
		$by_name = [];
		foreach ( $existing as $cat ) {
			$by_name[ $cat['name'] ] = $cat;
		}

		$changed = false;
		foreach ( $product_data['categories'] ?? [] as $cat ) {
			$name = $cat['name'] ?? '';
			if ( ! empty( $name ) && ! isset( $by_name[ $name ] ) ) {
				$by_name[ $name ] = [
					'name'  => $name,
					'slug'  => $cat['slug'] ?? '',
					'level' => $cat['level'] ?? 1,
				];
				$changed = true;
			}
		}

		if ( $changed ) {
			// Sort by level then name.
			$all = array_values( $by_name );
			usort( $all, function ( $a, $b ) {
				if ( $a['level'] !== $b['level'] ) {
					return $a['level'] - $b['level'];
				}
				return strcmp( $a['name'], $b['name'] );
			} );
			update_option( 'vikingbad_api_categories', $all );
		}
	}

	/**
	 * Sync API categories onto a product while preserving manually added ones.
	 *
	 * Stores API-assigned term IDs in _vikingbad_category_ids meta.
	 * On update: removes old API categories no longer in the API response,
	 * adds new API categories, and leaves manual categories untouched.
	 */
	private function sync_categories( \WC_Product $product, array $api_categories ): void {
		if ( empty( $api_categories ) ) {
			return;
		}

		$new_api_term_ids = $this->category_handler->resolve( $api_categories );
		if ( empty( $new_api_term_ids ) ) {
			return;
		}

		$old_api_term_ids = $product->get_meta( '_vikingbad_category_ids' );
		$old_api_term_ids = is_array( $old_api_term_ids ) ? array_map( 'intval', $old_api_term_ids ) : [];

		$current_term_ids = $product->get_category_ids();

		// Manual categories = current minus old API ones.
		$manual_term_ids = array_diff( $current_term_ids, $old_api_term_ids );

		// Merged = manual + new API categories.
		$merged = array_unique( array_merge( $manual_term_ids, $new_api_term_ids ) );
		$merged = array_values( array_map( 'intval', $merged ) );

		$product->set_category_ids( $merged );
		$product->update_meta_data( '_vikingbad_category_ids', $new_api_term_ids );
	}

	private function add_error( string $message ): void {
		$this->failed++;
		$this->errors[] = $message;
		$this->logger->error( $message );
	}

	private function add_skipped( string $sku, string $name, string $description, string $reason ): void {
		$this->skipped++;
		$this->skipped_products[] = [
			'sku'         => $sku,
			'name'        => $name,
			'description' => $description,
			'reason'      => $reason,
		];
		$this->logger->warning( "Skipped: SKU {$sku} — {$name} ({$reason})" );
	}
}
