<?php

// +----------------------------------------------------------------------+
// | Copyright Incsub (http://incsub.com/)                                |
// +----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License, version 2, as  |
// | published by the Free Software Foundation.                           |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to the Free Software          |
// | Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,               |
// | MA 02110-1301 USA                                                    |
// +----------------------------------------------------------------------+

/**
 * Authorize.Net gateway class.
 *
 * @since 3.5
 *
 * @category Membership
 * @package Gateway
 */
class Membership_Gateway_Authorize extends Membership_Gateway {

	const MODE_SANDBOX = 'sandbox';
	const MODE_LIVE    = 'live';

	/**
	 * Gateway id.
	 *
	 * @since 3.5
	 *
	 * @access public
	 * @var string
	 */
	public $gateway = 'authorize';

	/**
	 * Gateway title.
	 *
	 * @since 3.5
	 *
	 * @access public
	 * @var string
	 */
	public $title = 'Authorize.Net';

	/**
	 * Determines whether gateway has payment form or not.
	 *
	 * @since 3.5
	 *
	 * @access public
	 * @var boolean
	 */
	public $haspaymentform = true;

	/**
	 * Array of payment result.
	 *
	 * @since 3.5
	 *
	 * @access private
	 * @var array
	 */
	private $_payment_result;

	/**
	 * Current member.
	 *
	 * @since 3.5
	 *
	 * @access private
	 * @var M_Membership
	 */
	private $_member;

	/**
	 * Current subscription.
	 *
	 * @since 3.5
	 *
	 * @access private
	 * @var M_Subscription
	 */
	private $_subscription;

	/**
	 * Constructor.
	 *
	 * @since 3.5
	 *
	 * @access public
	 */
	public function __construct() {
		parent::__construct();

		$this->_add_action( 'M_gateways_settings_' . $this->gateway, 'render_settings' );
		$this->_add_action( 'membership_purchase_button', 'render_subscribe_button', 10, 3 );
		$this->_add_action( 'membership_payment_form_' . $this->gateway, 'render_payment_form', 10, 3 );
		$this->_add_action( 'wp_enqueue_scripts', 'enqueue_scripts' );
		$this->_add_action( 'wp_login', 'propagate_ssl_cookie', 10, 2 );

		$this->_add_ajax_action( 'processpurchase_' . $this->gateway, 'process_purchase', true, true );
		$this->_add_ajax_action( 'purchaseform', 'render_popover_payment_form' );
	}

	/**
	 * Propagates SSL cookies when user logs in.
	 *
	 * @since 3.5
	 * @action wp_login 10 2
	 *
	 * @access public
	 * @param type $login
	 * @param WP_User $user
	 */
	public function propagate_ssl_cookie( $login, WP_User $user ) {
		if ( !is_ssl() ) {
			wp_set_auth_cookie( $user->ID, true, true );
		}
	}

	/**
	 * Renders gateway settings page.
	 *
	 * @since 3.5
	 * @action M_gateways_settings_authorize
	 *
	 * @access public
	 */
	public function render_settings() {
		$method = defined( 'MEMBERSHIP_GLOBAL_TABLES' ) && filter_var( MEMBERSHIP_GLOBAL_TABLES, FILTER_VALIDATE_BOOLEAN )
			? 'get_site_option'
			: 'get_option';

		$template = new Membership_Render_Gateway_Authorize_Settings();

		$template->api_user = $method( $this->gateway . "_api_user" );
		$template->api_key = $method( $this->gateway . "_api_key" );

		$template->mode = $method( $this->gateway . "_mode", self::MODE_SANDBOX );
		$template->modes = array(
			self::MODE_SANDBOX => __( 'Sandbox', 'membership' ),
			self::MODE_LIVE    => __( 'Live', 'membership' ),
		);

		$template->render();
	}

	/**
	 * Updates gateway options.
	 *
	 * @since 3.5
	 *
	 * @access public
	 */
	public function update() {
		$method = defined( 'MEMBERSHIP_GLOBAL_TABLES' ) && filter_var( MEMBERSHIP_GLOBAL_TABLES, FILTER_VALIDATE_BOOLEAN )
			? 'update_site_option'
			: 'update_option';

		$mode = filter_input( INPUT_POST, 'mode' );
		if ( in_array( $mode, array( self::MODE_LIVE, self::MODE_SANDBOX ) ) ) {
			$method( $this->gateway . "_mode", $mode );
		}

		foreach ( array( 'api_user', 'api_key' ) as $option ) {
			$key = "{$this->gateway}_{$option}";
			if ( isset( $_POST[$option] ) ) {
				$method( $key, filter_input( INPUT_POST, $option ) );
			}
		}
	}

