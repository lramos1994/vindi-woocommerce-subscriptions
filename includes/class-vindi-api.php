<?php

class Vindi_API
{
    /**
     * @var string
     */
    private $key;

    /**
     * @var string
     */
    public $last_error = '';

    /**
     * @var bool
     */
    private $accept_bank_slip;

    /**
     * @var Vindi_Logger
     */
    private $logger;

    private $errors_list = array(
        'invalid_parameter|card_number'          => 'Número do cartão inválido.',
        'invalid_parameter|payment_company_code' => '',
        'invalid_parameter|payment_company_id'   => ''
    );

    /**
     * @const string API base path.
     */
    const BASE_PATH = 'https://app.vindi.com.br/api/v1/';

    /**
     * @param string $key
     */
    public function __construct($key, Vindi_Logger $logger)
    {
        $this->key    = $key;
        $this->logger = $logger;
    }

    /**
     * Build HTTP Query.
     *
     * @param array $data
     *
     * @return string
     */
    private function build_body($data)
    {
        return json_encode($data);
    }

    /**
     * Generate Authentication Header.
     * @return string
     */
    private function get_auth_header()
    {
        return sprintf('Basic %s:', base64_encode($this->key));
    }

    /**
     * @param array $error
     * @param       $endpoint
     *
     * @return string
     */
    private function get_error_message($error, $endpoint)
    {
        var_dump($error);
        exit;

        $error_id         = empty($error['id']) ? '' : $error['id'];
        $error_parameter  = empty($error['parameter']) ? '' : $error['parameter'];

        $error_identifier = sprintf('%s|%s', $error_id, $error_parameter);

        if(false === array_key_exists($error_identifier, $this->errors_list))
            return $error_identifier;

        return __($this->errors_list[$error_identifier], VINDI_IDENTIFIER);
    }

    /**
     * @param array $response
     * @param       $endpoint
     *
     * @return bool
     */
    private function check_response($response, $endpoint)
    {
        if (isset($response['errors']) && ! empty($response['errors'])) {
            foreach ($response['errors'] as $error) {
                $message = $this->get_error_message($error, $endpoint);

                if (function_exists('wc_add_notice'))
                    wc_add_notice($message, 'error');

                $this->last_error = $message;
            }

            return false;
        }

        $this->last_error = '';

        return true;
    }

