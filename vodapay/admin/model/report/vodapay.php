<?php

namespace Opencart\Admin\Model\Extension\VodaPay\Report;

require_once DIR_EXTENSION . 'vodapay/system/library/vodapay.php';

class VodaPay extends Model
{
    public function getQuery($data = array())
    {
        $sql = "";
        $sql .= " WHERE nid > 0";

        if (!empty($data['filter_order_id'])) {
            $sql .= " and order_id LIKE '%" . (int)$data['filter_order_id'] . "%'";
        }

        if (!empty($data['filter_amount'])) {
            $sql .= " and amount LIKE '%" . (int)$data['filter_amount'] . "%'";
        }

        if (!empty($data['filter_action'])) {
            $sql .= " and action = '" . $data['filter_action'] . "'";
        }

        if (!empty($data['filter_status'])) {
            $sql .= " and status = '" . $data['filter_status'] . "'";
        }

        if (!empty($data['filter_state'])) {
            $sql .= " and state = '" . $data['filter_state'] . "'";
        }

        if (!empty($data['filter_payment_id'])) {
            $sql .= " and payment_id LIKE '%" . $data['filter_payment_id'] . "%'";
        }

        if (!empty($data['filter_reference'])) {
            $sql .= " and reference LIKE '%" . $data['filter_reference'] . "%'";
        }

        if (!empty($data['filter_captured_amt'])) {
            $sql .= " and captured_amt LIKE '%" . (int)$data['filter_captured_amt'] . "%'";
        }

        return $sql . " ORDER BY nid DESC";
    }

    public function getOrders($data = array())
    {
        $sql = "SELECT * FROM " . DB_PREFIX . "vodapay_vodapay";
        $sql .= $this->getQuery($data);
        if (isset($data['start']) || isset($data['limit'])) {
            if ($data['start'] < 0) {
                $data['start'] = 0;
            }

            if ($data['limit'] < 1) {
                $data['limit'] = 2;
            }
            $sql .= " LIMIT " . (int)$data['start'] . "," . (int)$data['limit'];
        }
        $query = $this->db->query($sql);

        return $query->rows;
    }

    public function getTotalOrders($data = array())
    {
        $sql   = "SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "vodapay_vodapay`";
        $sql   .= $this->getQuery($data);
        $query = $this->db->query($sql);

        return $query->row['total'];
    }

    public function getVodaPayOrderSatuts()
    {
        $vodapay = new \Opencart\System\Library\VodaPay($this->registry);

        return array(
            $vodapay::VP_PENDING    => $vodapay::VP_PENDING,
            $vodapay::VP_PROCESSING => $vodapay::VP_PROCESSING,
            $vodapay::VP_FAILED     => $vodapay::VP_FAILED,
            $vodapay::VP_COMPLETE   => $vodapay::VP_COMPLETE,
            $vodapay::VP_AUTHORISED => $vodapay::VP_AUTHORISED,
            $vodapay::VP_F_CAPTURED => $vodapay::VP_F_CAPTURED,
            $vodapay::VP_P_CAPTURED => $vodapay::VP_P_CAPTURED,
            $vodapay::VP_AUTH_REV   => $vodapay::VP_AUTH_REV,
            $vodapay::VP_F_REFUNDED => $vodapay::VP_F_REFUNDED,
            $vodapay::VP_P_REFUNDED => $vodapay::VP_P_REFUNDED
        );
    }
}
