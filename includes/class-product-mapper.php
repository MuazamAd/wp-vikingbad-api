<?php

namespace Vikingbad;

defined( 'ABSPATH' ) || exit;

class Product_Mapper {

	const VARIATION_ATTRIBUTE = 'Utførelse';

	/**
	 * Map API data onto a simple WC_Product.
	 */
	public function map( array $data, \WC_Product $product ): \WC_Product {
		$this->map_shared_fields( $data, $product );
		$this->map_variant_fields( $data, $product );

		// Attributes — visible on product page, not for variations.
		if ( ! empty( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
			$product->set_attributes( $this->build_attributes( $data['attributes'] ) );
		}

		return $product;
	}

	/**
	 * Map shared fields onto a variable product parent.
	 */
	public function map_parent( array $data, \WC_Product_Variable $product, array $all_variant_descriptions ): \WC_Product_Variable {
		$this->map_shared_fields( $data, $product );

		// Build attributes: variation attribute + API attributes.
		$attributes = [];

		// Variation attribute (Utførelse) with all possible values.
		$var_attr = new \WC_Product_Attribute();
		$var_attr->set_name( self::VARIATION_ATTRIBUTE );
		$var_attr->set_options( array_map( 'sanitize_text_field', $all_variant_descriptions ) );
		$var_attr->set_visible( true );
		$var_attr->set_variation( true );
		$var_attr->set_position( 0 );
		$attributes[] = $var_attr;

		// Additional API attributes (e.g. "Produktserie": "Fay") — visible, not for variations.
		if ( ! empty( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
			$pos = 1;
			foreach ( $data['attributes'] as $name => $value ) {
				if ( empty( $name ) || ( empty( $value ) && $value !== '0' ) ) {
					continue;
				}
				$values  = is_array( $value ) ? $value : [ $value ];
				$wc_attr = new \WC_Product_Attribute();
				$wc_attr->set_name( sanitize_text_field( $name ) );
				$wc_attr->set_options( array_map( 'sanitize_text_field', $values ) );
				$wc_attr->set_visible( true );
				$wc_attr->set_variation( false );
				$wc_attr->set_position( $pos++ );
				$attributes[] = $wc_attr;
			}
		}

		$product->set_attributes( $attributes );

		return $product;
	}

	/**
	 * Map variant-specific fields onto a WC_Product_Variation.
	 */
	public function map_variation_product( array $data, \WC_Product_Variation $variation ): \WC_Product_Variation {
		$this->map_variant_fields( $data, $variation );

		// Set the variation attribute value (description = variant name like "Hvit matt/Skandinavisk eik").
		$variant_value = sanitize_text_field( $data['description'] ?? '' );
		$variation->set_attributes( [
			sanitize_title( self::VARIATION_ATTRIBUTE ) => $variant_value,
		] );

		// Variation description for display.
		if ( ! empty( $data['description'] ) ) {
			$variation->set_description( sanitize_text_field( $data['description'] ) );
		}

		return $variation;
	}

	/**
	 * Fields shared between parent and simple products.
	 */
	private function map_shared_fields( array $data, \WC_Product $product ): void {
		if ( ! empty( $data['name'] ) ) {
			$product->set_name( sanitize_text_field( $data['name'] ) );
		}

		if ( ! empty( $data['text'] ) ) {
			$product->set_short_description( wp_kses_post( $data['text'] ) );
		}

		if ( ! empty( $data['technical_info'] ) ) {
			$product->set_description( wp_kses_post( $data['technical_info'] ) );
		}

		if ( isset( $data['status'] ) ) {
			$product->set_status( $data['status'] === 'active' ? 'publish' : 'draft' );
		}

		// Documents.
		if ( ! empty( $data['documents'] ) && is_array( $data['documents'] ) ) {
			$documents = array_map( function ( $doc ) {
				return [
					'name' => sanitize_text_field( $doc['name'] ?? '' ),
					'url'  => esc_url_raw( $doc['url'] ?? '' ),
				];
			}, $data['documents'] );

			$documents = array_filter( $documents, function ( $doc ) {
				return ! empty( $doc['name'] ) && ! empty( $doc['url'] );
			} );

			$product->update_meta_data( '_vikingbad_documents', array_values( $documents ) );
		}
	}

	/**
	 * Fields specific to each variant (or to a simple product).
	 */
	private function map_variant_fields( array $data, \WC_Product $product ): void {
		if ( ! empty( $data['sku'] ) ) {
			$product->set_sku( sanitize_text_field( $data['sku'] ) );
		}

		$gross = $data['price']['gross'] ?? null;
		if ( is_numeric( $gross ) ) {
			$product->set_regular_price( (string) $gross );
		}

		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );

		if ( ! empty( $data['ean'] ) ) {
			$product->update_meta_data( '_ean', sanitize_text_field( $data['ean'] ) );
		}
		if ( ! empty( $data['nrf'] ) ) {
			$product->update_meta_data( '_nrf', sanitize_text_field( $data['nrf'] ) );
		}

		$expected_at = $data['stock']['expected_at'] ?? null;
		if ( ! empty( $expected_at ) ) {
			$product->update_meta_data( '_vikingbad_expected_at', sanitize_text_field( $expected_at ) );
		}

		$net = $data['price']['net'] ?? null;
		if ( is_numeric( $net ) ) {
			$product->update_meta_data( '_vikingbad_net_price', (string) $net );
		}
	}

	/**
	 * Build WC_Product_Attribute array from API attributes (for simple products).
	 */
	private function build_attributes( array $attributes ): array {
		$wc_attributes = [];
		$position      = 0;

		foreach ( $attributes as $name => $value ) {
			if ( empty( $name ) || ( empty( $value ) && $value !== '0' ) ) {
				continue;
			}

			$values  = is_array( $value ) ? $value : [ $value ];
			$wc_attr = new \WC_Product_Attribute();
			$wc_attr->set_name( sanitize_text_field( $name ) );
			$wc_attr->set_options( array_map( 'sanitize_text_field', $values ) );
			$wc_attr->set_visible( true );
			$wc_attr->set_variation( false );
			$wc_attr->set_position( $position++ );

			$wc_attributes[] = $wc_attr;
		}

		return $wc_attributes;
	}
}
