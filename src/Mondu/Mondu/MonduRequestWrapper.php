<?php

namespace Mondu\Mondu;

use Mondu\Exceptions\MonduException;
use Mondu\Exceptions\ResponseException;
use Mondu\Mondu\Support\Helper;
use Mondu\Mondu\Support\OrderData;
use Mondu\Plugin;
use WC_Order;
use WC_Order_Refund;

class MonduRequestWrapper {
	private $api;

	/** @var api_errors */
	private static $api_errors  = array();

	public function __construct() {
		$this->api = new Api();

		// Error handling on admin pages
		add_action( 'admin_notices', array( $this, 'output_errors' ) );
		add_action( 'shutdown', array( $this, 'save_errors' ) );
	}

	/**
	 * Add an error message.
	 * @param string $text
	 */
	public static function add_error( $text ) {
		self::$api_errors[] = $text;
	}

	/**
	 * Save errors to an option.
	 */
	public function save_errors() {
		update_option( '_mondu_api_errors', self::$api_errors );
	}

	/**
	 * Show any stored error messages.
	 */
	public function output_errors() {

		if (!is_admin())
			return;

		$errors = maybe_unserialize( get_option( '_mondu_api_errors' ) );

		if ( !empty( $errors ) ) {
			echo '<div id="woocommerce_errors" class="error notice is-dismissible">';

			foreach ( $errors as $error ) {
				echo '<p> Mondu: ' . wp_kses_post( $error ) . '</p>';
			}

			echo '</div>';

			// Clear
			delete_option( '_mondu_api_errors' );
		}
	}

	/**
	 * Create Order
	 *
	 * @return mixed|void
	 * @throws ResponseException
	 */
	public function create_order( WC_Order $order, $success_url ) {
		if ( !Plugin::order_has_mondu( $order ) ) {
			return;
		}

		$order_data  = OrderData::create_order( $order, $success_url );
		$response    = $this->wrap_with_mondu_log_event( 'create_order', [ $order_data ] );
		$mondu_order = $response['order'];
		update_post_meta( $order->get_id(), Plugin::ORDER_ID_KEY, $mondu_order['uuid'] );
		return $mondu_order;
	}

	/**
	 * Get Mondu Order
	 *
	 * @param $order_id
	 * @return mixed|void
	 * @throws ResponseException
	 */
	public function get_order( $order_id ) {
		$order = new WC_Order($order_id);
		if ( !Plugin::order_has_mondu($order) ) {
			return;
		}

		$mondu_order_id = get_post_meta($order_id, Plugin::ORDER_ID_KEY, true);
		$response       = $this->wrap_with_mondu_log_event( 'get_order', [ $mondu_order_id ] );
		return isset($response['order']) ? $response['order'] : null;
	}

	/**
	 * Adjust Order
	 *
	 * @param $order_id
	 * @param $data_to_update
	 * @return mixed|void
	 * @throws ResponseException
	 */
	public function adjust_order( $order_id, $data_to_update ) {
		$order = new WC_Order($order_id);
		if ( !Plugin::order_has_mondu($order) ) {
			return;
		}

		$mondu_order_id = get_post_meta($order_id, Plugin::ORDER_ID_KEY, true);
		$response       = $this->wrap_with_mondu_log_event( 'adjust_order', [ $mondu_order_id, $data_to_update ] );
		return $response['order'];
	}

	/**
	 * Cancel Order
	 *
	 * @param $order_id
	 * @return mixed|void
	 * @throws ResponseException
	 */
	public function cancel_order( $order_id ) {
		$order = new WC_Order($order_id);
		if ( !Plugin::order_has_mondu($order) ) {
			return;
		}

		$mondu_order_id = get_post_meta($order_id, Plugin::ORDER_ID_KEY, true);
		$response       = $this->wrap_with_mondu_log_event( 'cancel_order', [ $mondu_order_id ] );
		return $response['order'];
	}

