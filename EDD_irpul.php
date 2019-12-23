<?php
/*
Plugin Name: افزونه پرداخت Easy Digital Downloads ایرپول
Plugin URI: http://irpul.ir
Description: افزونه ایرپول Easy Digital Downloads - طراحی شده توسط : <a target="_blank" href="http://omidtak.ir">امید آران</a>
Version: 1.0
Author: Omid Aran
Author URI: http://omidtak.ir
Copyright: 2016 irpul.ir
*/

if (!defined('ABSPATH')) exit;
@session_start();
if (!class_exists('EDD_DP')) :
final class EDD_DP
{
	public function __construct()
	{
		$this->hooks();
	}

	private function hooks() 
	{
		add_filter('edd_payment_gateways', array($this, 'add_gateway'));
		add_action('edd_dp_cc_form', array($this, 'cc_form'));
		add_action('edd_gateway_dp', array($this, 'process'));
		add_action('init', array($this, 'verify'));
		add_filter('edd_settings_gateways', array($this, 'options'));
	}
	
	public function add_gateway($gateways) 
	{
        global $edd_options;
		$gateways['dp'] = array(
				'admin_label'		=>	'سامانه الکترونیکی ایرپول',
				'checkout_label'	=>	$edd_options['dp_text']
		);
		return $gateways;
	}
	
	public function cc_form()
	{
		return;
	}
	
	public function process($purchase_data) 
	{
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

			$webgate_id 		= $edd_options['api_irpul'];
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
				'plugin'		=> 'EasyDigitalDownloads',
				'webgate_id' 	=> $webgate_id,
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
			);
			//print_r($parameters);exit;
			try {
				$client = new SoapClient('https://irpul.ir/webservice.php?wsdl' , array('soap_version'=>'SOAP_1_2','cache_wsdl'=>WSDL_CACHE_NONE ,'encoding'=>'UTF-8'));
				$result = $client->Payment($parameters);
				//print_r($result);exit;
			}catch (Exception $e) { echo 'Error'. $e->getMessage();  }
			
			if( $result['res_code']===1 ){
				header("Location: " . $result['url']);
				exit;
			}else {
				echo 'Error: ' . $result['res_code'] . "<br/><a href='" . get_permalink($edd_options['failure_page']) . "' >continue</a>" ;
				edd_update_payment_status($payment, 'failed' );
				//wp_redirect(get_permalink($edd_options['failure_page']));
				exit;
			}				
		}
		else {
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		}
		
	}
	
	public function verify() 
	{
		 function url_decrypt($string){
			$counter = 0;
			$data = str_replace(array('-','_','.'),array('+','/','='),$string);
			$mod4 = strlen($data) % 4;
			if ($mod4) {
			$data .= substr('====', $mod4);
			}
			$decrypted = base64_decode($data);
			
			$check = array('tran_id','order_id','amount','refcode','status');
			foreach($check as $str){
				str_replace($str,'',$decrypted,$count);
				if($count > 0){
					$counter++;
				}
			}
			if($counter === 5){
				return array('data'=>$decrypted , 'status'=>true);
			}else{
				return array('data'=>'' , 'status'=>false);
			}
		}
		
		global $edd_options;
		if($_GET['order'] == 'dp') 
		{
			$payment = $_SESSION['dp_pay'];
			
			$irpul_token 	= $_GET['irpul_token'];
			$decrypted 		= url_decrypt( $irpul_token );
			if($decrypted['status']){
				parse_str($decrypted['data'], $ir_output);
				$tran_id 	= $ir_output['tran_id'];
				$invoiceid2 = $ir_output['order_id'];
				$amount 	= $ir_output['amount'];
				$refcode	= $ir_output['refcode'];
				$status 	= $ir_output['status'];
				
				if($status == 'paid')	
				{
					$api = $edd_options['api_irpul'];
					$amount = $_SESSION['dp_am'];					
					$parameters = array
					(
						'webgate_id'	=> $api,
						'tran_id' 		=> $tran_id,
						'amount'	 	=> $amount,
					);
					try {
						$client = new SoapClient('https://irpul.ir/webservice.php?wsdl' , array('soap_version'=>'SOAP_1_2','cache_wsdl'=>WSDL_CACHE_NONE ,'encoding'=>'UTF-8'));
						$result = $client->PaymentVerification($parameters);
					}catch (Exception $e) { echo 'Error'. $e->getMessage();  }

					if(!empty($result) and $result==1){
						edd_update_payment_status($payment, 'complete');
						edd_empty_cart();
						edd_send_to_success_page();	
					}
					else
					{
						edd_update_payment_status($payment, 'failed');
						echo '<html><head><meta charset="utf-8"><meta http-equiv="refresh" CONTENT="5; url=' . get_permalink($edd_options['failure_page']) . '"></head><body>Error: ' . $result . "<br/><a href='" . get_permalink($edd_options['failure_page']) . "' >ادامه</a></body></html>" ;
						//wp_redirect(get_permalink($edd_options['failure_page']));
						exit;
					}
				}
				else{
					edd_update_payment_status($payment, 'failed');
					wp_redirect(get_permalink($edd_options['failure_page']));
					exit;	
				}	
			}else{
				edd_update_payment_status($payment, 'failed');
				wp_redirect(get_permalink($edd_options['failure_page']));
				exit;	
			}
		}			
	}

	public function options($settings) 
	{
         $dp_settings = array (
			array (
				'id'	=>		'dp_settings',
				'name'	=>		'<strong>تنظیمات ایرپول</strong>',
				'desc'	=>		'تنظیمات ایرپول',
				'type'	=>		'header'
			),
			array (
				'id'	=>		'api_irpul',
				'name'	=>		'شناسه درگاه ',
				'type'	=>		'text',
				'size'	=>		'regular'
			),
            array (
				'id'	=>		'dp_text',
				'name'	=>		'عنوان درگاه :',
				'type'	=>		'text',
				'size'	=>		'regular'
			)               
		);
		return array_merge($settings, $dp_settings);
	}
}
endif;

if ( !function_exists( 'edd_rial' ) )
{
	function edd_rial( $formatted, $currency, $price ) 
	{
		return $price . ' ریال'; 
	}
	add_filter( 'edd_rial_currency_filter_after', 'edd_rial', 10, 3 );
}

new EDD_dp();
