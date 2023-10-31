<?php

namespace App\Http\Controllers;

use App\CPU\CartManager;
use App\CPU\Helpers;
use App\CPU\OrderManager;
use App\Model\Order;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class RedoController extends Controller
{
    //payment functions
    public function payment(Request $request)
    {
        $order_id = Order::orderBy('id', 'DESC')->first()->id ?? 100001;
        $discount = session()->has('coupon_discount') ? session('coupon_discount') : 0;
        $value = CartManager::cart_grand_total() - $discount;
        $user = Helpers::get_customer();

		$config = Helpers::get_business_settings('redo_pay');
		
        $paramList = array();
        $ORDER_ID = $order_id;
        $CUST_ID = $user['id'];
        $TXN_AMOUNT = round($value, 2);

		$api_id = $request['api_key'];
		$api_pass = $config['password'];
		
		$curl = curl_init();

		curl_setopt_array($curl, array(
		  CURLOPT_URL => 'https://app.redopay.in/api/login',
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'POST',
		  CURLOPT_POSTFIELDS => array('email' => "$api_id",'password' => "$api_pass"),
		));

		$response = curl_exec($curl);

		curl_close($curl);
		$response = (array) json_decode($response);
		$response = (array) $response['data'];
		
		$token = $response['token'];
		
		$curl = curl_init();

		curl_setopt_array($curl, array(
		  CURLOPT_URL => 'https://app.redopay.in/api/collections/upi',
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'POST',
		  CURLOPT_POSTFIELDS => array('amount' => "$TXN_AMOUNT",'transactionId' => "$ORDER_ID"),
		  CURLOPT_HTTPHEADER => array(
			'Authorization: Bearer '.$token
		  ),
		));

		$response = curl_exec($curl);

		curl_close($curl);
		echo $response;
    }

    public function callback(Request $request)
    {
        $paramList = $_POST;
        $paytmChecksum = isset($_POST["CHECKSUMHASH"]) ? $_POST["CHECKSUMHASH"] : ""; //Sent by Paytm pg

        //Verify all parameters received from Paytm pg to your application. Like MID received from paytm pg is same as your application’s MID, TXN_AMOUNT and ORDER_ID are same as what was sent by you to Paytm PG for initiating transaction etc.
        $isValidChecksum = $this->verifychecksum_e($paramList, Config::get('config_paytm.PAYTM_MERCHANT_KEY'), $paytmChecksum); //will return TRUE or FALSE string.

        if ($isValidChecksum == "TRUE") {
            if ($request["STATUS"] == "TXN_SUCCESS") {
                $unique_id = OrderManager::gen_unique_id();
                $order_ids = [];
                foreach (CartManager::get_cart_group_ids() as $group_id) {
                    $data = [
                        'payment_method' => 'paytm',
                        'order_status' => 'confirmed',
                        'payment_status' => 'paid',
                        'transaction_ref' => 'trx_' . $unique_id,
                        'order_group_id' => $unique_id,
                        'cart_group_id' => $group_id
                    ];
                    $order_id = OrderManager::generate_order($data);
                    array_push($order_ids, $order_id);
                }

                if (session()->has('payment_mode') && session('payment_mode') == 'app') {
                    CartManager::cart_clean();
                    return redirect()->route('payment-success');
                } else {
                    CartManager::cart_clean();
                    return view('web-views.checkout-complete');
                }
            }
        }

        if (session()->has('payment_mode') && session('payment_mode') == 'app') {
            return redirect()->route('payment-fail');
        }
        Toastr::error('Payment process failed!');
        return back();
    }
}
