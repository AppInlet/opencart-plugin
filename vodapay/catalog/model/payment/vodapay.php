<?php

namespace Opencart\Catalog\Model\Extension\VodaPay\Payment;

use Ngenius\NgeniusCommon\Formatter\ValueFormatter;
use Ngenius\NgeniusCommon\NgeniusHTTPCommon;
use Ngenius\NgeniusCommon\NgeniusHTTPTransfer;
use Opencart\System\Engine\Model;
use Opencart\System\Library\Tools\Request;
use Opencart\System\Library\Tools\Validate;
use Opencart\System\Library\VodaPay as VodaPayTools;
use Ngenius\NgeniusCommon\Processor\ApiProcessor;

require_once DIR_EXTENSION . 'vodapay/system/library/vendor/autoload.php';

/**
 * ModelExtensionPaymentVodaPay class
 */
class VodaPay extends Model
{
    public const VODAPAY_STARTED    = 'STARTED';
    public const VODAPAY_AUTHORISED = 'AUTHORISED';
    public const VODAPAY_CAPTURED   = 'CAPTURED';
    public const VODAPAY_PURCHASED  = 'PURCHASED';
    public const VODAPAY_FAILED     = 'FAILED';
    public const EXTENSION_LITERAL  = "extension/vodapay/payment/vodapay";
    public const CHECKOUT_LITERAL   = "checkout/order";
    public const CAPTURE_LITERAL    = "cnp:capture";
    public const LINKS_LITERAL      = "_links";
    public const EMBEDDED_LITERAL   = "_embedded";
    public const UPDATE_LITERAL     = "UPDATE ";
    public const SELECT_LITERAL     = "SELECT ";
    /**
     * @var mixed|string
     */
    private string $vodapayState;
    private string $orderStatus;
    private VodaPayTools $vodapay;

    /**
     *
     * @return array
     */
    public function getMethods($address, $total = null)
    {
        $this->load->language('extension/vodapay/payment/vodapay');

        if ($this->config->get('payment_vodapay_title') == "") {
            $title = $this->language->get('text_title');
        } else {
            $title = $this->config->get('payment_vodapay_title');
        }

        $option_data['vodapay'] = [
            'code' => 'vodapay.vodapay',
            'name' => $this->language->get('text_title')
        ];

        return array(
            'code'       => 'vodapay',
            'name'       => $title,
            'option'     => $option_data,
            'sort_order' => $this->config->get('payment_vodapay_sort_order')
        );
    }

    /**
     * Insert data to the vodapay table
     *
     * @param type $data
     *
     * @return last inserted id
     */
    public function insertOrderInfo(array $data)
    {
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "vodapay_vodapay`
             SET order_id = {$data['order_id']}, amount = {$data['amount']}, currency = '{$data['currency']}',
              reference = '{$data['reference']}', action = '{$data['action']}', state = '{$data['state']}',
               status = '{$data['status']}' "
        );

