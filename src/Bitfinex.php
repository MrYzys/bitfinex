<?php

namespace Fq;

use GuzzleHttp\Client;

class Bitfinex
{
	const BTC = "tBTCUSD";
	const LTC = "tLTCUSD";
	const ETC = "tETCUSD";
	const ETH = "tETHUSD";
	const RRT = "tRRTUSD";
	const XRP = "tXRPUSD";
	const API_URL="https://api.bitfinex.com/v2";
	const API_TIMEOUT=10;

	private $apikey;
	private $secret;
	private $url = "https://api.bitfinex.com";

	public $start = null;
	public $end = null;
	public $sort = 1;

	public function __construct($apikey = null, $secret = null)
	{
		$this->apikey = $apikey;
		$this->secret = $secret;
	}
	/* Build BFX Headers for v2 API */
	private function buildHeaders($path)
	{
		$nonce		=(string) number_format(round(microtime(true) * 100000), 0, ".", "");
		$body		="{}";
		$signature	="/api/v2".$path["request"].$nonce.$body;
		$h			=hash_hmac("sha384", utf8_encode($signature), utf8_encode($this->secret));
		return array(
			"content-type: application/json",
			"content-length: ".strlen($body),
			"bfx-apikey: ".$this->apikey,
			"bfx-signature: ".$h,
			"bfx-nonce: ".$nonce
		);
	}
	/* Authenticated Endpoints Request */
	private function send_auth_endpoint_request($data) {
		$ch=curl_init();
		$url=self::API_URL.$data["request"];
		$headers=$this->buildHeaders($data);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::API_TIMEOUT);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "{}");
		if(!$result=curl_exec($ch)) {
			return $this->curl_error($ch);
		} else {
			return $this->output($result, $this->is_bitfinex_error($ch), $data);
		}
	}
	/* Public Endpoints Request */
	private function send_public_endpoint_request($request, $params=NULL) {
		$ch=curl_init();
		$query="";
		if (count($params)) {
			$query="?".http_build_query($params);
		}
		$url=self::API_URL . $request . $query;
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::API_TIMEOUT);
		if( !$result=curl_exec($ch) ) {
			return $this->curl_error($ch);
		} else {
			return $this->output($result, $this->is_bitfinex_error($ch), $request);
		}
	}
	/* API: Get Tickers - Ugly, need to fix this.*/
	public function get_tickers($symbols) {
		$request=$this->build_url_path("tickers", "?symbols=".implode(",", $symbols));
		$tickers=$this->send_public_endpoint_request($request);
		$t=array();
		for ($z=0; $z<count($tickers); $z++) {
			if (substr($tickers[$z][0], 0, 1)=="t") {
				$t[substr($tickers[$z][0], 1, strlen($tickers[$z][0]))]["last_price"]=$tickers[$z][3];
				$t[substr($tickers[$z][0], 1, strlen($tickers[$z][0]))]["ask"]=$tickers[$z][7];
			} elseif (substr($tickers[$z][0], 0, 1)=="f") {
				$t[substr($tickers[$z][0], 1, strlen($tickers[$z][0]))]["last_price"]=$tickers[$z][5];
				$t[substr($tickers[$z][0], 1, strlen($tickers[$z][0]))]["ask"]=$tickers[$z][10];
			}
		}
		return $t;
	}
	/* API: Get Orders */
	public function get_orders() {
		$request=$this->build_url_path("auth/r/orders");
		$data=array("request" => $request);
		$orders=$this->send_auth_endpoint_request($data);
		$o=array();
		for ($z=0; $z<count($orders); $z++) {
			if (substr($orders[$z][3], 0, 1)=="t") {
				$sym_fix=substr($orders[$z][3], 1, strlen($orders[$z][3]));
				$o[$orders[$z][0]]["symbol"]=$sym_fix;
				$o[$orders[$z][0]]["type"]=$orders[$z][8];
				$o[$orders[$z][0]]["amount"]=$orders[$z][6];
				$o[$orders[$z][0]]["amount_orig"]=$orders[$z][7];
				$o[$orders[$z][0]]["price"]="$".$orders[$z][16];
				$o[$orders[$z][0]]["order_status"]=$orders[$z][13];
			}
		}
		return $o;
	}

	public function order($id) {
		$request=$this->build_url_path("/auth/r/order/tBTCUSD:{$id}/trades");
		$data=array("request" => $request);
		$orders=$this->send_auth_endpoint_request($data);
		return $orders;
	}

	/* API: Get Orders - only handling exchange balances right now */
	public function get_balances() {
		$request=$this->build_url_path("auth/r/wallets");
		$data=array("request" => $request);
		$balances=$this->send_auth_endpoint_request($data);
		$b=array();
		$count=0;
		for ($z=0; $z<count($balances); $z++) {
			if ($balances[$z][0]=="exchange") {
				if ($balances[$z][2]!="0") {
					$b[$count]["currency"]=$balances[$z][1];
					$b[$count]["amount"]=$balances[$z][2];
					$count++;
				}
			}
		}
		return $b;
	}
	/* Handle CURL errors */
	private function curl_error($ch) {
		if ($errno=curl_errno($ch)) {
			$error_message=curl_strerror($errno);
			echo "CURL err ({$errno}): {$error_message}";
			return false;
		}
		return true;
	}
	/* Handle Bitfinex errors - but may move this to output...*/
	private function is_bitfinex_error($ch) {
		$http_code=(int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($http_code !== 200) {
			return true;
		}

		return false;
	}
	/* Retrieve CURL response, if API err or RATE LIMIT hit, recall routine. Need to implement max retries. */
	private function output($result, $is_error=false, $command) {
		$response=json_decode($result, true);
		if ((isset($response[0])&&$response[0]=="error") || (isset($response["error"]) && $response["error"]=="ERR_RATE_LIMIT")) {
			if (!is_array($command)) {
				//echo "Retrying... '".$command."' in 10 seconds.\n";
				sleep(10);
				return $this->send_public_endpoint_request($command);
			} else {
				//echo "Retrying... '".$command["request"]."' in 10 seconds.\n";
				sleep(10);
				return $this->send_auth_endpoint_request($command);
			}
		} else {
			if ($is_error) {
				$response["error"]=true;
			}
			return $response;
		}
	}
	/* Build URL path from functions */
	private function build_url_path($method, $params=NULL) {
		$parameters="";
		if ($params !== NULL) {
			$parameters="/";
			if (is_array($params)) {
				$parameters .= implode("/", $params);
			} else {
				$parameters .= $params;
			}
		}
		return "/$method$parameters";
	}

	public function getTrades($coinType = self::BTC, $limit = 100)
	{
		$url = "{$this->url}/v2/trades/{$coinType}/hist?limit={$limit}";
		return $this->json_get_v2($url);
	}

	public function getCandle($coinType = self::BTC, $step = "5m", $limit = 100, $param = [])
	{
		$url = "{$this->url}/v2/candles/trade:{$step}:{$coinType}/hist?limit={$limit}&sort={$this->sort}";
		if ($this->start !== null && $this->end !== null && $this->end > $this->start) {
			$url = "{$url}&start={$this->start}&end={$this->end}";
		}
		return $this->json_get_v2($url);
	}

	private function json_get_v2($url)
	{
		$http = new Client();
		$response = $http->get($url);
		$body = $response->getBody();
		if ($body->isReadable()) {
			$res_json = $body->read($body->getSize());
			$res_data = json_decode($res_json);
		} else {
			$res_data = [];
		}
		return $res_data;
	}

	public function new_order($symbol, $amount, $price, $side, $type)
	{
		$request = "/v1/order/new";
		$data = array(
			"request" => $request,
			"symbol" => $symbol,
			"amount" => $amount,
			"price" => $price,
			"exchange" => "bitfinex",
			"side" => $side,
			"type" => $type
		);
		return $this->hash_request($data);
	}

	/*
	response
	{
   "id":448364249,
   "symbol":"btcusd",
   "exchange":"bitfinex",
   "price":"0.01",
   "avg_execution_price":"0.0",
   "side":"buy",
   "type":"exchange limit",
   "timestamp":"1444272165.252370982",
   "is_live":true,
   "is_cancelled":false,
   "is_hidden":false,
   "was_forced":false,
   "original_amount":"0.01",
   "remaining_amount":"0.01",
   "executed_amount":"0.0",
   "order_id":448364249
	}
	*/

	public function cancel_order($order_id)
	{
		$request = "/v1/order/cancel";
		$data = array(
			"request" => $request,
			"order_id" => (int)$order_id
		);
		return $this->hash_request($data);
	}

	public function order_status($order_id)
	{
		$request = "/v1/order/status";
		$data = array(
			"request" => $request,
			"order_id" => (int)$order_id
		);
		return $this->hash_request($data);
	}

	public function cancel_all()
	{
		$request = "/v1/order/cancel/all";
		$data = array(
			"request" => $request
		);
		return $this->hash_request($data);
	}

	public function order_history()
	{
		$request = "/v1/order/hist";
		$data = array(
			"request" => $request
		);
		return $this->hash_request($data);
	}

	public function active_orders()
	{
		$request = "/v1/orders";
		$data = array(
			"request" => $request
		);
		return $this->hash_request($data);
	}

	public function account_info()
	{
		$request = "/v1/account_infos";
		$data = array(
			"request" => $request
		);
		return $this->hash_request($data);
	}

	/*
	response
	[{
   "maker_fees":"0.1",
   "taker_fees":"0.2",
	 "fees":[{
	 "pairs":"BTC",
	 "maker_fees":"0.1",
	 "taker_fees":"0.2"
	},{
	 "pairs":"LTC",
	 "maker_fees":"0.1",
	 "taker_fees":"0.2"
	},
	{
	 "pairs":"DRK",
	 "maker_fees":"0.1",
	 "taker_fees":"0.2"
	  }]
	}]
	*/

	public function deposit($method, $wallet, $renew)
	{
		$request = "/v1/deposit/new";
		$data = array(
			"request" => $request,
			"method" => $method,
			"wallet_name" => $wallet,
			"renew" => $renew
		);
		return $this->hash_request($data);
	}

	/*
	depost generates a BTC address to deposit funds into bitfinex
	example: deposit("bitcoin", "trading", $renew);
	$renew will generate a new fresh deposit address if set to 1, default is 0
	//response
	{
   "result":"success",
   "method":"bitcoin",
   "currency":"BTC",
   "address":"3FdY9coNq47MLiKhG2FLtKzdaXS3hZpSo4"
	}
	*/

	public function positions()
	{
		$request = "/v1/positions";
		$data = array(
			"request" => $request
		);
		return $this->hash_request($data);
	}

	/*
	response
	[{
   "id":943715,
   "symbol":"btcusd",
   "status":"ACTIVE",
   "base":"246.94",
   "amount":"1.0",
   "timestamp":"1444141857.0",
   "swap":"0.0",
   "pl":"-2.22042"
	}]
	*/

	public function close_position($position_id)
	{
		$request = "/v1/position/close";
		$data = array(
			"request" => $request,
			"position_id" => (int)$position_id
		);
		return $this->hash_request($data);
	}

	public function claim_position($position_id, $amount)
	{
		$request = "/v1/position/claim";
		$data = array(
			"request" => $request,
			"position_id" => (int)$position_id,
			"amount" => $amount
		);
		return $this->hash_request($data);
	}

	/*
	response
	{
   "id":943715,
   "symbol":"btcusd",
   "status":"ACTIVE",
   "base":"246.94",
   "amount":"1.0",
   "timestamp":"1444141857.0",
   "swap":"0.0",
   "pl":"-2.2304"
	}
	*/

	public function fetch_balance()
	{
		$request = "/v1/balances";
		$data = array(
			"request" => $request
		);
		return $this->hash_request($data);
	}

	/*
	response
	[{
   "type":"deposit",
   "currency":"btc",
   "amount":"0.0",
   "available":"0.0"
   },{
   "type":"deposit",
   "currency":"usd",
   "amount":"1.0",
   "available":"1.0"
   },{
   "type":"exchange",
   "currency":"btc",
   "amount":"1",
   "available":"1"
   },{
   "type":"exchange",
   "currency":"usd",
   "amount":"1",
   "available":"1"
   },{
   "type":"trading",
   "currency":"btc",
   "amount":"1",
   "available":"1"
   },{
   "type":"trading",
   "currency":"usd",
   "amount":"1",
   "available":"1"
	}]
	*/

	public function margin_infos()
	{
		$request = "/v1/margin_infos";
		$data = array(
			"request" => $request
		);
		return $this->hash_request($data);
	}

	/*
	[{
   "margin_balance":"14.80039951",
   "tradable_balance":"-12.50620089",
   "unrealized_pl":"-0.18392",
   "unrealized_swap":"-0.00038653",
   "net_value":"14.61609298",
   "required_margin":"7.3569",
   "leverage":"2.5",
   "margin_requirement":"13.0",
   "margin_limits":[{
	 "on_pair":"BTCUSD",
	 "initial_margin":"30.0",
	 "margin_requirement":"15.0",
	 "tradable_balance":"-0.329243259666666667"
	}]
	*/

	public function transfer($amount, $currency, $from, $to)
	{
		$request = "/v1/transfer";
		$data = array(
			"request" => $request,
			"amount" => $amount,
			"currency" => $currency,
			"walletfrom" => $from,
			"walletto" => $to
		);
		return $this->hash_request($data);
	}

	/*
	response
	[{
   "status":"success",
   "message":"1.0 USD transfered from Exchange to Deposit"
	}]
	*/

	private function headers($data)
	{
		$data["nonce"] = strval(round(microtime(true) * 10, 0));
		$payload = base64_encode(json_encode($data));
		$signature = hash_hmac("sha384", $payload, $this->secret);
		return array(
			"X-BFX-APIKEY: " . $this->apikey,
			"X-BFX-PAYLOAD: " . $payload,
			"X-BFX-SIGNATURE: " . $signature
		);
	}

	private function hash_request($data)
	{
		$ch = curl_init();
		$bfurl = $this->url . $data["request"];
		$headers = $this->headers($data);
		curl_setopt_array($ch, array(
			CURLOPT_URL => $bfurl,
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_POSTFIELDS => ""
		));
		$ccc = curl_exec($ch);
		return json_decode($ccc, true);
	}

}

?>

