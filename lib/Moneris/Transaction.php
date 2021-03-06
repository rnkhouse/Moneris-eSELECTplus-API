<?php 
/**
 * Mostly takes care of validation.
 */
class Moneris_Transaction
{

	/**
	 * @var array
	 */
	protected $_errors = array();
	
	/**
	 * @var Moneris
	 */
	protected $_gateway;
	
	/**
	 * @var array
	 */
	protected $_params;
	
	/**
	 * @var SimpleXMLElement
	 */
	protected $_response;
	
	/**
	 * The result object for this transaction.
	 * @var Moneris_Result
	 */
	protected $_result = null;
	
	/**
	 * @param Moneris_Gateway $gateway
	 */
	public function __construct(Moneris_Gateway $gateway, array $params = array(), $prepare_params = true)
	{
		$this->gateway($gateway);
		$this->_params = $prepare_params ? $this->prepare($params) : $params;
	}
	
	/**
	 * The amount for this transaction.
	 * Only available for some transaction types.
	 *
	 * @return string|null
	 */
	public function amount()
	{
		if (isset($this->_params['amount']))
			return $this->_params['amount'];
		return null;
	}
	
	/**
	 * Check that required params have been provided.
	 *
	 * @return bool
	 */
	public function is_valid()
	{
		$params = $this->_params;
		$errors = array();
		
		if (empty($params))
			$errors[] = 'No params provided.';
		
		if (isset ($params['type'])) {
			switch ($params['type']) {
				case 'purchase':
				case 'preauth':
				case 'card_verification':
				
					if (! isset($params['order_id'])) $errors[] = 'Order ID not provided';
					if (! isset($params['pan'])) $errors[] = 'Credit card number not provided';
					if (! isset($params['amount'])) $errors[] = 'Amount not provided';
					if (! isset($params['expdate'])) $errors[] = 'Expiry date not provided';
					
					if ($this->gateway()->check_avs()) {
						
						if (! isset($params['avs_street_number'])) $errors[] = 'Street number not provided';
						if (! isset($params['avs_street_name'])) $errors[] = 'Street name not provided';
						if (! isset($params['avs_zipcode'])) $errors[] = 'Zip/postal code not provided';
						
						//@TODO email is Amex/JCB only... 
						//if (! isset($params['avs_email'])) $errors[] = 'Email not provided';

					}
					
					if ($this->gateway()->check_cvd()) {
						if (! isset($params['cvd'])) $errors[] = 'CVD not provided';
					}
					
					break;
				
				case 'purchasecorrection':
					if (! isset($params['order_id']) || '' == $params['order_id']) $errors[] = 'Order ID not provided';
					if (! isset($params['txn_number']) || '' == $params['txn_number']) $errors[] = 'Transaction number not provided';
					break;
			
				case 'completion':
					if (! isset($params['comp_amount']) || '' == $params['comp_amount']) $errors[] = 'Amount not provided';
					if (! isset($params['order_id']) || '' == $params['order_id']) $errors[] = 'Order ID not provided';
					if (! isset($params['txn_number']) || '' == $params['txn_number']) $errors[] = 'Transaction number not provided';
					break;
					
				case 'refund':
					if (! isset($params['amount']) || '' == $params['amount']) $errors[] = 'Amount not provided';
					if (! isset($params['order_id']) || '' == $params['order_id']) $errors[] = 'Order ID not provided';
					if (! isset($params['txn_number']) || '' == $params['txn_number']) $errors[] = 'Transaction number not provided';
					break;
					
				default:
					$errors[] = $params['type'] . ' is not a support transaction type';
			}
		} else {
			$errors[] = 'Transaction type not provided';
		}
		
		$this->errors($errors);
		return empty($errors);
	}
	
	/**
	 * Get or set errors.
	 *
	 * @param array $errors 
	 * @return array|Moneris_Result Fluid interface for set operations.
	 */
	public function errors(array $errors = null)
	{
		if (! is_null($errors)) {
			$this->_errors = $errors;
			return $this;
		}
		return $this->_errors;
	}
	
