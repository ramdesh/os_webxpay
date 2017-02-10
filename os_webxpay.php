<?php
/**
 * @package            Joomla
 * @subpackage         Event Booking
 * @author             Tuan Pham Ngoc
 * @copyright          Copyright (C) 2010 - 2016 Ossolution Team
 * @license            GNU/GPL, see LICENSE.php
 */
// no direct access
defined('_JEXEC') or die;

include_once(JPATH_COMPONENT_SITE . '/payments/RSA.php');

class os_webxpay extends RADPayment
{
    var $_rsa;

    var $_response;

    var $_customFields;
    /**
     * Constructor functions, init some parameter
     *
     * @param object $params
     */
    public function __construct($params, $config = array())
    {
        parent::__construct($params, $config);

        $this->url = 'https://webxpay.com/index.php?route=checkout/billing';

        $this->setTitle("online payment portal");

        $this->_rsa = new Crypt_RSA();

        $this->_rsa->loadKey("-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDS1RX2K6l1J4VsjcWYBp59o098
D+D4QDOoeDdxJ0JqBUcW7DwjAkaKC1eBXy31FQP8B7qxEHqFqGWN4OsxMaJuPTwN
sD+DGnVXRU5ANRhv5YCuQhdvHiEW72MmVoDpiMn5pKZseS8o6HiP9DGRHkkq7xHn
IOmFfmGjPhgQNf0jvwIDAQAB
-----END PUBLIC KEY-----");

        $this->setParameter('secret_key', $params->get('secret'));

    }

    /**
     * Process Payment
     *
     * @param object $row
     * @param array  $data
     */
    public function processPayment($row, $data)
    {
        $Itemid  = JFactory::getApplication()->input->getInt('Itemid', 0);
        $siteUrl = JUri::base();

        $event = EventbookingHelperDatabase::getEvent($row->event_id);

        $payment = base64_encode($this->_rsa->encrypt($row->id . '|' . round($data['amount'], 2)));

        $this->setParameter('process_currency', $data['currency']);
        $this->setParameter('item_name', $data['item_name']);
        $this->setParameter('payment', $payment);
        $this->setParameter('custom_fields', base64_encode($row->id . '|' .$Itemid));


        $this->setParameter('return', $siteUrl . 'index.php?option=com_eventbooking&view=complete&Itemid=' . $Itemid);

        /*$this->setParameter('cancel_return', $siteUrl . 'index.php?option=com_eventbooking&task=cancel&id=' . $row->id . '&Itemid=' . $Itemid);
        $this->setParameter('notify_url', $siteUrl . 'index.php?option=com_eventbooking&task=payment_confirm&payment_method=os_webxpay');*/
        $this->setParameter('address_line_one', $row->address);
        $this->setParameter('address_line_two', $row->address2);
        $this->setParameter('city', $row->city);
        $this->setParameter('country', 'Sri Lanka');
        $this->setParameter('first_name', $row->first_name);
        $this->setParameter('last_name', $row->last_name);
        $this->setParameter('state', $row->state);
        $this->setParameter('postal_code', $row->zip);
        $this->setParameter('email', $row->email);
        $this->setParameter('contact_number', $row->phone);

        $this->renderRedirectForm();
    }

    /**
     * Verify payment
     *
     * @return bool
     */
    public function verifyPayment()
    {
        $ret = $this->validate();
        if ($ret)
        {
            $id            = $this->_customFields[0];
            $transactionId = $this->_response[0];
            $row = JTable::getInstance('EventBooking', 'Registrant');
            $row->load($id);
            if (!$row->id)
            {
                return false;
            }
            if ($row->published && $row->payment_status)
            {
                return false;
            }

            $this->onPaymentSuccess($row, $transactionId);

            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * Get list of supported currencies
     *
     * @return array
     */
    public function getSupportedCurrencies()
    {
        return array(
            'LKR',
            'USD',
        );
    }

    /**
     * Validate the post data from paypal to our server
     *
     * @return string
     */
    protected function validate()
    {

        $this->notificationData = $_POST;
        $this->_customFields = explode('|', base64_decode($_POST['custom_fields']));
        $this->_response = explode('|', base64_decode($_POST['payment']));

        if($this->_response[4] != '00' || !isset($this->_customFields[0])) {
            return false;
        }
        return true;
    }
}
