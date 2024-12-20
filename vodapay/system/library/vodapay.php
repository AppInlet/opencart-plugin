<?php

namespace Opencart\System\Library;

/**
 * VodaPay class
 */
class VodaPay
{
    /**
     * Config vodapay statuses
     */
    public const VP_PENDING    = 'vodapay-Pending';
    public const VP_PROCESSING = 'vodapay-Processing';
    public const VP_FAILED     = 'vodapay-Failed';
    public const VP_COMPLETE   = 'vodapay-Complete';
    public const VP_AUTHORISED = 'vodapay-Authorised';
    public const VP_F_CAPTURED = 'vodapay-Fully-Captured';
    public const VP_F_REFUNDED = 'vodapay-Fully-Refunded';
    public const VP_P_CAPTURED = 'vodapay-Partially-Captured';
    public const VP_P_REFUNDED = 'vodapay-Partially-Refunded';
    public const VP_AUTH_REV   = 'vodapay-Auth-Reversed';
    public const VP_DECLINED   = 'vodapay-Declined';
    /**
     * Config tags
     */
    public const UAT_IDENTITY_URL         = 'https://identity-uat.vodapaygateway.vodacom.co.za';
    public const LIVE_IDENTITY_URL        = 'https://identity.vodapaygateway.vodacom.co.za';
    public const UAT_API_URL              = 'https://api-gateway-uat.vodapaygateway.vodacom.co.za';
    public const LIVE_API_URL             = 'https://api-gateway.vodapaygateway.vodacom.co.za';
    public const TOKEN_ENDPOINT           = '/identity/auth/access-token';
    public const ORDER_ENDPOINT           = '/transactions/outlets/%s/orders';
    public const ORDER_STATUS_ENDPOINT    = '/transactions/outlets/%s/orders/%s';
    public const FETCH_ENDPOINT           = '/transactions/outlets/%s/orders/%s';
    public const CAPTURE_ENDPOINT         = '/transactions/outlets/%s/orders/%s/payments/%s/captures';
    public const REFUND_ENDPOINT          = '/transactions/outlets/%s/orders/%s/payments/%s/captures/%s/refund';
    public const PURCHASE_REFUND_ENDPOINT = '/transactions/outlets/%s/orders/%s/payments/%s/cancel';
    public const VOID_ENDPOINT            = '/transactions/outlets/%s/orders/%s/payments/%s/cancel';
    /**
     *
     * @var session,curl,config
     */
    public $session;
    public $url;
    private $config;

    /**
     * constructor
     */
    public function __construct($registry)
    {
        $this->session = $registry->get('session');
        $this->url     = $registry->get('url');
        $this->config  = $registry->get('config');
    }

    /**
     * Retrieve apikey and outletReferenceId empty or not
     * @return bool
     */
    public function isComplete()
    {
        return !empty($this->getApiKey()) && !empty($this->getOutletReferenceId());
    }

    /**
     * Gets Identity Url
     * @return string
     */
    public function getIdentityUrl()
    {
        if ($this->getEnvironment() === 'uat') {
            return self::UAT_IDENTITY_URL;
        }

        return self::LIVE_IDENTITY_URL;
    }

    /**
     * Gets Payment Action
     * @return string
     */
    public function getPaymentAction()
    {
        return $this->config->get('payment_vodapay_payment_action');
    }

    /**
     * Gets Environment
     * @return string
     */
    public function getEnvironment()
    {
        return $this->config->get('payment_vodapay_environment');
    }

    /**
     * Gets Api Url
     * @return string
     */
    public function getApiUrl()
    {
        if ($this->getEnvironment() == "uat") {
            $api_url = $this->config->get('payment_vodapay_uat_api_url');
        } else {
            $api_url = $this->config->get('payment_vodapay_live_api_url');
        }

        return $api_url;
    }

    /**
     * Gets Outlet Reference Id
     * @return string
     */
    public function getOutletReferenceId()
    {
        $outletReferenceId = $this->config->get('payment_vodapay_outlet_ref');
        $currency          = $this->session->data['currency'] ?? '';
        $extraCurrencies   = $this->config->get('payment_vodapay_extra_currency');
        if (!empty($extraCurrencies) && (($key = array_search($currency, $extraCurrencies)) !== false)) {
            $extraOutlets      = $this->config->get('payment_vodapay_extra_outlet');
            $outletReferenceId = $extraOutlets[$key];
        }

        return $outletReferenceId;
    }

