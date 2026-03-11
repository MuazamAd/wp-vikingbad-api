<?php

namespace Vikingbad;

defined( 'ABSPATH' ) || exit;

class Image_Handler {

	private $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Import images for a product (simple or variable parent).
	 * First image = featured, rest = gallery.
	 */
	public function import( array $image_urls, int $product_id ): void {
		$attachment_ids = $this->resolve_images( $image_urls, $product_id );

		if ( empty( $attachment_ids ) ) {
			return;
		}

		set_post_thumbnail( $product_id, $attachment_ids[0] );

		$gallery_ids = array_slice( $attachment_ids, 1 );
		if ( ! empty( $gallery_ids ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$product->set_gallery_image_ids( $gallery_ids );
				$product->save();
			}
		}
	}

	/**
	 * Import a single image for a variation.
	 * WC variations only support one image.
	 */
	public function import_variation_image( string $image_url, int $variation_id ): void {
		if ( empty( $image_url ) ) {
			return;
		}

		$attachment_ids = $this->resolve_images( [ $image_url ], $variation_id );

		if ( ! empty( $attachment_ids ) ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation && $variation->is_type( 'variation' ) ) {
				$variation->set_image_id( $attachment_ids[0] );
				$variation->save();
			}
		}
	}

	/**
	 * Resolve image URLs to attachment IDs (sideload if needed).
	 *
	 * @return int[]
	 */
	private function resolve_images( array $image_urls, int $parent_id ): array {
		if ( empty( $image_urls ) ) {
			return [];
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_ids = [];

		foreach ( $image_urls as $url ) {
			$url = is_array( $url ) ? ( $url['url'] ?? $url['src'] ?? '' ) : $url;
			$url = trim( $url );

			if ( empty( $url ) ) {
				continue;
			}

			$attachment_id = $this->get_existing_attachment( $url );

			if ( ! $attachment_id ) {
				$attachment_id = $this->sideload( $url, $parent_id );
			}

			if ( $attachment_id ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		return $attachment_ids;
	}

	private function get_existing_attachment( string $url ): int {
		global $wpdb;

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_vikingbad_source_url' AND meta_value = %s LIMIT 1",
				$url
			)
		);

		return (int) $attachment_id;
	}

	private function sideload( string $url, int $parent_id ): int {
		$tmp = download_url( $url, 15 );

		if ( is_wp_error( $tmp ) ) {
			$this->logger->error( "Failed to download image: {$url} — " . $tmp->get_error_message() );
			return 0;
		}

		$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );

		$file_array = [
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, $parent_id );

		if ( is_wp_error( $attachment_id ) ) {
			$this->logger->error( "Failed to sideload image: {$url} — " . $attachment_id->get_error_message() );
			@unlink( $tmp );
			return 0;
		}

		update_post_meta( $attachment_id, '_vikingbad_source_url', $url );

		return $attachment_id;
	}
}