    /**
     * Perform request to API.
     *
     * @param string $endpoint
     * @param string $method
     * @param array  $data
     * @param null   $data_to_log
     *
     * @return array|bool|mixed
     */
    private function request($endpoint, $method = 'POST', $data = array(), $data_to_log = null)
    {
        $url  = sprintf('%s%s', self::BASE_PATH, $endpoint);
        $body = $this->build_body($data);

        $request_id = rand();

        $data_to_log = null !== $data_to_log ? $this->build_body($data_to_log) : $body;

        $this->logger->log(sprintf("[Request #%s]: Novo Request para a API.\n%s %s\n%s", $request_id, $method, $url, $data_to_log));

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header(),
                'Content-Type'  => 'application/json',
                'User-Agent'    => sprintf('Vindi-WooCommerce-Subscriptions/%s; %s', Vindi_WooCommerce_Subscriptions::VERSION, get_bloginfo( 'url' )),
            ),
            'method'    => $method,
            'timeout'   => 60,
            'sslverify' => true,
            'body'      => $body,
        ));

        if (is_wp_error($response)) {
            $this->logger->log(sprintf("[Request #%s]: Erro ao fazer request! %s", $request_id, print_r($response, true)));

            return false;
        }

        $status = sprintf('%s %s', $response['response']['code'], $response['response']['message']);
        $this->logger->log(sprintf("[Request #%s]: Nova Resposta da API.\n%s\n%s", $request_id, $status, print_r($response['body'], true)));

        $response_body = wp_remote_retrieve_body($response);

        if (! $response_body) {
            $this->logger->log(sprintf('[Request #%s]: Erro ao recuperar corpo do request! %s', $request_id, print_r($response, true)));

            return false;
        }

        $response_body_array = json_decode($response_body, true);

        if (! $this->check_response($response_body_array, $endpoint)) {
            return false;
        }

        return $response_body_array;
    }

    /**
     * Make an API request to create a Customer.
     *
     * @param array $body (name, email, code)
     *
     * @return array|bool|mixed
     */
    public function create_customer($body)
    {
        if ($response = $this->request('customers', 'POST', $body)) {
            return $response['customer']['id'];
        }

        return false;
    }

    /**
     *
     * @param int   $subscription_id
     *
     * @return array|bool|mixed
     */
    public function delete_subscription($subscription_id, $cancel_bills = false)
    {
        $query = '';

        if($cancel_bills)
            $query = '?cancel_bills=true';

        if ($response = $this->request('subscriptions/' . $subscription_id . $query, 'DELETE'))
            return $response;

        return false;
    }

    /**
     * Make an API request to retrieve an existing Customer.
     *
     * @param string $code
     *
     * @return array|bool|mixed
     */
    public function find_customer_by_code($code)
    {
        $response = $this->request(sprintf(
            'customers/search?code=%s',
            $code
        ),'GET');

        if ($response && (1 === count($response['customers'])) &&
            isset($response['customers'][0]['id'])) {
            return $response['customers'][0]['id'];
        }

        return false;
    }

    /**
     * Make an API request to retrieve an existing Customer or to create one if not found.
     *
     * @param array $body (name, email, code)
     *
     * @return array|bool|mixed
     */
    public function find_or_create_customer($body)
    {
        $customer_id = $this->find_customer_by_code($body['code']);

        if (false === $customer_id) {
            return $this->create_customer($body);
        }

        return $customer_id;
    }

    /**
     * Make an API request to create a Payment Profile to a Customer.
     *
     * @param $body (holder_name, card_expiration, card_number, card_cvv, customer_id)
     *
     * @return array|bool|mixed
     */
    public function create_customer_payment_profile($body)
    {
        // Protect credit card number.
        $log                = $body;
        $log['card_number'] = '**** *' . substr($log['card_number'], -3);
        $log['card_cvv']    = '***';

        return $this->request('payment_profiles', 'POST', $body, $log);
    }

    /**
     * Make an API request to create a Subscription.
     *
     * @param $body (plan_id, customer_id, payment_method_code, product_items[{product_id}])
     *
     * @return array
     */
    public function create_subscription($body)
    {
        if (($response = $this->request('subscriptions', 'POST', $body)) &&
            isset($response['subscription']['id'])) {

            $subscription         = $response['subscription'];
            $subscription['bill'] = $response['bill'];

            return $subscription;
        }

        return false;
    }

    /**
     * Make an API request to retrive Payment Methods.
     * @return array|bool
     */
    public function get_payment_methods()
    {
        if (false === ($payment_methods = get_transient('vindi_payment_methods'))) {

            $payment_methods = array(
                'credit_card' => array(),
                'bank_slip'   => false,
            );

            $response = $this->request('payment_methods', 'GET');

            if (false === $response)
                return false;

            foreach ($response['payment_methods'] as $method) {
                if ('active' !== $method['status']) {
                    continue;
                }

                if ('PaymentMethod::CreditCard' === $method['type']) {
                    $payment_methods['credit_card'] = array_merge(
                        $payment_methods['credit_card'],
                        $method['payment_companies']
                    );
                } else if ('PaymentMethod::BankSlip' === $method['type']) {
                    $payment_methods['bank_slip'] = true;
                }
            }

            set_transient('vindi_payment_methods', $payment_methods, 12 * HOUR_IN_SECONDS);
        }

        $this->accept_bank_slip = $payment_methods['bank_slip'];

        return $payment_methods;
    }

    /**
     * @return bool|null
     */
    public function accept_bank_slip()
    {
        if (null === $this->accept_bank_slip) {
            $this->get_payment_methods();
        }

        return $this->accept_bank_slip;
    }

    /**
     * @param array $body
     *
     * @return int|bool
     */
    public function create_bill($body)
    {
        if ($response = $this->request('bills', 'POST', $body)) {
            return $response['bill']['id'];
        }

        return false;
    }

    /**
     * @param $bill_id
     *
     * @return array|bool|mixed
     */
    public function approve_bill($bill_id)
    {
        $response = $this->request(sprintf('bills/%s', $bill_id), 'GET');

        if (false === $response || ! isset($response['bill'])) {
            return false;
        }

        $bill = $response['bill'];

        if ('review' !== $bill['status']) {
            return true;
        }

        return $this->request(sprintf('bills/%s/approve', $bill_id), 'POST');
    }

    /**
     * @param $bill_id
     *
     * @return string
     */
    public function get_bank_slip_download($bill_id)
    {
        $response = $this->request(sprintf('bills/%s', $bill_id), 'GET');

        if (false === $response) {
            return false;
        }

        return $response['bill']['charges'][0]['print_url'];
    }

    /**
     * @return array
     */
    public function get_products()
    {
        $list     = array();
        $response = $this->request('products?query=status:active', 'GET');

        if ($products = $response['products']) {
            foreach ($products as $product) {
                $list[$product['id']] = sprintf('%s (%s)', $product['name'], $product['pricing_schema']['short_format']);
            }
        }

        return $list;
    }

    /**
     * @param int $id
     *
     * @return array
     */
    public function get_plan_items($id)
    {
        $list     = array();
        $response = $this->request(sprintf('plans/%s', $id), 'GET');

        if ($plan = $response['plan']) {
            foreach ($plan['plan_items'] as $item) {
                if (isset($item['product'])) {
                    $list[] = $item['product']['id'];
                }
            }
        }

        return $list;
    }

    /**
     * @param int   $plan_id
     * @param float $order_total
     *
     * @return array
     */
    public function build_plan_items_for_subscription($plan_id, $order_total)
    {
        $list = array();

        foreach ($this->get_plan_items($plan_id) as $item) {
            $list[] = array(
                'product_id'     => $item,
                'pricing_schema' => array(
                    'price' => $order_total
                ),
            );
            $order_total = 0;
        }

        return $list;
    }

    /**
     * @return array
     */
    public function get_plans()
    {
        if (false === ($list = get_transient('vindi_plans'))) {
            $list     = array();
            $response = $this->request('plans?query=status:active', 'GET');

            if ($plans = $response['plans']) {
                foreach ($plans as $plan) {
                    $list[$plan['id']] = $plan['name'];
                }
            }

            set_transient('vindi_plans', $list, 10 * MINUTE_IN_SECONDS);
        }

        return $list;
    }

    /**
     * Make an API request to create a Product.
     *
     * @param array $body (name, code, status, pricing_schema (price))
     *
     * @return array|bool|mixed
     */
    public function create_product($body)
    {
        if ($response = $this->request('products', 'POST', $body)) {
            return $response['product'];
        }

        return false;
    }

    /**
     * Make an API request to retrieve an existing Product.
     *
     * @param string $code
     *
     * @return array|bool|mixed
     */
    public function find_product_by_code($code)
    {
        $transient_key = 'vindi_product_' . $code;
        $product       = get_transient($transient_key);

        if(false !== $product)
            return $product;

        $response = $this->request(sprintf('products?query=code:%s', $code), 'GET');

        if (false === empty($response['products'])) {
            $product = end($response['products']);
            set_transient($transient_key, $product, 1 * HOUR_IN_SECONDS);
        }

        return $product;
    }

    /**
     * @return array
     */
    public function find_or_create_product($name, $code)
    {
        $product = $this->find_product_by_code($code);

        if (false === $product)
        {
            return $this->create_product(array(
                'name'           => $name,
                'code'           => $code,
                'status'         => 'active',
                'pricing_schema' => array(
                    'price' => 0,
                ),
            ));
        }

        return $product;
    }

    /**
     * Make an API request to retrieve the Unique Payment Product or to create it if not found.
     * @return array|bool|mixed
     */
    public function find_or_create_unique_payment_product()
    {
        $product_id = $this->find_product_by_code('wc-pagtounico');

        if (false === $product_id)
        {
            return $this->create_product(array(
                'name'           => 'Pagamento Único (não remover)',
                'code'           => 'wc-pagtounico',
                'status'         => 'active',
                'pricing_schema' => array(
                    'price' => 0,
                ),
            ));
        }

        return $product_id;
    }

    /**
     * Make an API request to retrieve informations about the Merchant.
     * @return array|bool|mixed
     */
    public function get_merchant()
    {
        if (false === ($merchant = get_transient('vindi_merchant'))) {
            $response = $this->request('merchant', 'GET');

            if (! $response || ! $response['merchant'])
                return false;

            $merchant = $response['merchant'];

            set_transient('vindi_merchant', $merchant, 1 * HOUR_IN_SECONDS);
        }

        return $merchant;
    }

    /**
     * Check to see if Merchant Status is Trial.
     * @return boolean
     */
    public function is_merchant_status_trial()
    {
        if ($merchant = $this->get_merchant())
            return 'trial' === $merchant['status'];

        return false;
    }

    /**
     * Verify API key authorization and clear
     * all transient data if access was denied
     *@param $api_key string
     *@return mixed|boolean|string
     */
    public function test_api_key($api_key)
    {
        delete_transient('vindi_merchant');
        
        $url         = static::BASE_PATH . 'merchant';
        $method      = 'GET';
		$request_id  = rand();
        $data_to_log = 'API Authorization Test';

		$this->logger->log(sprintf("[Request #%s]: Novo Request para a API.\n%s %s\n%s", $request_id, $method, $url, $data_to_log));

		$response = wp_remote_post( $url, [
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
				'Content-Type'  => 'application/json',
			    'User-Agent'    => sprintf('Vindi-WooCommerce-Subscriptions/%s; %s', Vindi_WooCommerce_Subscriptions::VERSION, get_bloginfo( 'url' )),
			],
			'method'    => $method,
			'timeout'   => 60,
			'sslverify' => true,
		] );

		if (is_wp_error($response)) {
			$this->logger->log(sprintf("[Request #%s]: Erro ao fazer request! %s", $request_id, print_r($response, true)));

			return false;
		}

		$status = $response['response']['code'] . ' ' . $response['response']['message'];
		$this->logger->log(sprintf("[Request #%s]: Nova Resposta da API.\n%s\n%s", $request_id, $status, print_r($response['body'], true)));

		$response_body = wp_remote_retrieve_body($response);

		if (!$response_body) {
			$this->logger->log(sprintf('[Request #%s]: Erro ao recuperar corpo do request! %s', $request_id, print_r($response, true)));

			return false;
		}

		$response_body_array = json_decode($response_body, true);

        if (isset($response_body_array['errors']) && !empty($response_body_array['errors'])) {
			foreach ($response_body_array['errors'] as $error) {
				if('unauthorized' == $error['id'] AND 'authorization' == $error['parameter']) {
                    delete_transient('vindi_plans');
                    delete_transient('vindi_payment_methods');
                    return $error['id'];
                }
			}
		}

		return true;
    }
}
