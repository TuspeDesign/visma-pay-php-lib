<?php
require dirname(__FILE__) . '/lib/visma_pay_loader.php';

$vismaPay = new VismaPay\VismaPay('349428160e25b1536c3a5d91b4ef5de8049e', '7b840413f08ed44ea5a8610412d463bd');

$payment_return = '';

if(isset($_GET['action']))
{
	if($_GET['action'] == 'auth-payment')
	{
		$serverPort = (isset($_SERVER['SERVER_PORT']) && ($_SERVER['SERVER_PORT'] != 80 &&  $_SERVER['SERVER_PORT'] != 433)) ? ':' . $_SERVER['SERVER_PORT'] : '';

		$returnUrl = strstr("http" . (!empty($_SERVER['HTTPS']) ? "s" : "") . "://" . $_SERVER['SERVER_NAME'] . $serverPort . $_SERVER['REQUEST_URI'], '?', true)."?return-from-pay-page";

		$method = isset($_GET['method']) ? $_GET['method'] : '';

		if($result = $db->query("SELECT order_price_total, order_customer, order_products, order_shipping FROM tuspe_reports WHERE order_id = ". $paymentInfo["order"] ." LIMIT 1")){
			while ($row = $result->fetch_assoc()) {

				$orderNumber = time() ."_". $paymentInfo["order"];

				$vismaPay->addCharge(array(
					'order_number' => $orderNumber,
					'amount' => $row["order_price_total"] * 100,
					'currency' => 'EUR'
				));

				$customer = json_decode($row["order_customer"]);
				$vismaPay->addCustomer([
					"firstname" => $customer->name1,
					"lastname" => $customer->name2,
					"phone" => $customer->phone,
					"email" => $customer->email,
					"address_street" => $customer->street,
					"address_zip" => $customer->postal,
					"address_city" => $customer->area,
				]);

				$products = json_decode(stripslashes($row["order_products"]));
				foreach($products as $p){
					$vismaPay->addProduct([
						'id' => $p->id,
						'title' => $p->title,
						'count' => $p->amount,
						'pretax_price' => $p->pricePretax * 100,
						'tax' => $p->priceVat * 100,
						'price' => $p->priceCurrent * 100,
						'type' => 1
					]);
				}

				if($row["order_shipping"]){

					$p = json_decode(stripslashes($row["order_shipping"]));
					$vismaPay->addProduct([
						'id' => $p->id ? $p->id : "toimitus",
						'title' => $p->title,
						'count' => 1,
						'pretax_price' => $p->pricePretax * 100,
						'tax' => $p->priceVat * 100,
						'price' => $p->price * 100,
						'type' => 1
					]);

				}

			}
		}

		if($method === 'iframe')
			$returnUrl .= '&iframe';

		$paymentMethod = array(
			'return_url' => $page->httpUrl ."payment/return",
			'notify_url' => $page->httpUrl ."payment/notify",
			'lang' => $lang ? $lang : 'fi'
		);

		if($method === 'embedded')
			$paymentMethod['type'] = 'embedded';
		else
			$paymentMethod['type'] = 'e-payment';

		if(isset($_GET['selected']))
		{
			$paymentMethod['selected'] = array(strip_tags($_GET['selected']));
		}

		$vismaPay->addPaymentMethod($paymentMethod);

		try
		{
			$result = $vismaPay->createCharge();

			if($result->result == 0)
			{
				if($method === 'iframe')
				{
					header('Cache-Control: no-cache');
					echo json_encode(array(
						'url' => $vismaPay::API_URL . '/token/' . $result->token
					));
				}
				else if($method === 'embedded')
				{
					echo json_encode(array(
						'token' => $result->token
					));
				}
				else
				{
					$data = [
						"redirect" => $vismaPay::API_URL . '/token/' . $result->token,
						"vismaOrder" => $orderNumber
					];
					head(0, $data);
				}
			}
			else
			{
				$error_msg = 'Unable to create a payment. ';

				if(isset($result->errors) && !empty($result->errors))
				{
					$error_msg .= 'Validation errors: ' . print_r($result->errors, true);
				}
				else
				{
					$error_msg .= 'Please check that api key and private key are correct.';
				}

				exit($error_msg);
			}
		}
		catch(VismaPay\VismaPayException $e)
		{
			exit('Got the following exception: ' . $e->getMessage());
		}
	}

	exit();
}
else if(isset($_GET["RETURN_CODE"]))
{
	$code = (int)$_GET["RETURN_CODE"];
	$id = (int)explode("_", strip_tags($_GET["ORDER_NUMBER"]))[1];

	if(!is_numeric($id) || $id == 0 || $code != 0) header("Location: /kassa?failed=". $id);
	else {

		$orderId = $url[3] ? (int)$url[3] : $paymentInfo["order"];
		$db->query("UPDATE tuspe_reports SET `status` = 1, orderTime = '". date("Y-m-d H:i:s") ."' WHERE `orderId` = ". $orderId  ." LIMIT 1");
		header("Location: /kassa?id=". $id);

	}

	exit();
}

try
{

	$merchantPaymentMethods = $vismaPay->getMerchantPaymentMethods();

	if($merchantPaymentMethods->result != 0)
	{
		exit('Unable to get the payment methods for the merchant. Please check that api key and private key are correct.');
	}

	foreach ($merchantPaymentMethods->payment_methods as $pm) $data["methods"][] = [
		"name" => $pm->name,
		"value" => $pm->selected_value,
		"link" => "?action=auth-payment&method=button&selected=". $pm->selected_value,
		"min" => $pm->min_amount,
		"max" => $pm->max_amount,
		"img" => $pm->img
	];

}
catch(VismaPay\VismaPayException $e)
{
	exit('Got the following exception: ' . $e->getMessage());
}