	/**
	 * Renders payment button.
	 *
	 * @since 3.5
	 * @action membership_purchase_button 10 3
	 *
	 * @access public
	 * @global array $M_options The array of membership options.
	 * @param M_Subscription $subscription
	 * @param array $pricing The pricing information.
	 * @param int $user_id The current user id.
	 */
	public function render_subscribe_button( M_Subscription $subscription, $pricing, $user_id ) {
		global $M_options;

		$actionurl = isset( $M_options['registration_page'] ) ? str_replace('http:', 'https:', get_permalink( $M_options['registration_page'] ) ) : '';
		if ( empty( $actionurl ) ) {
			$actionurl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		}

		$template = new Membership_Render_Gateway_Authorize_Button();

		$template->gateway = $this->gateway;
		$template->subscription_id = $subscription->id;
		$template->user_id = $user_id;

		$actionurl = add_query_arg( array( 'action' => 'registeruser', 'subscription' => $subscription->id ), $actionurl );
		$template->actionurl = $actionurl;

		$coupon = membership_get_current_coupon();
		$template->coupon_code = !empty( $coupon ) ? $coupon->get_coupon_code() : '';

		$template->render();
	}

	/**
	 * Renders payment form.
	 *
	 * @since 3.5
	 * @action membership_payment_form_authorize
	 *
	 * @access public
	 * @param M_Subscription $subscription The current subscription to subscribe to.
	 * @param array $pricing The pricing information.
	 * @param int $user_id The current user id.
	 */
	public function render_payment_form( M_Subscription $subscription, $pricing, $user_id ) {
		$coupon = membership_get_current_coupon();

		$api_u = get_option( $this->gateway . "_api_user" );
		$api_k = get_option( $this->gateway . "_api_key" );
		$error = false;
		if ( isset( $_GET['errors'] ) ) {
			if ( $_GET['errors'] == 1 ) {
				$error = __( 'Payment method not supported for the payment', 'membership' );
			} elseif ( $_GET['errors'] == 2 ) {
				$error = __( 'There was a problem processing your purchase. Please, try again.', 'membership' );
			}
		}
		if ( !isset( $api_u ) || $api_u == '' || $api_u == false || !isset( $api_k ) || $api_k == '' || $api_k == false ) {
			$error = __( 'This payment gateway has not been configured. Your transaction will not be processed.', 'membership' );
		}


		$template = new Membership_Render_Gateway_Authorize_Form();

		$template->error = $error;
		$template->coupon = !empty( $coupon ) ? $coupon->get_coupon_code() : '';
		$template->subscription_id = $subscription->id;
		$template->gateway = $this->gateway;
		$template->user_id = $user_id;

		$template->render();
	}