	/**
	 * Get or set the gateway object.
	 *
	 * @param Moneris_Gateway $gateway Optional.
	 * @return Moneris_Gateway|Moneris_Transaction Fluid interface for set operations
	 */
	public function gateway(Moneris_Gateway $gateway = null)
	{
		if (! is_null($gateway)) {
			$this->_gateway = $gateway;
			return $this;
		}
		return $this->_gateway;
	}
	
	/**
	 * The transaction number (only available for transaction that have been processed).
	 *
	 * @return string|null
	 */
	public function number()
	{
		if (is_null($this->_response))
			return null;
		return (string) $this->_response->receipt->TransID;
	}
	
	/**
	 * The order ID for this transaction.
	 * Only available for some transaction types.
	 *
	 * @return string|null
	 */
	public function order_id()
	{
		if (isset($this->_params['order_id']))
			return $this->_params['order_id'];
		return null;
	}
	
	/**
	 * Get or some some params! Like a boss!
	 *
	 * @param array $params 
	 * @return array|Moneris_Transaction Fluid interface on set operations.
	 */
	public function params(array $params = null, $prepare_params = true)
	{
		if (! is_null($params)) {
			$this->_params = $prepare_params ? $this->prepare($params) : $params;
			return $this;
		}
		return $this->_params;
	}
	
	/**
	 * Clean up transaction parameters.
	 *
	 * @param array $params 
	 * @return array Cleaned up parameters
	 */
	public function prepare(array $params)
	{
		foreach ($params as $k => $v) {
			$params[$k] = trim($v); // remove whitespace
			if ('' == $params[$k]) unset($params[$k]); // remove optional params
		}
		
		if (isset($params['cc_number'])) {
			$params['pan'] = preg_replace('/\D/', '', $params['cc_number']);
			unset($params['cc_number']);
		}
		
		if (isset($params['description'])) {
			$params['dynamic_descriptor'] = $params['description'];
			unset($params['description']);
		}
		
		if (isset($params['expiry_month']) && isset($params['expiry_year']) && ! isset($params['expdate'])) {
			$params['expdate'] = sprintf('%02d%02d', $params['expiry_year'], $params['expiry_month']);
			unset($params['expiry_year'], $params['expiry_month']);
		}
			
		return $params;
	}
	
	/**
	 * Get or set the response.
	 *
	 * @param SimpleXMLElement $response 
	 * @return SimpleXMLElement|Moneris_Transaction Fluid interface for set operations
	 */
	public function response(SimpleXMLElement $response = null) 
	{
		if (! is_null($response)) {
			$this->_response = $response;
			return $this;
		}
		return $this->_response;
	}
	
	/**
	 * Get the result for this transaction.
	 *
	 * @return Moneris_Result
	 */
	public function result()
	{
		
	}
	
	/**
	 * Convert the transaction params into XML.
	 *
	 * @return string XML formatted transaction params
	 */
	public function to_xml()
	{
		$gateway = $this->gateway();
		$params = $this->params();
		
		$xml = new SimpleXMLElement('<request/>');
		$xml->addChild('store_id', $gateway->store_id());
		$xml->addChild('api_token', $gateway->api_key());
		
		$type = $xml->addChild($params['type']);
		unset($params['type']);

		if ($gateway->check_cvd()) {
			$cvd = $type->addChild('cvd_info');
			$cvd->addChild('cvd_indicator', '1');
			$cvd->addChild('cvd_value', $params['cvd']);
			unset($params['cvd']);
		}
		
		if ($gateway->check_avs()) {
			$avs = $type->addChild('avs_info');
			foreach ($params as $key => $value) {
				if (substr($key, 0, 4) != 'avs_')
					continue;
				$avs->addChild($key, $value);
				unset($params[$key]);
			}
			
		}
		
		foreach ($params as $key => $value) {
			$type->addChild($key, $value);
		}
	
		return $xml->asXML();
	}
	