        return $this->db->getLastId();
    }

    /**
     * Remove data from the vodapay table
     *
     * @param int $orderId
     *
     * @return void
     */
    public function removeOrderInfo(int $orderId)
    {
        $this->db->query(
            "DELETE FROM `" . DB_PREFIX . "vodapay_vodapay`

             WHERE order_id = '$orderId'"
        );
    }

    /**
     * Get order statues Id
     * @return array
     */
    public function getVodaPayStatusId(string $status)
    {
        $get_result = $this->db->query(
            "SELECT order_status_id FROM `" . DB_PREFIX . "order_status` WHERE name LIKE '%$status%'"
        );

        return $get_result->row['order_status_id'];
    }

    /**
     * Get all Order id
     *
     * @param int $order_id
     *
     * @return reult set
     */
    public function getOrder(int $order_id)
    {
        return $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "vodapay_vodapay` WHERE order_id = {$order_id}"
        );
    }

    /**
     * Update the table
     *
     * @param array $data
     * @param int $nid
     *
     * @return result set
     */
    public function updateTable(array $data, int $nid)
    {
        if ($nid == 0) {
            throw new \Exception('Invalid nid provided: ' . $nid);
        }

        return $this->db->query(
            self::UPDATE_LITERAL . DB_PREFIX
            . "vodapay_vodapay SET state = '{$data['state']}',
             status = '{$data['status']}', payment_id = '{$data['payment_id']}',
              captured_amt = '{$data['captured_amt']}' WHERE nid = '{$nid}' "
        );
    }

    /**
     * Insert data to the table
     *
     * @param type $customer_id
     * @param type $description
     * @param type $amount
     * @param type $order_id
     */
    public function addTransaction($customer_id, $description = '', $amount = '', $order_id = 0)
    {
        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "customer_transaction SET customer_id = '"
            . (int)$customer_id . "', order_id = '" . (int)$order_id . "', description = '" . $this->db->escape(
                $description
            ) . "', amount = '" . (float)$amount . "', date_added = NOW()"
        );
    }

    /**
     * Fetch data table
     *
     * @param string $where
     *
     * @return array
     */
    public function fetchOrder(string $where)
    {
        $get_result = $this->db->query("SELECT * FROM `" . DB_PREFIX . "vodapay_vodapay` WHERE $where");

        return $get_result->rows;
    }

    public function quantityReduce($order_id)
    {
        $this->load->model('checkout/order');
        $order_products = $this->model_checkout_order->getProducts($order_id);

        foreach ($order_products as $order_product) {
            $this->db->query(
                self::UPDATE_LITERAL . DB_PREFIX . "product SET quantity = (quantity - "
                . (int)$order_product['quantity'] . ") WHERE product_id = '"
                . (int)$order_product['product_id'] . "' AND subtract = '1'"
            );

            $order_options = $this->model_checkout_order->getOptions(
                $order_id,
                $order_product['order_product_id']
            );

            foreach ($order_options as $order_option) {
                $this->db->query(
                    self::UPDATE_LITERAL . DB_PREFIX . "product_option_value SET quantity = (quantity - "
                    . (int)$order_product['quantity'] . ") WHERE product_option_value_id = '"
                    . (int)$order_option['product_option_value_id'] . "' AND subtract = '1'"
                );
            }
        }
    }

    /**
     * Order Authorize.
     *
     * @param array $order
     * @param ApiProcessor $apiProcessor
     *
     * @return void
     */
    public function orderAuthorize(array $order, ApiProcessor $apiProcessor): void
    {
        $this->load->model(self::CHECKOUT_LITERAL);
        $payment_result = $apiProcessor->getPaymentResult();
        if (self::VODAPAY_AUTHORISED === $this->vodapayState) {
            $this->orderStatus = $this->vodapay::VP_AUTHORISED;
            $this->load->model(self::CHECKOUT_LITERAL);

            $total = $order['total'];

            ValueFormatter::formatCurrencyDecimals($order['currency_code'] , $total);

            $message   = 'An amount: ' . $order['currency_code'] . $total . ' has been authorised.';
            $status_id = $this->vodapay->getOrderStatusId($this, $this->vodapay::VP_AUTHORISED);

            $this->model_checkout_order->addHistory($order['order_id'], $status_id, $message, true);
        } elseif (isset($payment_result['state']) && $payment_result['state'] === 'FAILED') {
            $this->declineOrder($payment_result, $order);
        }
    }

    /**
     * Order Sale
     *
     * @param array $order
     * @param array $payment_result
     *
     * @return array|string|null
     */
    public function orderSale(array $order, ApiProcessor $apiProcessor): array|string|null
    {
        $payment_result = $apiProcessor->getPaymentResult();

        if (self::VODAPAY_CAPTURED === $this->vodapayState) {
            $this->orderStatus = $this->vodapay::VP_COMPLETE;
            $this->load->model(self::CHECKOUT_LITERAL);
            $transaction_id = '';
            if (isset($payment_result[self::EMBEDDED_LITERAL][self::CAPTURE_LITERAL][0])) {
                $transaction_id = $apiProcessor->getTransactionId();
            }

            $total = $order['total'];

            ValueFormatter::formatCurrencyDecimals($order['currency_code'] , $total);

            $message   = 'Captured Amount: ' . $order['currency_code'] . $total . ' | Transaction ID: ' . $transaction_id;
            $status_id = $this->vodapay->getOrderStatusId($this, $this->vodapay::VP_COMPLETE);

            $this->model_checkout_order->addHistory($order['order_id'], $status_id, $message, true);

            return $transaction_id;
        } elseif ($payment_result['state'] === 'FAILED') {
            $this->declineOrder($payment_result, $order);
        }

        return null;
    }

    /**
     * Order Sale
     *
     * @param array $order
     * @param ApiProcessor $apiProcessor
     *
     * @return array|string|null
     */
    public function orderPurchase(array $order, ApiProcessor $apiProcessor): array|string|null
    {
        $payment_result = $apiProcessor->getPaymentResult();

        if (self::VODAPAY_PURCHASED === $this->vodapayState) {
            $this->orderStatus = $this->vodapay::VP_COMPLETE;
            $this->load->model(self::CHECKOUT_LITERAL);
            $transaction_id = '';

            if (isset($payment_result[self::EMBEDDED_LITERAL][self::CAPTURE_LITERAL][0])) {
                $transaction_id = $apiProcessor->getTransactionId();
            }

            $total = $order['total'];

            ValueFormatter::formatCurrencyDecimals($order['currency_code'] , $total);

            $message   = 'Captured Amount: ' . $order['currency_code']
                         . $total . ' | Transaction ID: ' . $transaction_id;
            $status_id = $this->vodapay->getOrderStatusId($this, $this->vodapay::VP_COMPLETE);

            $this->model_checkout_order->addHistory($order['order_id'], $status_id, $message, true);

            return $transaction_id;
        } elseif ($payment_result['state'] === 'FAILED') {
            $this->declineOrder($payment_result, $order);
        }

        return null;
    }

    /**
     * @param $payment_result
     * @param $order
     *
     * @return void
     */
    public function declineOrder($payment_result, $order): void
    {
        $this->orderStatus = $this->vodapay::VP_DECLINED;
        $this->load->model(self::CHECKOUT_LITERAL);
        $message   = $payment_result['authResponse']['resultMessage'] ?? 'Declined';
        $status_id = $this->vodapay->getOrderStatusId($this, $this->vodapay::VP_DECLINED);
        $this->model_checkout_order->addHistory($order['order_id'], $status_id, $message, true);
    }

    /**
     * Process Order
     *
     * @param array $order
     * @param string $action
     *
     * @return bool|string
     */
    public function processOrder(array $order, ApiProcessor $apiProcessor, string $action): bool|string
    {
        $this->load->model(self::EXTENSION_LITERAL);
        $payment_result = $apiProcessor->getPaymentResult();

        if (!empty($order['order_id'])) {
            $order_item   = $this->getOrder($order['order_id']);
            $redirect_url = '';

            $orderTotals = $this->model_checkout_order->getTotals($this->session->data['order_id']);

            $customerStoreCredit = 0.00;

            foreach ($orderTotals as $orderTotal) {
                if ($orderTotal['code'] === 'credit') {
                    $customerStoreCredit = (float)$orderTotal['value'];
                }
            }

            $this->addTransaction(
                $order['customer_id'],
                "Order ID: #" . $order['order_id'],
                $customerStoreCredit - (float)end($orderTotals)['value'],
                $order['order_id']
            );
        } else {
            $order_item = null;
        }

        if ($order_item) {
            $this->orderStatus = $this->vodapay::VP_PROCESSING;
            $data_table        = [];
            $payment_id        = '';
            $capture_id        = '';
            $captured_amt      = 0;
            if (isset($payment_result['_id'])) {
                $payment_id = $apiProcessor->getPaymentId();
            }

            if ($apiProcessor->isPaymentConfirmed()) {
                switch ($action) {
                    case 'AUTH':
                        $this->orderAuthorize($order, $apiProcessor);
                        break;
                    case 'SALE':
                        $capture_id   = $this->orderSale($order, $apiProcessor);
                        $captured_amt = $order['total'];
                        break;
                    case 'PURCHASE':
                        $capture_id   = $this->orderPurchase($order, $apiProcessor);
                        $captured_amt = $order['total'];
                        break;
                    default:
                        break;
                }
                $data_table['status'] = $this->orderStatus;
                $this->quantityReduce($order['order_id']);

                $redirect_url = $this->url->link('checkout/success');
            } elseif (self::VODAPAY_STARTED === $this->vodapayState) {
                $data_table['status'] = $this->vodapay::VP_PENDING;
            } else {
                $data_table['status'] = $this->vodapay::VP_FAILED;
                switch ($action) {
                    case 'SALE':
                        $this->orderSale($order, $apiProcessor);
                        break;
                    case 'PURCHASE':
                        $this->orderPurchase($order, $apiProcessor);
                        break;
                    case 'AUTH':
                        $this->orderAuthorize($order, $apiProcessor);
                        break;
                    default:
                        break;
                }
                $redirect_url = $this->url->link('checkout/failure');
            }
            $data_table['payment_id']   = $payment_id;
            $data_table['captured_amt'] = $captured_amt;
            $data_table['state']        = $this->vodapayState;
            if (!empty($capture_id)) {
                $comment = json_encode(array('captureId' => $capture_id));
            } else {
                $comment = json_encode(array('AuthId' => $payment_id));
            }

            $this->addTransaction(
                $order['customer_id'],
                $comment,
                (float)end($orderTotals)['value'],
                $order['order_id']
            );
            $this->updateTable($data_table, $order_item->row['nid']);

            return $redirect_url;
        } else {
            $this->vodapay->debug('VODAPAY: Platform order not found');
        }

        return false;
    }

    /**
     * @param string $order_ref
     *
     * @return array
     */
    public function processRedirect(string $order_ref): array
    {
        $data          = [];
        $this->vodapay = new VodaPayTools($this->registry);

        $this->load->model(self::CHECKOUT_LITERAL);

        $order_info   = $this->getVodaPayOrder($order_ref);
        $apiProcessor = new ApiProcessor($order_info);

        if (!isset($this->session->data['order_id'])) {
            $order = $this->fetchOrder("reference = '$order_ref'");

            if (empty($order)) {
                $this->response->redirect($this->url->link('checkout/failure'));
            }
            $this->session->data['order_id'] = end(
                                                   $order
                                               )['order_id'] ?? null;
        }

        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        if ($order) {
            if (is_array($order_info)) {
                if (isset($order_info[self::EMBEDDED_LITERAL]['payment'])
                    && is_array($order_info[self::EMBEDDED_LITERAL]['payment'])) {
                    $action         = $order_info['action'] ?? '';
                    $payment_result = $apiProcessor->getPaymentResult();

                    if ($payment_result['state'] === 'FAILED') {
                        $this->orderStatus = $this->vodapay::VP_DECLINED;
                    }

                    $this->vodapayState = $payment_result['state'] ?? '';

                    $apiProcessor->processPaymentAction($action, $this->vodapayState);

                    $data['redirect_url'] = $this->processOrder($order, $apiProcessor, $action);
                }
            } else {
                $data['error'] = $order_info;
            }
        } else {
            $data['error'] = 'Error! Order not found';
        }

        return $data;
    }

    /**
     * @param $vodapayReference
     *
     * @return array|string|bool
     */
    public function getVodaPayOrder($vodapayReference): array|string|bool
    {
        $request = new Request($this->vodapay);

        $httpTransfer = new NgeniusHTTPTransfer("");

        $httpTransfer->setTokenHeaders($this->vodapay->getApiKey());
        $httpTransfer->setHttpVersion($this->vodapay->getHttpVersion());

        $httpTransfer->build($request->tokenRequest());

        $token = NgeniusHTTPCommon::placeRequest($httpTransfer);

        if (is_string($token)) {
            $token = Validate::tokenValidate($token);

            $httpTransfer->setPaymentHeaders($token);

            $httpTransfer->build($request->fetchOrder($vodapayReference));

            return Validate::orderValidate(
                NgeniusHTTPCommon::placeRequest($httpTransfer)
            );
        }

        return false;
    }

    /**
     * @param $orders
     *
     * @return void
     */
    public function processCron($orders): void
    {
        $this->vodapay = new VodaPayTools($this->registry);

        $this->vodapay->debug('VODAPAY: Cron started');
        $this->vodapay->debug('VODAPAY: Found ' . sizeof($orders) . ' unprocessed order(s)');

        if (empty($orders)) {
            $this->vodapay->debug('VODAPAY: Cron ended');

            return;
        }

        $this->load->model(self::CHECKOUT_LITERAL);

        $counter = 0;

        foreach ($orders as $vodapayOrder) {
            if ($counter >= 5) {
                $this->vodapay->debug('VODAPAY: Breaking loop at 5 orders to avoid timeout');
                break;
            }

            try {
                $this->vodapay->debug('VODAPAY: Processing order #' . $vodapayOrder['order_id']);
                $vodapayOrder['status'] = 'vodapay-Cron';
                $this->updateTable($vodapayOrder, $vodapayOrder['nid']);
                $vodapayReference = $vodapayOrder['reference'];
                $orderInfo        = $this->getVodaPayOrder($vodapayReference);

                if (isset($orderInfo[self::EMBEDDED_LITERAL]['payment'])
                    && is_array($orderInfo[self::EMBEDDED_LITERAL]['payment'])
                ) {
                    $paymentResult = $orderInfo[self::EMBEDDED_LITERAL]['payment'][0];
                    $action        = $orderInfo['action'] ?? '';

                    $this->vodapayState = $paymentResult['state'] ?? '';
                    $apiProcessor       = new ApiProcessor($orderInfo);
                    $apiProcessor->processPaymentAction($action, $this->vodapayState);

                    $this->vodapay->debug('VODAPAY: State is ' . $vodapayOrder['state']);

                    $this->load->model('checkout/order');

                    $order = $this->model_checkout_order->getOrder($vodapayOrder['order_id']);

                    $this->session->data['order_id'] = $vodapayOrder['order_id'];

                    if ($apiProcessor->isPaymentAbandoned()) {
                        $paymentResult['state'] = self::VODAPAY_FAILED;
                        $this->orderStatus      = $this->vodapay::VP_DECLINED;
                    }

                    $this->processOrder($order, $apiProcessor, $action);
                } else {
                    $this->vodapay->debug('VODAPAY: Payment result not found');
                }
            } catch (\Exception $error) {
                $this->vodapay->debug('VODAPAY: Exception ' . $error->getMessage());
            }
            $counter++;
        }
        $this->vodapay->debug('VODAPAY: Cron ended');
    }
}