	/**
	 * Renders popover payment form.
	 *
	 * @since 3.5
	 * @action wp_ajax_purchaseform
	 *
	 * @access public
	 * @global WP_Scripts $wp_scripts
	 */
	public function render_popover_payment_form() {
		if ( filter_input( INPUT_POST, 'gateway' ) != $this->gateway ) {
			return;
		}

		$subscription = new M_Subscription( filter_input( INPUT_POST, 'subscription', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) );
		$user_id = filter_input( INPUT_POST, 'user', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'default' => get_current_user_id() ) ) );
		do_action( 'membership_payment_form_' . $this->gateway, $subscription, null, $user_id );
	}

	/**
	 * Processes purchase action.
	 *
	 * @since 3.5
	 * @action wp_ajax_nopriv_processpurchase_authorize
	 * @action wp_ajax_processpurchase_authorize
	 *
	 * @access public
	 */
	public function process_purchase() {
		global $M_options;
		if ( empty( $M_options['paymentcurrency'] ) ) {
			$M_options['paymentcurrency'] = 'USD';
		}

		if ( !is_ssl() ) {
			wp_die( __( 'You must use HTTPS in order to do this', 'membership' ) );
			exit;
		}

		// fetch subscription and pricing
		$sub_id = filter_input( INPUT_POST, 'subscription_id', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		$this->_subscription = new M_Subscription( $sub_id );
		$pricing = $this->_subscription->get_pricingarray();
		if ( !$pricing ) {
			status_header( 404 );
			exit;
		}

		// apply a coupon
		$coupon = membership_get_current_coupon();
		if ( $coupon && $coupon->valid_for_subscription( $this->_subscription->id ) ) {
			$pricing = $coupon->apply_coupon_pricing( $pricing );
		}

		// fetch member
		$user_id = is_user_logged_in() ? get_current_user_id() : $_POST['user_id'];
		$this->_member = new M_Membership( $user_id );

		// process payments
		$started = new DateTime();
		$this->_payment_result = array( 'status' => '', 'errors' => array() );
		for ( $i = 0, $count = count( $pricing ); $i < $count; $i++ ) {
			switch ( $pricing[$i]['type'] ) {
				case 'finite':
					$unit = false;
					switch ( $pricing[$i]['unit'] ) {
						case 'd': $unit = 'day';   break;
						case 'w': $unit = 'week';  break;
						case 'm': $unit = 'month'; break;
						case 'y': $unit = 'year';  break;
					}

					$this->_process_nonserial_purchase( $pricing[$i], $started, $i );
					$started->modify( sprintf( '+%d %s', $pricing[$i]['period'], $unit ) );
					break;
				case 'indefinite':
					$this->_process_nonserial_purchase( $pricing[$i], $started, $i );
					break 2;
				case 'serial':
					$this->_process_serial_purchase( $pricing[$i], $started, $i );
					break 2;
			}

			if ( $this->_payment_result['status'] == 'error' ) {
				break;
			}
		}

		if ( $this->_payment_result['status'] == 'success' ) {
			if ( $this->_member->has_subscription() && $this->_member->on_sub( $sub_id ) ) {
				$this->_member->expire_subscription( $sub_id );
				$this->_member->create_subscription( $sub_id, $this->gateway );
			} else {
				$this->_member->create_subscription( $sub_id, $this->gateway );
			}

			$popup = isset( $M_options['formtype'] ) && $M_options['formtype'] == 'new';
			if ( $popup && !empty( $M_options['registrationcompleted_message'] ) ) {
				$html = '<div class="header" style="width: 750px"><h1>';
				$html .= sprintf( __( 'Sign up for %s completed', 'membership' ), $this->_subscription->sub_name() );
				$html .= '</h1></div><div class="fullwidth">';
				$html .= wpautop( $M_options['registrationcompleted_message'] );
				$html .= '</div>';

				$this->_payment_result['redirect'] = 'no';
				$this->_payment_result['message'] = $html;
			} else {
				$this->_payment_result['message'] = '';
				$this->_payment_result['redirect'] = strpos( home_url(), 'https://' ) === 0
					? str_replace( 'https:', 'http:', M_get_registrationcompleted_permalink() )
					: M_get_registrationcompleted_permalink();
			}
		}

		echo json_encode( $this->_payment_result );
		exit;
	}

	/**
	 * Processes non serial level purchase.
	 *
	 * @since 3.5
	 *
	 * @access private
	 * @global array $M_options The array of plugin options.
	 * @param array $price The array with current price information.
	 * @param DateTime $date The date when to process this transaction.
	 * @param int $index The index in queue of levels.
	 */
	private function _process_nonserial_purchase( $price, $date, $index ) {
		global $M_options;

		if ( $price['amount'] == 0 ) {
			$this->_payment_result['status'] = 'success';
			return;
		}

		$aim = $this->_get_aim();
		$aim->amount = $amount = number_format( $price['amount'], 2, '.', '' );
		if ( $index == 0 ) {
			// this level is the first in the queue, so just perform simple AIM payment
			$response = $aim->authorizeAndCapture();
			if ( $response->approved ) {
				$this->_payment_result['status'] = 'success';

				$this->_record_transaction(
					$this->_member->ID,
					$this->_subscription->sub_id(),
					$amount,
					$M_options['paymentcurrency'],
					time(),
					$response->transaction_id,
					'Processed',
					$this->_get_option( 'mode', self::MODE_SANDBOX ) != self::MODE_LIVE ? 'Sandbox' : ''
				);
			} else {
				$this->_payment_result['status'] = 'error';
				$this->_payment_result['errors'][] = __( 'Your payment was declined. Please, check all your details or use a different card.', 'membership' );
			}
		} else {
			// this level is not the first level in the queue, so perform delayed ARB payment
			$response = $aim->authorizeOnly();
			if ( $response->approved ) {
				$this->_payment_result['status'] = 'success';

				$this->_record_transaction(
					$this->_member->ID,
					$this->_subscription->sub_id(),
					$amount,
					$M_options['paymentcurrency'],
					$date->getTimestamp(),
					$response->transaction_id,
					'Future',
					$this->_get_option( 'mode', self::MODE_SANDBOX ) != self::MODE_LIVE ? 'Sandbox' : ''
				);
			} else {
				$this->_payment_result['status'] = 'error';
				$this->_payment_result['errors'][] = __( 'Your payment was declined. Please, check all your details or use a different card.', 'membership' );
			}
		}
	}

	/**
	 * Processes serial level purchase.
	 *
	 * @since 3.5
	 *
	 * @access private
	 * @global array $M_options The array of plugin options.
	 * @param array $price The array with current price information.
	 * @param DateTime $date The date when to process this transaction.
	 */
	private function _process_serial_purchase( $price, $date ) {
		if ( $price['amount'] == 0 ) {
			$this->_payment_result['status'] = 'success';
			return;
		}

		$amount = number_format( $price['amount'], 2, '.', '' );

		$level = new M_Level( $price['level_id'] );
		$name = substr( sprintf(
			$price['type'] == 'finite'
				? __( '%s / %s', 'membership' )
				: __( '%s / %s', 'membership' ),
			$level->level_title(),
			$this->_subscription->sub_name()
		), 0, 50 );

		$subscription = $this->_get_arb_subscription( $price );
		$subscription->name = $name;
		$subscription->amount = $amount;
		$subscription->startDate = $date->format( 'Y-m-d' );
		$subscription->totalOccurrences = 1;

		$arb = $this->_get_arb();
		$response = $arb->createSubscription( $subscription );
		if ( $response->isOk() ) {
			$this->_payment_result['status'] = 'success';
			add_user_meta( $this->_member->ID, $this->gateway . '_subscription_' . $this->_subscription->sub_id(), $response->getSubscriptionId() );
		} else {
			$this->_payment_result['status'] = 'error';
			$this->_payment_result['errors'][] = __( 'Your payment was declined. Please, check all your details or use a different card.', 'membership' );
		}
	}

	/**
	 * Initializes and returns AuthorizeNetAIM object.
	 *
	 * @since 3.5
	 *
	 * @access private
	 * @return AuthorizeNetAIM The instance of AuthorizeNetAIM class.
	 */
	private function _get_aim() {
		require_once MEMBERSHIP_ABSPATH . '/classes/Authorize.net/AuthorizeNet.php';

		// merchant information
		$login_id = $this->_get_option( 'api_user' );
		$transaction_key = $this->_get_option( 'api_key' );
		$mode = $this->_get_option( 'mode', self::MODE_SANDBOX );

		// card information
		$card_number = preg_replace( '/\D/', '', filter_input( INPUT_POST, 'card_num' ) );
		$card_code = trim( filter_input( INPUT_POST, 'card_code' ) );
		$expire_date = sprintf( '%02d/%02d', filter_input( INPUT_POST, 'exp_month', FILTER_VALIDATE_INT ), substr( filter_input( INPUT_POST, 'exp_year', FILTER_VALIDATE_INT ), -2 ) );

		// billing information
		$address = trim( filter_input( INPUT_POST, 'address' ) );
		$first_name = trim( filter_input( INPUT_POST, 'first_name' ) );
		$last_name = trim( filter_input( INPUT_POST, 'last_name' ) );
		$zip = trim( filter_input( INPUT_POST, 'zip' ) );

		// create new AIM
		$aim = new AuthorizeNetAIM( $login_id, $transaction_key );
		$aim->setSandbox( $mode != self::MODE_LIVE );

		$aim->card_num = $card_number;
		$aim->card_code = $card_code;
		$aim->exp_date = $expire_date;
		$aim->duplicate_window = MINUTE_IN_SECONDS;

		$aim->cust_id = $this->_member->ID;
		$aim->customer_ip = self::_get_remote_ip();

		$aim->first_name = $first_name;
		$aim->last_name = $last_name;
		$aim->address = $address;
		$aim->zip = $zip;

		return $aim;
	}

	/**
	 * Initializes and returns AuthorizeNetARB object.
	 *
	 * @since 3.5
	 *
	 * @access private
	 * @return AuthorizeNetARB The instance of AuthorizeNetARB class.
	 */
	private function _get_arb() {
		require_once MEMBERSHIP_ABSPATH . '/classes/Authorize.net/AuthorizeNet.php';

		// merchant information
		$login_id = $this->_get_option( 'api_user' );
		$transaction_key = $this->_get_option( 'api_key' );
		$mode = $this->_get_option( 'mode', self::MODE_SANDBOX );

		$arb = new AuthorizeNetARB( $login_id, $transaction_key );
		$arb->setSandbox( $mode != self::MODE_LIVE );
		return $arb;
	}

	/**
	 * Initializes and returns AuthorizeNet_Subscription object.
	 *
	 * @since 3.5
	 *
	 * @access private
	 * @return AuthorizeNet_Subscription The instance of AuthorizeNet_Subscription class.
	 */
	private function _get_arb_subscription( $pricing ) {
		require_once MEMBERSHIP_ABSPATH . '/classes/Authorize.net/AuthorizeNet.php';

		// card information
		$card_number = preg_replace( '/\D/', '', filter_input( INPUT_POST, 'card_num' ) );
		$card_code = trim( filter_input( INPUT_POST, 'card_code' ) );
		$expire_date = sprintf( '%04d-%02d', filter_input( INPUT_POST, 'exp_year', FILTER_VALIDATE_INT ), filter_input( INPUT_POST, 'exp_month', FILTER_VALIDATE_INT ) );

		// billing information
		$address = trim( filter_input( INPUT_POST, 'address' ) );
		$first_name = trim( filter_input( INPUT_POST, 'first_name' ) );
		$last_name = trim( filter_input( INPUT_POST, 'last_name' ) );
		$zip = trim( filter_input( INPUT_POST, 'zip' ) );

		// create new subscription
		$subscription = new AuthorizeNet_Subscription();

		switch ( $pricing['unit'] ) {
			case 'd':
				$subscription->intervalLength = $pricing['period'];
				$subscription->intervalUnit = 'days';
				break;
			case 'w':
				$subscription->intervalLength = $pricing['period'] * 7;
				$subscription->intervalUnit = 'days';
				break;
			case 'm':
				$subscription->intervalLength = $pricing['period'];
				$subscription->intervalUnit = 'months';
				break;
			case 'y':
				$subscription->intervalLength = $pricing['period'] * 12;
				$subscription->intervalUnit = 'months';
				break;
		}

		$subscription->creditCardCardNumber = $card_number;
		$subscription->creditCardCardCode = $card_code;
		$subscription->creditCardExpirationDate = $expire_date;

		$subscription->customerId = $this->_member->ID;

		$subscription->billToFirstName = $first_name;
		$subscription->billToLastName = $last_name;
		$subscription->billToAddress = $address;
		$subscription->billToZip = $zip;

		return $subscription;
	}

	/**
	 * Returns gateway option.
	 *
	 * @since 3.5
	 *
	 * @access private
	 * @param string $name The option name.
	 * @param mixed $default The default value.
	 * @return mixed The option value if it exists, otherwise default value.
	 */
	private function _get_option( $name, $default = false ) {
		$key = "{$this->gateway}_{$name}";
		return defined( 'MEMBERSHIP_GLOBAL_TABLES' ) && filter_var( MEMBERSHIP_GLOBAL_TABLES, FILTER_VALIDATE_BOOLEAN )
			? get_site_option( $key, $default )
			: get_option( $key, $default );
	}

	/**
	 * Enqueues scripts.
	 *
	 * @since 3.5
	 * @action wp_enqueue_scripts
	 *
	 * @access public
	 */
	public function enqueue_scripts() {
		if ( membership_is_registration_page() || membership_is_subscription_page() ) {
			wp_enqueue_script( 'membership-authorize', MEMBERSHIP_ABSURL . 'membershipincludes/js/authorizenet.js', array( 'jquery' ), Membership_Plugin::VERSION, true );
			wp_localize_script( 'membership-authorize', 'membership_authorize', array(
				'return_url'        => add_query_arg( 'action', 'processpurchase_' . $this->gateway, admin_url( 'admin-ajax.php', 'https' ) ),
				'payment_error_msg' => __( 'There was an unknown error encountered with your payment. Please contact the site administrator.', 'membership' ),
				'stylesheet_url'    => MEMBERSHIP_ABSURL . 'membershipincludes/css/authorizenet.css',
			) );
		}
	}

}