	/**
	 * Was this transaction a huge success?
	 *
	 * @param SimpleXMLElement $response 
	 * @return Moneris_Result
	 */
	public function validate_response(SimpleXMLElement $response)
	{
		//var_dump($response);
		$this->response($response);
		$result = new Moneris_Result($this);
		$receipt = $response->receipt;
		$gateway = $this->gateway();
	
		// did the transaction go through?
		if ('Global Error Receipt' == $receipt->ReceiptId) {

			$result->error_code(Moneris_Result::ERROR_GLOBAL_ERROR_RECEIPT)
				->was_successful(false);
			return $result;
		}
		
		// was it a successful transaction?
		// any response code greater than 49 is an error code:
		if ((int) $receipt->ResponseCode >= 50 || (int) $receipt->ResponseCode == 0) {
			
			// trying to make some sense of this... grouping them as best as I can:
			switch ($receipt->ResponseCode) {
				case '050':
				case '074': 
					$result->error_code(Moneris_Result::ERROR_SYSTEM_UNAVAILABLE); 
					break;
				case '051':
				case '482':
				case '484': 
					$result->error_code(Moneris_Result::ERROR_CARD_EXPIRED); 
					break; 
				case '075': 
					$result->error_code(Moneris_Result::ERROR_INVALID_CARD); 
					break;
				case '076':
				case '079':
				case '080':
				case '081':
				case '082':
				case '083': 
					$result->error_code(Moneris_Result::ERROR_INSUFFICIENT_FUNDS); 
					break;
				case '077': 
					$result->error_code(Moneris_Result::ERROR_PREAUTH_FULL); 
					break;
				case '078': 
					$result->error_code(Moneris_Result::ERROR_DUPLICATE_TRANSACTION); 
					break;
				case '481':
				case '483': 
					$result->error_code(Moneris_Result::ERROR_DECLINED); 
					break;
				case '485': 
					$result->error_code(Moneris_Result::ERROR_NOT_AUTHORIZED); 
					break;
				case '486': 
				case '487': 
				case '489': 
				case '490': 
					$result->failed_cvd(true);
					$result->error_code(Moneris_Result::ERROR_CVD); 
					break;
				case 'null': 
					$result->error_code(Moneris_Result::ERROR_SYSTEM_UNAVAILABLE); 
					break;
				default: 
					$result->error_code(Moneris_Result::ERROR); 

			}
			
			return $result->was_successful(false);
			
		}
		
		// if the transaction used AVS, we need to know if it was successful, and void the transaction if it wasn't:
		if ($gateway->check_avs() 
			&& isset($receipt->AvsResultCode) 
			&& 'null' !== (string) $receipt->AvsResultCode
			&& ! in_array($receipt->AvsResultCode, $gateway->successful_avs_codes())) {
			
			// see if we can't provide a nice, detailed error response:
			switch ($receipt->AvsResultCode) {
				case 'B':
				case 'C': 
					$result->error_code(Moneris_Result::ERROR_AVS_POSTAL_CODE); 
					break;
				case 'G':
				case 'I': 
				case 'P': 
				case 'S':
				case 'U': 
				case 'Z': 
					$result->error_code(Moneris_Result::ERROR_AVS_ADDRESS); 
					break;
				case 'N': 
					$result->error_code(Moneris_Result::ERROR_AVS_NO_MATCH); 
					break;
				case 'R': 
					$result->error_code(Moneris_Result::ERROR_AVS_TIMEOUT); 
					break;
				default: 
					$result->error_code(Moneris_Result::ERROR_AVS);
			}
			
			
			$result->failed_avs(true);
			
		}
		
		
		// if the transaction used CVD, we need to know if it was successful, and void the transaction if it wasn't:
		$result_code = isset($receipt->CvdResultCode) ? (string) $receipt->CvdResultCode : null;
		if ($gateway->check_cvd()
			&& ! is_null($result_code) 
			&& ! in_array($result_code{1}, $gateway->successful_cvd_codes())) {
				
			$result->error_code(Moneris_Result::ERROR_CVD)->failed_cvd(true);
		}

		return $result->was_successful(true);
		
	}
	
}
