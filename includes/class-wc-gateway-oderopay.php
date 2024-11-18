<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Oderopay\OderoConfig;

/**
 * OderoPay Payment Gateway
 *
 * Provides a OderoPay Payment Gateway.
 *
 * @class  woocommerce_oderopay
 * @package WooCommerce
 * @category Payment Gateways
 * @author WooCommerce
 */


class WC_Gateway_OderoPay extends WC_Payment_Gateway
{

    CONST ODERO_PAYMENT_KEY = 'odero_payment_id';

	/**
	 * Version
	 *
	 * @var string
	 */
	public $version;


    private $available_currencies;
    private $merchant_id;
    private $merchant_name;
    private $merchant_token;

    /**
     * @var \Oderopay\OderoClient
     */
    public $odero;
    /**
     * @var bool
     */
    public $send_debug_email;
    /**
     * @var bool
     */
    public $enable_logging;
    /**
     * @var string
     */
    public $response_url;

    /**
     * @var string
     */
    public $secret_key;
    /**
     * @var string
     */
    private $merchant_id_sandbox;
    /**
     * @var string
     */
    private $merchant_token_sandbox;
    /**
     * @var bool
     */
    private $sandbox;

    private $debug_email = null;

    /**
	 * Constructor
	 */
	public function __construct()
    {
		$this->version = WC_GATEWAY_ODEROPAY_VERSION;
		$this->id = 'oderopay';
		$this->method_title       = __( 'OderoPay', 'wc-gateway-oderopay' );
		/* translators: 1: a href link 2: closing href */
		$this->method_description = __( 'OderoPay works by sending the user to OderoPay to enter their payment information.', 'wc-gateway-oderopay' );
		$this->icon               = WP_PLUGIN_URL . '/' . plugin_basename( dirname( dirname( __FILE__ ) ) ) . '/assets/images/icon.png';
		$this->debug_email        = get_option( 'admin_email' );
		$this->available_currencies = (array) apply_filters('woocommerce_gateway_oderopay_available_currencies', array( 'RON', 'EUR' ) );

		// Supported functionality
		$this->supports = array(
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
		);

		$this->init_form_fields();
		$this->init_settings();

		if ( ! is_admin() ) {
			$this->setup_constants();
		}

		// Setup default merchant data.
		$this->sandbox                  = 'yes' === $this->get_option( 'sandbox' );
		$this->merchant_name            = $this->get_option( 'merchant_name' );
		$this->merchant_id              = $this->get_option( 'merchant_id' );
		$this->merchant_token           = $this->get_option( 'merchant_token' );
		$this->merchant_id_sandbox      = $this->get_option( 'merchant_id_sandbox' );
		$this->merchant_token_sandbox   = $this->get_option( 'merchant_token_sandbox' );
		$this->title                    = $this->get_option( 'title' );
        $this->description              = $this->get_option( 'description' );
        $this->enabled                  = $this->get_option( 'enabled' );
		$this->secret_key               = $this->get_option( 'secret_key', wc_rand_hash());
        $this->enable_logging           = (bool) $this->get_option( 'enable_logging' );

		add_action( 'woocommerce_api_oderopay', array( $this, 'webhook' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_oderopay', array( $this, 'receipt_page' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );


        #add route for check odero payment
		add_action( 'rest_api_init', function() {
			register_rest_route(
				'wc',
				'/odero/(?P<id>[a-zA-Z0-9-]+)/verify/',
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [$this, 'verify_odero_payment'],
					'permission_callback' => function(){
						return true;
					}
				]
			);
		});

        $merchantId     = !$this->sandbox ? $this->merchant_id : $this->merchant_id_sandbox;
        $merchantToken  = !$this->sandbox ? $this->merchant_token : $this->merchant_token_sandbox;

        //Configure SDK
        $config = new OderoConfig($this->merchant_name ?? get_bloginfo( 'name' ), $merchantId, $merchantToken, $this->sandbox  ? OderoConfig::ENV_STG : OderoConfig::ENV_PROD);
        $odero = new \Oderopay\OderoClient($config);

        $this->odero = $odero;

	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @since 1.0.0
	 */
	public function init_form_fields()
    {
        $webhookUrl = get_site_url();

        $statuses = wc_get_order_statuses();

		$this->form_fields = array(
			'enabled' => array(
				'title'       => esc_attr__( 'Enable/Disable', 'wc-gateway-oderopay' ),
				'label'       => esc_attr__( 'Enable OderoPay', 'wc-gateway-oderopay' ),
				'type'        => 'checkbox',
				'description' => esc_attr__( 'This controls whether or not this gateway is enabled within WooCommerce.', 'wc-gateway-oderopay' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
            'title' => array(
                'title'       => esc_attr__( 'Title', 'wc-gateway-oderopay' ),
                'type'        => 'text',
                'description' => esc_attr__( 'This controls the title which the user sees during checkout.', 'wc-gateway-oderopay' ),
                'default'     => esc_attr__( 'OderoPay', 'wc-gateway-oderopay' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => esc_attr__( 'Description', 'wc-gateway-oderopay' ),
                'type'        => 'text',
                'description' => esc_attr__( 'This controls the description which the user sees during checkout.', 'wc-gateway-oderopay' ),
                'default'     => '',
                'desc_tip'    => true,
            ),

			'merchant_name' => array(
				'title'       => esc_attr__( 'Merchant Name', 'wc-gateway-oderopay' ),
				'type'        => 'text',
				'description' => esc_attr__( 'This is the merchant name, mostly your default store name.', 'wc-gateway-oderopay' ),
				'default'     => get_bloginfo( 'name' ),
			),

            'merchant_id' => array(
				'title'       => esc_attr__( 'Merchant ID', 'wc-gateway-oderopay' ),
				'type'        => 'text',
				'description' => esc_attr__( 'This is the merchant ID, received from OderoPay.', 'wc-gateway-oderopay' ),
				'default'     => '',
			),

			'merchant_token' => array(
				'title'       => esc_attr__( 'Merchant Token', 'wc-gateway-oderopay' ),
				'type'        => 'text',
				'description' => esc_attr__( 'This is the merchant token, received from OderoPay.', 'wc-gateway-oderopay' ),
				'default'     => '',
			),

            'sandbox' => array(
                'title'       => esc_attr__( 'OderoPay Sandbox', 'wc-gateway-oderopay' ),
                'type'        => 'checkbox',
                'description' => esc_attr__( 'Place the payment gateway in development mode.', 'wc-gateway-oderopay' ),
                'default'     => true,
            ),

            'merchant_id_sandbox' => array(
				'title'       => esc_attr__( 'Merchant ID (Sandbox)', 'wc-gateway-oderopay' ),
				'type'        => 'text',
				'description' => esc_attr__( 'This is the merchant ID (sandbox), received from OderoPay.', 'wc-gateway-oderopay' ),
				'default'     => '',
			),

			'merchant_token_sandbox' => array(
				'title'       => esc_attr__( 'Merchant Token (sandbox)', 'wc-gateway-oderopay' ),
				'type'        => 'text',
				'description' => esc_attr__( 'This is the merchant token (sandbox), received from OderoPay.', 'wc-gateway-oderopay' ),
				'default'     => '',
			),

            'status_settings' => array(
                'title'       => esc_attr__( 'Order Status Settings', 'wc-gateway-oderopay' ),
                'type'        => 'title',
                'description' => esc_attr__( 'Please Set the default order status', 'wc-gateway-oderopay' ),
            ),

            'status_on_process' => array(
                'title'       => esc_attr__( 'Status On Process', 'wc-gateway-oderopay' ),
                'type'        => 'select',
                'options'      => $statuses,
                'default'      => $this->get_option('status_on_process') ?? 'wc-on-hold',
                'description' => esc_attr__( 'This status is set when customer is redirected to Odero Payment Page', 'wc-gateway-oderopay' ),
            ),

            'status_on_failed' => array(
                'title'       => esc_attr__( 'On Payment Failed', 'wc-gateway-oderopay' ),
                'type'        => 'select',
                'options'      => $statuses,
                'default'      => $this->get_option('status_on_failed') ?? 'wc-failed',
                'description' => esc_attr__( 'This status is set when payment is failed', 'wc-gateway-oderopay' ),
            ),

            'status_on_success' => array(
                'title'       => esc_attr__( 'On Payment Success', 'wc-gateway-oderopay' ),
                'type'        => 'select',
                'options'      => $statuses,
                'default'      => $this->get_option('status_on_success') ?? 'wc-processing',
                'description' => esc_attr__( 'This status is set when payment is success', 'wc-gateway-oderopay' ),
            ),

            'secret_key' => array(
                'title'       => esc_attr__( 'Secret Key', 'wc-gateway-oderopay' ),
                'type'        => 'text',
                'description' => esc_attr__( 'Please set a random passphrase', 'wc-gateway-oderopay' ),
            ),

            'webhook_url' => array(
                'title'       => esc_attr__( 'Webhook Url', 'wc-gateway-oderopay' ),
                'type'        => 'title',
                'description' => /* translators: 1: webhook url 2: secret key */ wp_sprintf( esc_attr__('Please ensure that you have this endpoint on Odero Merchant Settings: %1$s?wc-api=ODEROPAY&secret_key=%2$s', 'wc-gateway-oderopay'), $webhookUrl, $this->get_option('secret_key')),
            ),

			'enable_logging' => array(
				'title'   => esc_attr__( 'Enable Logging', 'wc-gateway-oderopay' ),
				'type'    => 'checkbox',
				'label'   => esc_attr__( 'Enable transaction logging for gateway.', 'wc-gateway-oderopay' ),
				'default' => false,
			),

		);
	}

	public function verify_odero_payment(WP_REST_Request $data)
	{
        $params = $data->get_params();

        $order_id = $params['id'];

        if (!$order_id){
            throw new InvalidArgumentException('order id is required');
        }

		/** @var WC_Order $order */
		$order = wc_get_order(sanitize_text_field($params['id']));

        $oderoPaymentId = $order->get_meta(self::ODERO_PAYMENT_KEY);

        $payment = $this->odero->payments->get($oderoPaymentId);

        $this->log('ORDER UPDATE ON REDIRECT, ODERO PAYMENT: ' . $payment->toJSON());
        $this->update_order_by_odero_status($order, $payment->getStatus());

        //redirect to order
		wp_redirect( $order->get_view_order_url() );
		exit;
	}


	/**
	 * Determine if the gateway still requires setup.
	 *
	 * @return bool
	 */
	public function needs_setup()
    {
		return ! $this->get_option( 'merchant_id' ) || ! $this->get_option( 'merchant_token' );
	}


	/**
	 *
	 * Check if this gateway is enabled and available in the base currency being traded with.
	 *
	 * @return array
	 */
	public function check_requirements()
    {

		$errors = [
			// Check if the store currency is supported by OderoPay
			! in_array( get_woocommerce_currency(), $this->available_currencies ) ? 'wc-gateway-oderopay-error-invalid-currency' : null,
			// Check if user entered the merchant ID
			'no' === $this->get_option( 'sandbox' ) && empty( $this->get_option( 'merchant_id' ) )  ? 'wc-gateway-oderopay-error-missing-merchant-id' : null,
			// Check if user entered the merchant token
			'no' === $this->get_option( 'sandbox' ) && empty( $this->get_option( 'merchant_token' ) ) ? 'wc-gateway-oderopay-error-missing-merchant-token' : null,

            //
			'yes' === $this->get_option( 'sandbox' ) && empty( $this->get_option( 'merchant_id_sandbox' ) ) ? 'wc-gateway-oderopay-error-missing-merchant-id' : null,
			'yes' === $this->get_option( 'sandbox' ) && empty( $this->get_option( 'merchant_token_sandbox' ) ) ? 'wc-gateway-oderopay-error-missing-merchant-token' : null,
        ];

		return array_filter( $errors );
	}

	/**
	 * Check if the gateway is available for use.
	 *
	 * @return bool
	 */
	public function is_available()
    {
		if ( 'yes' === $this->enabled ) {
			$errors = $this->check_requirements();
			// Prevent using this gateway on frontend if there are any configuration errors.
			return 0 === count( $errors );
		}

		return parent::is_available();
	}

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options()
    {
		if (in_array( get_woocommerce_currency(), $this->available_currencies )) {
			parent::admin_options();
		} else {
		?>
			<h3><?php esc_attr_e( 'OderoPay', 'wc-gateway-oderopay' ); ?></h3>
			<div class="inline error">
                <p>
                    <strong><?php esc_attr_e( 'Gateway Disabled', 'wc-gateway-oderopay' ); ?></strong>
                    <?php /* translators: 1: a href link 2: closing href */ echo wp_sprintf( esc_html__( 'Choose RON, EUR or USD as your store currency in %1$sGeneral Settings%2$s to enable the OderoPay Gateway.', 'wc-gateway-oderopay' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">', '</a>' ); ?>
                </p>
            </div>
			<?php
		}
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @since 1.0.0
	 */
	public function process_payment( $order_id )
    {
        /** @var WC_Order $order */
        $order         = wc_get_order( $order_id );

        $order->set_payment_method('oderopay');

        //set billing address
        $country = (new League\ISO3166\ISO3166)->alpha2($order->get_billing_country() ?: $this->get_default_country());
        $billingAddress = new \Oderopay\Model\Address\BillingAddress();
        $billingAddress
            ->setAddress(sprintf('%s %s',
                !empty($order->get_billing_address_1()) ? $order->get_billing_address_1() : $order->get_shipping_address_1(),
                !empty($order->get_billing_address_2()) ? $order->get_billing_address_2() :  $order->get_shipping_address_2()
            ))
            ->setCity($order->get_billing_city())
            ->setCountry($country['alpha3']);

        //set shipping address
        $country = (new League\ISO3166\ISO3166)->alpha2($order->get_shipping_country() ?: $this->get_default_country());
        $deliveryAddress = new \Oderopay\Model\Address\DeliveryAddress();
        $deliveryAddress
            ->setAddress(sprintf('%s %s',
                !empty($order->get_shipping_address_1()) ? $order->get_shipping_address_1() :  $order->get_billing_address_1(),
                !empty($order->get_shipping_address_2()) ? $order->get_shipping_address_2() : $order->get_billing_address_2()
            ))
            ->setCity($order->get_shipping_city() ?: $order->get_billing_city())
            ->setCountry($country['alpha3'])
            ->setDeliveryType($order->get_shipping_method() ?: "no-shipping");

        $phone = $order->get_billing_phone() ?? $order->get_shipping_phone();
        $phoneNumber = $this->add_country_code_to_phone($phone, $country);

        $customer = new \Oderopay\Model\Payment\Customer();
        $customer
            ->setEmail($order->get_billing_email())
            ->setPhoneNumber($phoneNumber)
            ->setDeliveryInformation($deliveryAddress)
            ->setBillingInformation($billingAddress);

        $products = [];

        $cartTotal = 0;
        foreach ( $order->get_items() as $item_id => $item ) {

            /** @var WC_Product $wooProduct */
            $wooProduct = $item->get_product();
            $image = get_the_post_thumbnail_url($wooProduct->get_id());

            $productPrice = wc_get_price_excluding_tax( $wooProduct );
            $price =  number_format($productPrice, 2, '.', '');

            $productTotal = $price * $item->get_quantity();
            /** @var  WC_Order_Item $item */
            $product = new \Oderopay\Model\Payment\BasketItem();
            $product
                ->setExtId( $item->get_product_id())
                ->setName($wooProduct->get_name())
                ->setPrice($price)
                ->setTotal($productTotal)
                ->setQuantity($item->get_quantity());

			$cartTotal += $product->getTotal();

            if(!empty($image)){
                $product->setImageUrl($image);
			}

            $products[] = $product;

        }

        //add shipping cost
        if($order->get_shipping_total() > 0){
            $cargoImage = WP_PLUGIN_URL . '/' . plugin_basename( dirname( dirname( __FILE__ ) ) ) . '/assets/images/cargo.webp';
            $shippingItem = new \Oderopay\Model\Payment\BasketItem();

			$price = number_format($order->get_shipping_total(), 2, '.', '');

            $shippingItem
                ->setExtId($order->get_shipping_method())
                ->setImageUrl( $cargoImage)
                ->setName($order->get_shipping_method())
                ->setPrice($price)
                ->setQuantity(1);
            $products[] = $shippingItem;

			$cartTotal += $shippingItem->getTotal();

        }

        //add taxes
        foreach ($order->get_tax_totals() as $tax) {
            $taxImage = WP_PLUGIN_URL . '/' . plugin_basename( dirname( dirname( __FILE__ ) ) ) . '/assets/images/tax.png';
            $taxItem = new \Oderopay\Model\Payment\BasketItem();

			$price = number_format($tax->amount, 2, '.', '');

            $taxItem
                ->setExtId($tax->id)
                ->setImageUrl($taxImage)
                ->setName($tax->label)
                ->setPrice($price)
                ->setQuantity(1);
            $products[] = $taxItem;
			$cartTotal += $taxItem->getTotal();
        }


        //add the order discount
        $discountTotal =  number_format($order->get_discount_total(), 2, '.', '');
        if($discountTotal > 0 ){
			$couponImage = WP_PLUGIN_URL . '/' . plugin_basename( dirname( dirname( __FILE__ ) ) ) . '/assets/images/voucher.png';
			$couponItem = new \Oderopay\Model\Payment\BasketItem();

			$couponItem
				->setExtId('DISCOUNT')
				->setImageUrl($couponImage)
				->setName('DISCOUNT')
				->setPrice($discountTotal)
				->setQuantity(-1);
			$products[] = $couponItem;

			$cartTotal += $couponItem->getTotal();
		}

        $nonce = wp_create_nonce();
        $returnUrl = wp_sprintf('%s?rest_route=/wc/odero/%s/verify&_wpnonce=%s', esc_attr(site_url('/')), $order->get_order_number(), $nonce);

        $cartTotal  = sprintf("%.2f", $cartTotal);
      //  $returnUrl = $this->get_return_url( $order );
        $paymentRequest = new \Oderopay\Model\Payment\Payment();
        $paymentRequest
            ->setAmount($cartTotal)
            ->setCurrency(get_woocommerce_currency())
            ->setExtOrderId($order->get_id())
            ->setExtOrderUrl($returnUrl)
            ->setSuccessUrl($returnUrl)
            ->setReturnUrl($returnUrl)
            ->setFailUrl($returnUrl)
            ->setMerchantId($this->merchant_id)
            ->setCustomer($customer)
            ->setProducts($products)
        ;

		$payload = $paymentRequest->toArray();
		$this->log('Odero Payload: '.wp_json_encode($payload), WC_Log_Levels::INFO);

        $payment = $this->odero->payments->create($paymentRequest); //PaymentIntentResponse

        // Mark as on-hold (we're awaiting the cheque)
        $order->update_status($this->get_option('status_on_process'), __( 'Awaiting cheque payment', 'wc-gateway-oderopay' ));

        if($payment->isSuccess()){
            //save the odero payment id
            $order->add_meta_data(self::ODERO_PAYMENT_KEY, $payment->data['paymentId']);
            $order->save();

            return  array(
                'result' => 'success',
                'redirect' => $payment->data['url']
            );
        }else{
            wc_add_notice(  $payment->getMessage(), 'error' );
        }

        $this->log(wp_json_encode($payment->toArray()), WC_Log_Levels::INFO);

    }

	/**
	 * Receipt page.
	 *
	 * Display text and a button to direct the user to OderoPay.
	 *
	 */
	public function receipt_page( $order )
    {
		echo '<p>' . esc_html__( 'Thank you for your order, please click the button below to pay with OderoPay.', 'wc-gateway-oderopay' ) . '</p>';
	}

    public function webhook()
    {
		$request  = $_REQUEST;

        if(empty(sanitize_text_field($request['secret_key'])) || sanitize_text_field($request['secret_key']) !== $this->secret_key){
            // THE REQUEST ATTACK
            $this->log("CALLBACK ATTACK!  " . wp_json_encode($request), WC_Log_Levels::CRITICAL);
			die('Security check');
        }

        $this->log("RECEIVED PAYLOAD " . wp_json_encode($request), WC_Log_Levels::NOTICE);
        $message = $this->odero->webhooks->handle($request);

        switch (true) {
            case $message instanceof \Oderopay\Model\Webhook\Payment:
                /** @var  \Oderopay\Model\Webhook\Payment $message */
                $data = $message->getData();
                $wcOrderId = sanitize_text_field($data['extOrderId']);

				/** @var WC_Order $order */
                $order = wc_get_order($wcOrderId);

                if(empty($order)) return;

                $this->update_order_by_odero_status($order, $message->getStatus());

                break;

            default:
                $this->log(wp_json_encode($request), WC_Log_Levels::CRITICAL);
                return -1;
        }
	}


	public function update_order_by_odero_status(WC_Order $order, $status = 'SUCCESS')
	{
		if($order->get_payment_method() !== 'oderopay') {
			$this->log('Callback Received but skipped');
			return;
		}

        if(in_array($status, ['INITIATED'])){
			return;
		}

		if ($status === 'SUCCESS'){
			//set order as paid
			$order->update_status($this->get_option('status_on_success'), esc_attr__('Payment Success', 'wc-gateway-oderopay' ));
			//reduce stocks
			wc_reduce_stock_levels( $order->get_id() );
			$this->log('Order Update (Success) : '. wp_json_encode($order), WC_Log_Levels::NOTICE);

		}else{
			// set status failed
			$order->update_status($this->get_option('status_on_failed'), esc_attr__('Payment failed', 'wc-gateway-oderopay' ));
			$this->log('Order Update (Status) :  '. $this->get_option('status_on_failed'));
			$this->log('Order Update (Failed) : '. wp_json_encode($order), WC_Log_Levels::ERROR);
		}
    }

	/**
	 * Setup constants.
	 *
	 * Setup common values and messages used by the OderoPay gateway.
	 */
	public function setup_constants() {
		// Create user agent string.
		define( 'ODERO_SOFTWARE_NAME', 'WooCommerce' );
		define( 'ODERO_MODULE_NAME', 'WooCommerce-OderoPay-Gateway' );
		define( 'ODERO_MODULE_VER', $this->version );

		// Features
		// - PHP
		$pf_features = 'PHP ' . phpversion() . ';';

		// - cURL
		if ( in_array( 'curl', get_loaded_extensions() ) ) {
			define( 'ODERO_CURL', '' );
			$pf_version = curl_version();
			$pf_features .= ' curl ' . $pf_version['version'] . ';';
		} else {
			$pf_features .= ' nocurl;';
		}

		// Create user agrent
		define( 'ODERO_USER_AGENT', ODERO_SOFTWARE_NAME . '/' . ' (' . trim( $pf_features ) . ') ' . ODERO_MODULE_NAME . '/' . ODERO_MODULE_VER );

		// General Defines
		define( 'ODERO_TIMEOUT', 15 );
		define( 'ODERO_EPSILON', 0.01 );

		// Messages
		// Error
		define( 'ODERO_ERR_AMOUNT_MISMATCH', esc_attr__( 'Amount mismatch', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_BAD_ACCESS', esc_attr__( 'Bad access of page', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_BAD_SOURCE_IP', esc_attr__( 'Bad source IP address', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_CONNECT_FAILED', esc_attr__( 'Failed to connect to OderoPay', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_INVALID_SIGNATURE', esc_attr__( 'Security signature mismatch', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_MERCHANT_ID_MISMATCH', esc_attr__( 'Merchant ID mismatch', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_NO_SESSION', esc_attr__( 'No saved session found for ITN transaction', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_ORDER_ID_MISSING_URL', esc_attr__( 'Order ID not present in URL', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_ORDER_ID_MISMATCH', esc_attr__( 'Order ID mismatch', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_ORDER_INVALID', esc_attr__( 'This order ID is invalid', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_ORDER_NUMBER_MISMATCH', esc_attr__( 'Order Number mismatch', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_ORDER_PROCESSED', esc_attr__( 'This order has already been processed', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_PDT_FAIL', esc_attr__( 'PDT query failed', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_PDT_TOKEN_MISSING', esc_attr__( 'PDT token not present in URL', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_SESSIONID_MISMATCH', esc_attr__( 'Session ID mismatch', 'wc-gateway-oderopay' ) );
		define( 'ODERO_ERR_UNKNOWN', esc_attr__( 'Unkown error occurred', 'wc-gateway-oderopay' ) );

		// General
		define( 'ODERO_MSG_OK', esc_attr__( 'Payment was successful', 'wc-gateway-oderopay' ) );
		define( 'ODERO_MSG_FAILED', esc_attr__( 'Payment has failed', 'wc-gateway-oderopay' ) );
		define( 'ODERO_MSG_PENDING', esc_attr__( 'The payment is pending. Please note, you will receive another Instant Transaction Notification when the payment status changes to "Completed", or "Failed"', 'wc-gateway-oderopay' ) );

		do_action( 'woocommerce_gateway_oderopay_setup_constants' );
	}

	/**
	 * Log system processes.
	 */
	public function log( $message, $level = WC_Log_Levels::NOTICE  ) {
		if ( $this->get_option( 'sandbox' ) || $this->enable_logging ) {
			if ( empty( $this->logger ) ) {
				$this->logger = new WC_Logger();
			}
			$this->logger->add( 'oderopay', $message, $level );
		}
	}


	/**
	 * Get order property with compatibility check on order getter introduced
	 * in WC 3.0.
	 **
	 * @param WC_Order $order Order object.
	 * @param string   $prop  Property name.
	 *
	 * @return mixed Property value
	 */
	public static function get_order_prop( $order, $prop ) {
		switch ( $prop ) {
			case 'order_total':
				$getter = array( $order, 'get_total' );
				break;
			default:
				$getter = array( $order, 'get_' . $prop );
				break;
		}

		return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $prop };
	}

	/**
	 * Gets user-friendly error message strings from keys
	 *
	 * @param   string  $key  The key representing an error
	 *
	 * @return  string        The user-friendly error message for display
	 */
	public function get_error_message( $key ) {
		switch ( $key ) {
			case 'wc-gateway-oderopay-error-invalid-currency':
				return __( 'Your store uses a currency that OderoPay doesnt support yet.', 'wc-gateway-oderopay' );
			case 'wc-gateway-oderopay-error-missing-merchant-id':
				return __( 'You forgot to fill your merchant ID.', 'wc-gateway-oderopay' );
			case 'wc-gateway-oderopay-error-missing-merchant-token':
				return __( 'You forgot to fill your merchant token.', 'wc-gateway-oderopay' );
			case 'wc-gateway-oderopay-error-missing-pass-phrase':
				return __( 'OderoPay requires a passphrase to work.', 'wc-gateway-oderopay' );
			default:
				return '';
		}
	}

	/**
	*  Show possible admin notices
	*/
	public function admin_notices() {

		// Get requirement errors.
		$errors_to_show = $this->check_requirements();

		// If everything is in place, don't display it.
		if ( ! count( $errors_to_show ) ) {
			return;
		}

		// If the gateway isn't enabled, don't show it.
		if ( "no" ===  $this->enabled ) {
			return;
		}

		// Use transients to display the admin notice once after saving values.
		if ( ! get_transient( 'wc-gateway-oderopay-admin-notice-transient' ) ) {
			set_transient( 'wc-gateway-oderopay-admin-notice-transient', 1, 1);

			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'To use OderoPay as a payment provider, you need to fix the problems below:', 'wc-gateway-oderopay' ) . '</p>'
				. '<ul style="list-style-type: disc; list-style-position: inside; padding-left: 2em;">'
				// phpcs:ignore  WordPress.Security.EscapeOutput.OutputNotEscaped
				. array_reduce( $errors_to_show, function( $errors_list, $error_item ) {
					$errors_list = $errors_list . PHP_EOL . ( '<li>' . $this->get_error_message($error_item) . '</li>' );
					return $errors_list;
				}, '' )
				. '</ul></p></div>';
		}
	}

    /**
     * add custom query for orders
     *
     * @param $query
     * @param $query_vars
     * @return array
     */
    public function handle_order_number_custom_query_var( $query, $query_vars ) {

        if ( ! empty( $query_vars[self::ODERO_PAYMENT_KEY] ) ) {
            $query['meta_query'][] = array(
                'key' => self::ODERO_PAYMENT_KEY,
                'value' => esc_attr( $query_vars[self::ODERO_PAYMENT_KEY] ),
            );
        }

        return $query;
    }

    private function get_default_country()
    {
        $wooCommerceCountry = get_option( 'woocommerce_default_country' );

        $country  = explode(':', $wooCommerceCountry);
        $country  = reset($country);

        return $country ?: 'RO';

    }

    private function add_country_code_to_phone(?string $phone, array $country)
    {
        $code = WC()->countries->get_country_calling_code( $country['alpha2'] ?? "RO" );
        return preg_replace('/^(?:\+?'. (int) $code.'|0)?/',$code, $phone);
    }
}
