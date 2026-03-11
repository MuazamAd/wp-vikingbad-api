<?php
/**
 * Plugin Name: Vikingbad Produktimport
 * Description: Importer produkter fra Vikingbad API til WooCommerce.
 * Version: 1.0.0
 * Author: Muazam Ali Ashraf — Adseo
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: vikingbad
 */

defined( 'ABSPATH' ) || exit;

define( 'VIKINGBAD_VERSION', '1.0.0' );
define( 'VIKINGBAD_PLUGIN_FILE', __FILE__ );
define( 'VIKINGBAD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIKINGBAD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VIKINGBAD_API_BASE', 'https://api.vikingbad.no/v1' );

require_once VIKINGBAD_PLUGIN_DIR . 'includes/class-encryption.php';
require_once VIKINGBAD_PLUGIN_DIR . 'includes/class-logger.php';
require_once VIKINGBAD_PLUGIN_DIR . 'includes/class-settings.php';
require_once VIKINGBAD_PLUGIN_DIR . 'includes/class-api-client.php';
require_once VIKINGBAD_PLUGIN_DIR . 'includes/class-category-handler.php';
require_once VIKINGBAD_PLUGIN_DIR . 'includes/class-image-handler.php';
require_once VIKINGBAD_PLUGIN_DIR . 'includes/class-product-mapper.php';
require_once VIKINGBAD_PLUGIN_DIR . 'includes/class-product-importer.php';
require_once VIKINGBAD_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once VIKINGBAD_PLUGIN_DIR . 'includes/class-plugin.php';

Vikingbad\Plugin::instance();
