<?php
/*
Plugin Name: افزونه پرداخت Easy Digital Downloads ایرپول
Plugin URI: https://irpul.ir
Description: افزونه ایرپول Easy Digital Downloads - طراحی شده توسط : <a target="_blank" href="https://irpul.ir">irpul.ir</a>
Version: 1.1
Author: irpul.ir
Author URI: https://irpul.ir
Copyright: 2021 irpul.ir
*/

if (!defined('ABSPATH')) exit;
@session_start();

if (!class_exists('EDD_DP')) :
	final class EDD_DP{
	public function __construct(){
		$this->hooks();
	}

	private function hooks(){
		add_filter('edd_payment_gateways', array($this, 'add_gateway'));
		add_action('edd_dp_cc_form', array($this, 'cc_form'));
		add_action('edd_gateway_dp', array($this, 'process'));
		add_action('init', array($this, 'verify'));
		add_filter('edd_settings_gateways', array($this, 'options'));
	}
	
	public function add_gateway($gateways){
        global $edd_options;


		$gateways['dp'] = array(
			'admin_label'		=>	'پرداخت الکترونیک ایرپول',
			'checkout_label'	=>	$edd_options['text_irpul']
		);
		return $gateways;
	}
	
	public function cc_form(){
		return;
	}
	
	public function process($purchase_data){
		global $edd_options;

		$payment_data = array(
			'price' 		=> $purchase_data['price'], 
			'date' 			=> $purchase_data['date'],
			'user_email' 	=> $purchase_data['post_data']['edd_email'],
			'purchase_key' 	=> $purchase_data['purchase_key'],
			'currency' 		=> $edd_options['currency'],
			'downloads' 	=> $purchase_data['downloads'],
			'cart_details' 	=> $purchase_data['cart_details'],
			'user_info' 	=> $purchase_data['user_info'],
			'status' 		=> 'pending'
		);
		$payment = edd_insert_payment($payment_data);

		if ($payment){
			$_SESSION['dp_pay'] = $payment;
			$_SESSION['dp_am'] 	= (int) $purchase_data['price'];
			$_SESSION['dp_am'] 	= $_SESSION['dp_am'];

			$amount 			= (int) $purchase_data['price'];
			$amount 			= $amount;
			$callback 			= urlencode(add_query_arg('order', 'dp', get_permalink($edd_options['success_page'])));
			$invoice_id 		= $payment;
			
			$products_array = $purchase_data['cart_details'];
			$firstname = $lastname = $products_name 	= '';
			$i 				= 0;
			$count 			= count($products_array);	
			foreach ( $products_array as $product) {
				$products_name .= 'تعداد '. $product['item_number']['quantity'] . ' عدد ' . $product['name'];
				if ($i!=$count-1) {	
					$products_name .= ' | ';
				}
				$i++;
			}
			
			if(!empty($purchase_data['post_data']['edd_email']))	$email =  $purchase_data['post_data']['edd_email'];
			if(!empty($purchase_data['post_data']['edd_first'])) 	$firstname = $purchase_data['post_data']['edd_first'];
			if(!empty($purchase_data['post_data']['edd_last']))		$lastname = $purchase_data['post_data']['edd_last'];

			$parameters = array(
				//'plugin'		=> 'EasyDigitalDownloads',
				//'webgate_id' 	=> $webgate_id,
				'method' 	    => 'payment',
				'order_id'		=> $invoice_id,
				'product'		=> $products_name,
				'payer_name'	=> $firstname . ' '. $lastname,
				'phone' 		=> '',
				'mobile' 		=> '',
				'email' 		=> $email,
				'amount' 		=> $amount,
				'callback_url' 	=> $callback,
				'address' 		=> '',
				'description' 	=> '',
				'test_mode' 	=> false,
			);

			$token 		= $edd_options['token_irpul'];
			$result 	= post_data('https://irpul.ir/ws.php', $parameters, $token );

			if( isset($result['http_code']) ){
				$data =  json_decode($result['data'],true);

				if( isset($data['code']) && $data['code'] === 1){
					header("Location: " . $data['url']);
					exit;
				}
				else{
					echo 'Error: ' . $data['code'] . '<br/> ' . $data['status'] . "<br/><a href='" . get_permalink($edd_options['failure_page']) . "' >continue</a>" ;
					edd_update_payment_status($payment, 'failed' );
					//wp_redirect(get_permalink($edd_options['failure_page']));
					exit;
				}
			}else{
				echo 'پاسخی از سرویس دهنده دریافت نشد. لطفا دوباره تلاش نمائید';
				echo 'پاسخی از سرویس دهنده دریافت نشد. لطفا دوباره تلاش نمائید' . "<br/><a href='" . get_permalink($edd_options['failure_page']) . "' >continue</a>" ;
				edd_update_payment_status($payment, 'failed' );
				//wp_redirect(get_permalink($edd_options['failure_page']));
				exit;
			}
		}
		else {
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		}
	}

	public function verify(){
		global $edd_options;
		if( isset($_GET['order']) && $_GET['order'] == 'dp'){
			$payment = $_SESSION['dp_pay'];
			
			if( isset($_POST['trans_id']) && isset($_POST['order_id']) && isset($_POST['amount']) && isset($_POST['refcode']) && isset($_POST['status']) ){
				$trans_id 	= $_POST['trans_id'];
				$invoiceid2 = $_POST['order_id'];
				$amount 	= $_POST['amount'];
				$refcode	= $_POST['refcode'];
				$status 	= $_POST['status'];
				
				if($status == 'paid'){

					$amount = $_SESSION['dp_am'];
					$parameters = array(
						'method' 	    => 'verify',
						'trans_id' 		=> $trans_id,
						'amount'	 	=> $amount,
					);

					$token  = $edd_options['token_irpul'];

					$result =  post_data('https://irpul.ir/ws.php', $parameters, $token );

					if( isset($result['http_code']) ){
						$data =  json_decode($result['data'],true);

						if( isset($data['code']) && $data['code'] === 1){
							$irpul_amount  = $data['amount'];

							if($amount == $irpul_amount){
								edd_update_payment_status($payment, 'complete');
								edd_empty_cart();
								edd_send_to_success_page();
							}
							else{
								$error_msg = 'مبلغ تراکنش در ایرپول (' . number_format($irpul_amount) . ' تومان) تومان با مبلغ تراکنش در سیمانت (' . number_format($amount) . ' تومان) برابر نیست';
								edd_update_payment_status($payment, 'failed');
								echo '<html><head><meta charset="utf-8"><meta http-equiv="refresh" CONTENT="5; url=' . get_permalink($edd_options['failure_page']) . '"></head><body>Error: ' . $error_msg . "<br/><a href='" . get_permalink($edd_options['failure_page']) . "' >ادامه</a></body></html>" ;
								exit;
							}
						}
						else{
							edd_update_payment_status($payment, 'failed');
							echo '<html><head><meta charset="utf-8"><meta http-equiv="refresh" CONTENT="5; url=' . get_permalink($edd_options['failure_page']) . '"></head><body>Error: ' . 'خطا در پرداخت. کد خطا: ' . $data['code'] . '\r\n ' . $data['status'] . "<br/><a href='" . get_permalink($edd_options['failure_page']) . "' >ادامه</a></body></html>" ;
							//wp_redirect(get_permalink($edd_options['failure_page']));
							exit;
						}
					}else{
						edd_update_payment_status($payment, 'failed');
						echo '<html><head><meta charset="utf-8"><meta http-equiv="refresh" CONTENT="5; url=' . get_permalink($edd_options['failure_page']) . '"></head><body>Error: ' . "پاسخی از سرویس دهنده دریافت نشد. لطفا دوباره تلاش نمائید" . "<br/><a href='" . get_permalink($edd_options['failure_page']) . "' >ادامه</a></body></html>" ;
						//wp_redirect(get_permalink($edd_options['failure_page']));
						exit;
					}
				}
				else{
					edd_update_payment_status($payment, 'failed');
					wp_redirect(get_permalink($edd_options['failure_page']));
					exit;	
				}
			}
			else{
				edd_update_payment_status($payment, 'failed');
				echo '<html><head><meta charset="utf-8"><meta http-equiv="refresh" CONTENT="5; url=' . get_permalink($edd_options['failure_page']) . '"></head><body>Error: ' . "undefined callback parameters" . "<br/><a href='" . get_permalink($edd_options['failure_page']) . "' >ادامه</a></body></html>" ;
				exit;
			}				
		}			
	}

	public function options($settings){
         $dp_settings = array (
			array (
				'id'	=>		'dp_settings',
				'name'	=>		'<strong>تنظیمات ایرپول</strong>',
				'desc'	=>		'تنظیمات ایرپول',
				'type'	=>		'header'
			),
			array (
				'id'	=>		'token_irpul',
				'name'	=>		'توکن ایرپول ',
				'type'	=>		'text',
				'size'	=>		'regular'
			),
            array (
				'id'	=>		'text_irpul',
				'name'	=>		'عنوان درگاه :',
				'type'	=>		'text',
				'size'	=>		'regular'
			)               
		);
		return array_merge($settings, $dp_settings);
	}
}
endif;