	/**
	 * Ship Order
	 *
	 * @param $order_id
	 * @return mixed|void
	 * @throws ResponseException
	 */
	public function ship_order( $order_id ) {
		$order = new WC_Order($order_id);
		if ( !Plugin::order_has_mondu($order) ) {
			return;
		}

		$mondu_order_id = get_post_meta($order_id, Plugin::ORDER_ID_KEY, true);
		$invoice_data   = OrderData::invoice_data_from_wc_order($order);
		$response       = $this->wrap_with_mondu_log_event( 'ship_order', [ $mondu_order_id, $invoice_data ] );
		$invoice        = $response['invoice'];
		update_post_meta($order_id, Plugin::INVOICE_ID_KEY, $invoice['uuid']);
		return $invoice;
	}

	/**
	 * Get invoices
	 *
	 * @param $order_id
	 * @return mixed|void
	 * @throws ResponseException
	 */
	public function get_invoices( $order_id ) {
		$order = new WC_Order($order_id);
		if ( !Plugin::order_has_mondu($order) ) {
			return;
		}

		$mondu_order_id = get_post_meta($order_id, Plugin::ORDER_ID_KEY, true);
		$response       = $this->wrap_with_mondu_log_event( 'get_invoices', [ $mondu_order_id ] );
		return $response['invoices'];
	}

	/**
	 * Get invoice
	 *
	 * @param $order_id
	 * @return mixed|void
	 * @throws ResponseException
	 */
	public function get_invoice( $order_id ) {
		$order = new WC_Order($order_id);
		if ( !Plugin::order_has_mondu($order) ) {
			return;
		}

		$mondu_order_id   = get_post_meta($order_id, Plugin::ORDER_ID_KEY, true);
		$mondu_invoice_id = get_post_meta($order_id, Plugin::INVOICE_ID_KEY, true);
		$response         = $this->wrap_with_mondu_log_event( 'get_invoice', [ $mondu_order_id, $mondu_invoice_id ] );
		return $response['invoice'];
	}


	/**
	 * Get Merchant Payment Methods
	 *
	 * @return array
	 */
	public function get_merchant_payment_methods() {
		$merchant_payment_methods = get_transient('mondu_merchant_payment_methods');
		if ( false === $merchant_payment_methods ) {
			try {
				$response = $this->wrap_with_mondu_log_event( 'get_payment_methods' );

				if ( !$response ) {
					return [];
				}

				# return only an array with the identifier (invoice, direct_debit, installment, etc)
				$merchant_payment_methods = array_map(function( $payment_method ) {
					return $payment_method['identifier'];
				}, $response['payment_methods']);
				set_transient('mondu_merchant_payment_methods', $merchant_payment_methods, 1 * 60);
				return $merchant_payment_methods;
			} catch ( \Exception $e ) {
				$merchant_payment_methods = array_keys(Plugin::PAYMENT_METHODS);
				set_transient('mondu_merchant_payment_methods', $merchant_payment_methods, 10 * 60);
				return $merchant_payment_methods;
			}
		}
		return $merchant_payment_methods;
	}

	/**
	 * Confirm Order
	 *
	 * @param $order_id
	 * @return void|WC_Order
	 * @throws ResponseException
	 */
	public function confirm_order( $order_id, $mondu_order_id ) {
		$order = new WC_Order($order_id);
		if ( !Plugin::order_has_mondu($order) ) {
			return;
		}

		$response = $this->wrap_with_mondu_log_event( 'confirm_order', [ $mondu_order_id ] );
		return $response['order'];
	}

	/**
	 * Update Order if fields were changed
	 *
	 * @param $order
	 * @return void
	 * @throws ResponseException
	 */
	public function update_order_if_changed_some_fields( $order ) {
		if ( !Plugin::order_has_mondu($order) ) {
			return;
		}

		if ( array_intersect([ 'total', 'discount_total', 'discount_tax', 'cart_tax', 'total_tax', 'shipping_tax', 'shipping_total' ], array_keys($order->get_changes())) ) {
			$data_to_update = OrderData::order_data_from_wc_order_with_amount($order);
			$this->adjust_order($order->get_id(), $data_to_update);
		}
	}

	/**
	 * Handle Order status change
	 *
	 * @param $order_id
	 * @param $from_status
	 * @param $to_status
	 * @return void
	 * @throws ResponseException
	 */
	public function order_status_changed( $order_id, $from_status, $to_status ) {
		$order = new WC_Order($order_id);
		if ( !Plugin::order_has_mondu($order) ) {
			return;
		}

		Helper::log([
			'order_id'    => $order_id,
			'from_status' => $from_status,
			'to_status'   => $to_status,
		]);

		if ( 'cancelled' === $to_status ) {
			$this->cancel_order($order_id);
		}
		if ( 'completed' === $to_status ) {
			$this->ship_order($order_id);
		}
	}

