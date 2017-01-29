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

include_once(JPATH_COMPONENT . '/payments/RSA.php');

class os_webxpay extends RADPayment
{
    var $_rsa;
    /**
     * Constructor functions, init some parameter
     *
     * @param object $params
     */
    public function __construct($params, $config = array())
    {
        parent::__construct($params, $config);

        $this->url = 'https://webxpay.com/index.php?route=checkout/billing';

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

        $this->setParameter('cancel_return', $siteUrl . 'index.php?option=com_eventbooking&task=cancel&id=' . $row->id . '&Itemid=' . $Itemid);
        $this->setParameter('notify_url', $siteUrl . 'index.php?option=com_eventbooking&task=payment_confirm&payment_method=os_webxpay');
        $this->setParameter('address_line_one', $row->address);
        $this->setParameter('address_line_two', $row->address2);
        $this->setParameter('city', $row->city);
        $this->setParameter('country', $data['country']);
        $this->setParameter('first_name', $row->first_name);
        $this->setParameter('last_name', $row->last_name);
        $this->setParameter('state', $row->state);
        $this->setParameter('postal_code', $row->postal_code);
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
            $id            = $this->notificationData['custom'];
            $transactionId = $this->notificationData['txn_id'];
            $amount        = $this->notificationData['mc_gross'];
            if ($amount < 0)
            {
                return false;
            }
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
        $errNum                 = "";
        $errStr                 = "";
        $urlParsed              = parse_url($this->url);
        $host                   = $urlParsed['host'];
        $path                   = $urlParsed['path'];
        $postString             = '';
        $response               = '';
        $this->notificationData = $_POST;
        foreach ($_POST as $key => $value)
        {
            $postString .= $key . '=' . urlencode(stripslashes($value)) . '&';
        }
        $postString .= 'cmd=_notify-validate';
        $fp = fsockopen($host, '80', $errNum, $errStr, 30);
        if (!$fp)
        {
            $response = 'Could not open SSL connection to ' . $this->url;
            $this->logGatewayData($response);

            return false;
        }
        fputs($fp, "POST $path HTTP/1.1\r\n");
        fputs($fp, "Host: $host\r\n");
        fputs($fp, "User-Agent: Events Booking\r\n");
        fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
        fputs($fp, "Content-length: " . strlen($postString) . "\r\n");
        fputs($fp, "Connection: close\r\n\r\n");
        fputs($fp, $postString . "\r\n\r\n");
        while (!feof($fp))
        {
            $response .= fgets($fp, 1024);
        }
        fclose($fp);
        $this->logGatewayData($response);

        if (!$this->mode || (stristr($response, "VERIFIED") && ($this->notificationData['payment_status'] == 'Completed')))
        {
            return true;
        }

        return false;
    }
}