if( !function_exists( 'edd_rial' ) ){
	function edd_rial( $formatted, $currency, $price ){
		return $price . ' ریال'; 
	}
	add_filter( 'edd_rial_currency_filter_after', 'edd_rial', 10, 3 );
}

function post_data($url,$params,$token) {
	ini_set('default_socket_timeout', 15);

	$headers = array(
		"Authorization: token= {$token}",
		'Content-type: application/json'
	);

	$handle = curl_init($url);
	curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
	curl_setopt($handle, CURLOPT_TIMEOUT, 40);
	curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($params) );
	curl_setopt($handle, CURLOPT_HTTPHEADER, $headers );

	$response = curl_exec($handle);
	//error_log('curl response1 : '. print_r($response,true));

	$msg='';
	$http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));

	$status= true;

	if ($response === false) {
		$curl_errno = curl_errno($handle);
		$curl_error = curl_error($handle);
		$msg .= "Curl error $curl_errno: $curl_error";
		$status = false;
	}

	curl_close($handle);//dont move uppder than curl_errno

	if( $http_code == 200 ){
		$msg .= "Request was successfull";
	}
	else{
		$status = false;
		if ($http_code == 400) {
			$status = true;
		}
		elseif ($http_code == 401) {
			$msg .= "Invalid access token provided";
		}
		elseif ($http_code == 502) {
			$msg .= "Bad Gateway";
		}
		elseif ($http_code >= 500) {// do not wat to DDOS server if something goes wrong
			sleep(2);
		}
	}

	$res['http_code'] 	= $http_code;
	$res['status'] 		= $status;
	$res['msg'] 		= $msg;
	$res['data'] 		= $response;

	if(!$status){
		//error_log(print_r($res,true));
	}
	return $res;
}

new EDD_dp();