	/**
	 * Handle Order Refunded
	 *
	 * @param $order_id
	 * @param $refund_id
	 * @return void
	 * @throws ResponseException
	 */
	public function order_refunded( $order_id, $refund_id ) {
		$order = new WC_Order($order_id);
		if ( !Plugin::order_has_mondu($order) ) {
			return;
		}

		$mondu_invoice_id = get_post_meta($order->get_id(), Plugin::INVOICE_ID_KEY, true);
		if ( !$mondu_invoice_id ) {
			Helper::log([
				'skipping_credit_note_creation' => [
					'order' => $order_id,
					'refund' => $refund_id,
				],
			]);
			return;
		}

		$refund      = new WC_Order_Refund($refund_id);
		$credit_note = OrderData::create_credit_note($refund);

		$this->wrap_with_mondu_log_event( 'create_credit_note', [ $mondu_invoice_id, $credit_note ] );
	}


	/**
	 * Cancel Invoice
	 *
	 * @param $mondu_order_id
	 * @param $mondu_invoice_id
	 * @return void
	 * @throws ResponseException
	 */
	public function cancel_invoice( $mondu_order_id, $mondu_invoice_id ) {
		$this->wrap_with_mondu_log_event( 'cancel_invoice', [ $mondu_order_id, $mondu_invoice_id ] );
	}

	/**
	 * Register Webhook
	 *
	 * @param string $topic
	 * @return mixed
	 * @throws ResponseException
	 */
	public function register_webhook( $topic ) {
		$params = [
			'topic'   => $topic,
			'address' => MONDU_WEBHOOKS_URL . '/?rest_route=/mondu/v1/webhooks/index',
		];

		$response = $this->wrap_with_mondu_log_event( 'register_webhook', [ $params ]);

		return isset($response['webhooks']) ? $response['webhooks'] : null;
	}

	/**
	 * Get Webhooks
	 *
	 * @return mixed
	 * @throws ResponseException
	 */
	public function get_webhooks() {
		$response = $this->wrap_with_mondu_log_event( 'get_webhooks' );

		return $response['webhooks'];
	}

	/**
	 * Webhook Secret
	 *
	 * @return mixed
	 * @throws ResponseException
	 */
	public function webhook_secret() {
		$response = $this->wrap_with_mondu_log_event( 'webhook_secret' );

		return $response['webhook_secret'];
	}

	/**
	 * Log Plugin event
	 *
	 * @param \Exception $exception
	 * @param string $event
	 * @param $body
	 * @return void
	 */
	public function log_plugin_event( \Exception $exception, $event, $body = null ) {
		global $wp_version;
		$params = [
			'plugin'           => 'woocommerce',
			'version'          => MONDU_PLUGIN_VERSION,
			'language_version' => 'PHP ' . phpversion(),
			'shop_version'     => $wp_version,
			'origin_event'     => strtoupper($event),
			'response_body'    => $body,
			'response_status'  => (string) $exception->getCode(),
			'error_message'    => $exception->getMessage(),
			'error_trace'      => $exception->getTraceAsString(),
		];
		$this->api->log_plugin_event($params);
	}

	/**
	 * Wrap the call to the Mondu API with a try/catch block and log if an error occurs
	 *
	 * @param string $action
	 * @param array $params
	 * @return mixed
	 * @throws ResponseException
	 * @throws Exception
	 */
	private function wrap_with_mondu_log_event( $action, array $params = [] ) {
		try {
			return call_user_func_array( [ $this->api, $action ], $params);
		} catch ( ResponseException $e ) {
			$this->log_plugin_event( $e, $action, $e->getBody() );
			if (is_admin()) {
				$messages = $e->get_api_message();
				foreach ($messages as $message) {
					$this->add_error($message);
				}
			}
			throw $e;
		} catch ( \Exception $e ) {
			$this->log_plugin_event( $e, $action );
			throw $e;
		}
	}
}
