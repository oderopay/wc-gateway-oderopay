<?php
/**
 * Plugin Name: Wc Gateway Oderopay
 * Description: Receive payments in RON and EUR
 * Author: OderoPay Team
 * Author URI: http://github.com/oderopay
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * Version: 1.3.0
 * Requires at least: 6.8
 * Tested up to: 6.8.3
 * WC tested up to: 10.2.2
 * WC requires at least: 10.0.0
 * Requires PHP: 7.2
 */
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

defined( 'ABSPATH' ) || exit;

define( 'WC_GATEWAY_ODEROPAY_VERSION', '1.3.0' );
define( 'WC_GATEWAY_ODEROPAY_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'WC_GATEWAY_ODEROPAY_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );

/**
 * Initialize the gateway.
 * @since 1.0.0
 */
function woocommerce_oderopay_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once( plugin_basename( 'vendor/autoload.php' ) );
	require_once( plugin_basename( 'includes/class-wc-gateway-oderopay.php' ) );
	load_plugin_textdomain( 'wc-gateway-oderopay', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) );

	add_filter( 'woocommerce_payment_gateways', 'woocommerce_oderopay_add_gateway' );
}
add_action( 'plugins_loaded', 'woocommerce_oderopay_init', 0 );

function woocommerce_oderopay_plugin_links( $links ) {
	$settings_url = add_query_arg(
		array(
			'page' => 'wc-settings',
			'tab' => 'checkout',
			'section' => 'WC_Gateway_OderoPay',
		),
		admin_url( 'admin.php' )
	);

	$plugin_links = array(
		'<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'wc-gateway-oderopay' ) . '</a>',
		'<a href="https://developer.pay.odero.ro/">' . __( 'Docs', 'wc-gateway-oderopay' ) . '</a>',
	);

	return array_merge( $plugin_links, $links );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woocommerce_oderopay_plugin_links' );


/**
 * Add the gateway to WooCommerce
 * @since 1.0.0
 */
function woocommerce_oderopay_add_gateway( $methods ) {
	$methods[] = 'WC_Gateway_OderoPay';
	return $methods;
}


/**
 * Declare compatibility with WC features.
 *
 * @return void
 */
function woocommerce_oderopay_declare_hpos_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'woocommerce_oderopay_declare_hpos_compatibility' );


/**
 * TESTING
 * @return void
 */
function woocommerce_oderopay_woocommerce_blocks_support() {
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        require_once dirname(__FILE__) . '/includes/class-wc-gateway-oderopay-blocks-support.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new WC_OderoPay_Blocks_Support );
            }
        );
    }
}
add_action( 'woocommerce_blocks_loaded', 'woocommerce_oderopay_woocommerce_blocks_support' );