    /**
     * Gets Api Key
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->config->get('payment_vodapay_api_key');
    }

    /**
     * Gets Http Version
     * @return string
     */
    public function getHttpVersion(): string
    {
        return $this->config->get('payment_vodapay_http_version');
    }

    /**
     * Gets TokenRequest URL
     * @return string
     */
    public function getTokenRequestUrl(): string
    {
        return $this->getApiUrl() . self::TOKEN_ENDPOINT;
    }

    /**
     * Gets Order Request URL
     * @return string
     */
    public function getOrderRequestUrl()
    {
        $endpoint = sprintf(self::ORDER_ENDPOINT, $this->getOutletReferenceId());

        return $this->getApiUrl() . $endpoint;
    }

    /**
     * @return mixed
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Gets Fetch Request URL
     *
     * @param string $order_ref
     *
     * @return string
     */
    public function getFetchRequestUrl($order_ref)
    {
        $endpoint = sprintf(self::FETCH_ENDPOINT, $this->getOutletReferenceId(), $order_ref);

        return $this->getApiUrl() . $endpoint;
    }

    /**
     * Gets Order Capture URL.
     *
     * @param string $order_ref
     * @param string $payment_ref
     *
     * @return string
     */
    public function getOrderCaptureUrl($order_ref, $payment_ref)
    {
        $endpoint = sprintf(self::CAPTURE_ENDPOINT, $this->getOutletReferenceId(), $order_ref, $payment_ref);

        return $this->getApiUrl() . $endpoint;
    }

    public function getOrderStatusUrl($orderRef)
    {
        $endpoint = sprintf(self::ORDER_STATUS_ENDPOINT, $this->getOutletReferenceId(), $orderRef);

        return $this->getApiUrl() . $endpoint;
    }

    /**
     * Gets Order Refund URL
     *
     * @param string $order_ref
     * @param string $payment_ref
     * @param string $transaction_id
     *
     * @return string
     */
    public function getOrderRefundUrl($order_ref, $payment_ref, $transaction_id)
    {
        $endpoint = sprintf(
            self::REFUND_ENDPOINT,
            $this->getOutletReferenceId(),
            $order_ref,
            $payment_ref,
            $transaction_id
        );

        return $this->getApiUrl() . $endpoint;
    }

    public function getPurchaseRefundUrl($order_ref, $payment_ref, $transaction_id)
    {
        $endpoint = sprintf(
            self::PURCHASE_REFUND_ENDPOINT,
            $this->getOutletReferenceId(),
            $order_ref,
            $payment_ref,
            $transaction_id
        );

        return $this->getApiUrl() . $endpoint;
    }

    /**
     * Gets Order Void URL
     *
     * @param string $order_ref
     * @param string $payment_ref
     *
     * @return string
     */
    public function getOrderVoidUrl($order_ref, $payment_ref)
    {
        $endpoint = sprintf(self::VOID_ENDPOINT, $this->getOutletReferenceId(), $order_ref, $payment_ref);

        return $this->getApiUrl() . $endpoint;
    }

    /**
     * Is debug on?
     * @return boolean
     */
    public function isDebugOn()
    {
        return (bool)$this->config->get('payment_vodapay_debug');
    }

    /**
     * Debug log
     *
     * @param string $text
     */
    public function debug($text)
    {
        if ($this->isDebugOn()) {
            $logger = new Log('vodapay.log');
            $logger->write($text);
        }
    }

    /**
     * VodaPay Order Status
     * @return array
     */
    public function vodapayOrderStatus()
    {
        $lang = (int)$this->config->get('config_language_id');

        return array(
            [$lang => ['name' => self::VP_PENDING]],
            [$lang => ['name' => self::VP_PROCESSING]],
            [$lang => ['name' => self::VP_FAILED]],
            [$lang => ['name' => self::VP_COMPLETE]],
            [$lang => ['name' => self::VP_AUTHORISED]],
            [$lang => ['name' => self::VP_F_CAPTURED]],
            [$lang => ['name' => self::VP_F_REFUNDED]],
            [$lang => ['name' => self::VP_P_CAPTURED]],
            [$lang => ['name' => self::VP_P_REFUNDED]],
            [$lang => ['name' => self::VP_AUTH_REV]],
            [$lang => ['name' => self::VP_DECLINED]]
        );
    }

    /**
     * Get Order status Id
     *
     * @param string $name
     *
     * @return type
     */
    public function getOrderStatusId($controller, $status)
    {
        $controller->load->model('extension/vodapay/payment/vodapay');

        return $controller->model_extension_vodapay_payment_vodapay->getVodaPayStatusId($status);
    }


}
