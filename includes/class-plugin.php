<?php

namespace Vikingbad;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'check_woocommerce' ] );
		add_action( 'init', [ $this, 'init' ] );
	}

	public function check_woocommerce() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>';
				esc_html_e( 'Vikingbad Produktimport krever at WooCommerce er installert og aktivert.', 'vikingbad' );
				echo '</p></div>';
			} );
		}
	}

	public function add_documents_tab( array $tabs ): array {
		global $product;

		if ( ! $product ) {
			return $tabs;
		}

		$documents = $product->get_meta( '_vikingbad_documents' );

		if ( empty( $documents ) || ! is_array( $documents ) ) {
			return $tabs;
		}

		$tabs['vikingbad_documents'] = [
			'title'    => __( 'Dokumenter', 'vikingbad' ),
			'priority' => 50,
			'callback' => [ $this, 'render_documents_tab' ],
		];

		return $tabs;
	}

	public function render_documents_tab(): void {
		global $product;

		$documents = $product->get_meta( '_vikingbad_documents' );

		if ( empty( $documents ) || ! is_array( $documents ) ) {
			return;
		}

		echo '<style>.vikingbad-documents{display:flex;gap:10px;flex-wrap:wrap}.vikingbad-document-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#0073aa;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;transition:background .2s}.vikingbad-document-btn:hover{background:#005a87;color:#fff}</style>';
		echo '<div class="vikingbad-documents">';
		foreach ( $documents as $doc ) {
			$name = esc_html( $doc['name'] );
			$url  = esc_url( $doc['url'] );
			echo '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="vikingbad-document-btn">';
			echo $name . ' (PDF)';
			echo '</a> ';
		}
		echo '</div>';
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$settings = new Settings();
		$settings->register();

		$ajax = new Ajax_Handler();
		$ajax->register();

		// Add Documents tab on single product page.
		add_filter( 'woocommerce_product_tabs', [ $this, 'add_documents_tab' ] );
	}
}
