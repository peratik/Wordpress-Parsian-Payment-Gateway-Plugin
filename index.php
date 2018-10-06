<?php
/*
Plugin Name: Woocommerce Parsian Payment Gateway
Plugin URI: http://asancared.com
Description: WooCommerce Parsian payment gateway
Version: 1.0
*/

add_filter( 'woocommerce_payment_gateways', 'add_wc_parsian_gateway_class' );
function add_wc_parsian_gateway_class( $methods ) {
	$methods[] = 'WC_PARSIAN_Gateway';
	return $methods;
}

add_filter('plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_parsian_gateway_links' );
function wc_parsian_gateway_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">' . __( 'Settings', 'cwoa-authorizenet-aim' ) . '</a>',
	);
	return array_merge( $plugin_links, $links );
}

add_action('plugins_loaded', 'wc_parsian_init_gateway_class' );
function wc_parsian_init_gateway_class() {
    class WC_PARSIAN_Gateway extends WC_Payment_Gateway {
		function __construct(){
			$this->context = stream_context_create([
				'ssl' => [
					'verify_peer' => false,
					'verify_peer_name' => false,
					'allow_self_signed' => true
				]
			]);	
			$this->id = "wc_parsian_gateway";
			$this->method_title = "پارسیان";
			$this->method_description = "تنظیمات درگاه پرداخت پارسیان برای افزونه فروشگاه ساز ووکامرس";
			
			$this->init_form_fields();
			$this->init_settings();
			
			$this->title = $this->get_option( 'title' );
			$this->pinCode = $this->get_option( 'pinCode' );
			
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action('woocommerce_receipt_'.$this->id.'', array($this, 'send_to_parsian_gateway'));
			add_action('woocommerce_api_'.strtolower(get_class($this)).'', array($this, 'return_from_parsian_gateway') );
		}
		
		function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'woocommerce' ),
					'type' => 'checkbox',
					'label' => __( 'فعال بودن درگاه پارسیان', 'woocommerce' ),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'woocommerce' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default' => __( 'درگاه پارسیان', 'woocommerce' ),
					'desc_tip'      => true,
				),
				'pinCode' => array(
					'title' => __( 'پین کد', 'woocommerce' ),
                    'type' => 'text',
					'default' => ''
				)				
			);
		}
		
		public function process_payment( $order_id ) {
			$order = new WC_Order( $order_id );	
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url(true)
			);
		}
		
		function send_to_parsian_gateway($order_id) {
			global $woocommerce;
			
			$order = new WC_Order( $order_id );
			$currency = $order->get_order_currency();
						
			$pinCode = $this->pinCode;
			$amount = intval($order->order_total);; // مبلغ فاكتور
			$redirectAddress = add_query_arg('wc_order', $order_id , WC()->api_request_url('WC_PARSIAN_Gateway'));

            $client = new SoapClient('https://pec.shaparak.ir/NewIPGServices/Sale/SaleService.asmx?wsdl', ['stream_context' => $this->context, 'cache_wsdl' => WSDL_CACHE_NONE, 'trace'=>true]);       
            $requestData = new ClientSalePaymentRequestData();
            $requestData->LoginAccount = $pinCode;
            $requestData->Amount = (double) $amount;
            $requestData->OrderId = (double) $order_id;
            $requestData->CallBackUrl = $redirectAddress;
            $requestData->AdditionalData = "";
            $requestData = array('requestData'=>$requestData);          
            $res = $client->SalePaymentRequest($requestData)->SalePaymentRequestResult;				
            $token = $res->Token;
            $status = $res->Status;
            if ($token && $status==0)  {
                $parsURL = "https://pec.shaparak.ir/NewIPG/?Token=" . $token ;
                echo "<script type='text/javascript'>window.location.href = '{$parsURL}';</script>";
            } else {
                wc_add_notice("پرداخت ناموفق" , "خطا - وضعیت: $status / کد پیگیری: $token");	
                wp_redirect( $woocommerce->cart->get_checkout_url() );
            }	
		}
		
		function return_from_parsian_gateway($order_id) {
			global $woocommerce;
			$order_id = $_GET['wc_order'];
			$order = new WC_Order($order_id);
			$currency = $order->get_order_currency();
			$amount = intval($order->order_total);; // مبلغ فاكتور
            
            $Token = $_POST["Token"];
            $status = $_POST["status"];
            $pinCode = $this->pinCode;
            if ($status==0 & $Token>0 && $ex==false) {					
                $client = new SoapClient('https://pec.shaparak.ir/NewIPGServices/Confirm/ConfirmService.asmx?wsdl', ['stream_context' => $this->context, 'cache_wsdl' => WSDL_CACHE_NONE, 'trace'=>true]);
                $requestData = array('requestData'=>(object) array(
                    'LoginAccount'=>$pinCode,
                    'Token'=>$Token
                ));
                $res = $client->ConfirmPayment($requestData)->ConfirmPaymentResult;					
                if ($res->Status==0) {
                    $order->payment_complete();
					wc_add_notice("پرداخت با موفقیت انجام شد", 'success' );
					wp_redirect( add_query_arg( 'wc_status', 'success', $this->get_return_url( $order ) ) );
					exit;	
                } else {
                    wc_add_notice("پرداخت ناموفق" , 'error' );	
					wp_redirect( $woocommerce->cart->get_checkout_url() );
					exit;
                }
            } else {
                wc_add_notice("درخواست غیرمعتبر می باشد" , 'error' );	
				wp_redirect( $woocommerce->cart->get_checkout_url() );
				exit;
            }           	
		}
	}
}

if (!class_exists('ClientSalePaymentRequestData')) {
    class ClientSalePaymentRequestData {
        public $LoginAccount;
        public $Amount;
        public $OrderId;
        public $CallBackUrl;
        public $AdditionalData;
    }
}