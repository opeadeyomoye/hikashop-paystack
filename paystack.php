<?php
/**
 * @package     Paystack Payment plugin for Hikashop
 * @author      Ope Adeyomoye <victoradeyomoye@yahoo.co.uk>
 * @version     1.5
 * @license     MIT License. See LICENSE file for more info.
*/

class plgHikashoppaymentPaystack extends hikashopPaymentPlugin
{
    const PAYSTACK_STANDARD_API_URL = 'https://api.paystack.co/transaction/initialize';
    const PAYSTACK_VERIFY_URL = 'https://api.paystack.co/transaction/verify/';
    
    public $app = null;
    public $name = 'paystack';
    public $multiple = true;
    public $accepted_currencies = array('NGN');
    
    public $pluginConfig = array(
        'test_mode' => array('Are you in test mode?', 'boolean', '1'),
        'test_auth_key' => array('Paystack TEST public key (or secret key, but public recommended)', 'textarea'),
        'live_auth_key' => array('Paystack LIVE public key (or secret key, but public recommended)', 'textarea'),
        'redirect_url' => array('Redirect URL after payment is confirmed (can be left empty).', 'textarea'),
        'invalid_status' => array('Invalid status', 'orderstatus'),
        'verified_status' => array('Verified status', 'orderstatus'),
    );
    
    
    /**
     * Class constructor
     *
     * Used to assign JApplication to $this->app
     */
    public function __construct(&$subject, $config)
    {
        $this->app = JFactory::getApplication();
        
        return parent::__construct($subject, $config);
    }
    
    
    /**
     * onAfterOrderConfirm hook
     *
     * Validates plugin settings and tries to get the payment URL from Paystack
     */
    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);
        
        if (
            ( ($this->payment_params->test_mode == 1) && empty($this->payment_params->test_auth_key) ) ||
            ( ($this->payment_params->test_mode == 0) && empty($this->payment_params->live_auth_key) )
        ) {
            $this->app->enqueueMessage("Your vendor's Paystack payment configurations seem to be incomplete but your order has been created.<br />Please contact the site administrator to fix this");
        }
        else {
            // get customer (for email)
            $user = JFactory::getUser();
            
            // paystack needs amount in kobo
            $amount = $order->order_full_price * 100;
            
            // callback url
            $callbackUrl = htmlspecialchars(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment='.$this->name.'&tmpl=component');

            // generate reference
            $reference = $this->generateReference($order);
            
            // get authorization url
            $params = array(
                'amount' => $amount,
                'email' => $user->email,
                'reference' => $reference,
                //'callback_url' => $callbackUrl,
            );
            
            $url = $this->getAuthorizationUrl($params);
            
            // if we got the url, redirect there
            if ($url !== false) {
                $this->app->redirect($url);
            } else {
                $this->app->enqueueMessage("We are unable to process your payment via Paystack at this time, but your order has been created.<br />
                                           Please contact the site administrator for assistance.");
            }
        }
        
        $this->showPage('end');
    }
    
    
    public function generateReference($order)
    {
        $cart = $order->cart;
        
        return base64_encode($order->order_id.":".$order->order_number.":".$order->order_created.":".$order->order_full_price);
    }
    
    
    public function getAuthorizationUrl($params)
    {
        $ch = curl_init();
        
        $headers = ["Content-Type: application/json", "Authorization: Bearer " . $this->getAuthKey()];
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_URL, self::PAYSTACK_STANDARD_API_URL);
        
        $result = curl_exec($ch);
        
        if ($result === false) {
            return false;
        }
        
        $body = json_decode($result);
        
        if (is_object($body)) {
            if (property_exists($body, 'status')) {
                if ($body->status == true) {
                    return $body->data->authorization_url;
                }
            }
        }
        
        return false;
    }
    
    
    public function getAuthKey()
    {
        if ($this->payment_params->test_mode == '1') {
            return $this->payment_params->test_auth_key;
        } else {
            return $this->payment_params->live_auth_key;
        }
    }
    
    
    public function onPaymentNotification(&$statuses)
    {
        parent::onPaymentNotification($statuses);

        // get 'n filter the data received
        $data = array();
        
        $filter = JFilterInput::getInstance();
        
        foreach($_REQUEST as $key => $value)
        {
            $key = $filter->clean($key);
            
            $value = JRequest::getString($key);
            
            $data[$key] = $value;
        }
        
        if (isset($data['trxref'])) {
            
            $status = $this->verifyPayment($data['trxref']);
            
            $orderData = explode(':', base64_decode($data['trxref']));
            $orderId = $orderData[0];
            
            if ($status === true) {
                
                // change the order status
                $this->modifyOrder($orderId, $this->payment_params->verified_status, true, true);
                
                if ($this->payment_params->redirect_url) {
                    $this->app->redirect($this->payment_params->redirect_url);
                }
                $this->app->redirect(htmlspecialchars(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id='.$orderId));
            } else {
                $this->app->enqueueMessage("We were unable to verify your transaction.<br />If you have already completed the payment process on Paystack, please contact the site administrator for assistance", 'error');
                return false;
            }
        }
    
        $this->app->enqueueMessage("We were unable to verify your transaction.<br />If you have already completed the payment process on Paystack, please contact the site administrator for assistance", 'error');
        
        return false;
    }
    
    
    public function verifyPayment($reference) {
        
        {// found that you could do this without penalties :)
            // extract order data to load plugin config
            $orderData = explode(':', base64_decode($reference));
            
            // offset 0 should contain order_id, 2 should contain the order_full_price
            $orderId = $orderData[0];
            $orderFullPrice = $orderData[2];
            
            // load plugin params and order details
            $dbOrder = $this->getOrder($orderId);
            
            $this->loadPaymentParams($dbOrder);
            $this->loadOrderData($dbOrder);
        }

        $result = $this->getPaymentInfo($reference);
        
        $data = @json_decode($result);
        
        if (is_object($data)) {
            if (property_exists($data, 'status')) {
                if ($data->status == true) {
                    
                    // compare order price to amount paid
                    $amountPaid = $data->data->amount / 100; // paystack sends amount in kobo
                    
                    if (($amountPaid - $dbOrder->order_full_price) >= 0) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    
    public function getPaymentInfo($reference) {
        $ch = curl_init();
        
        $headers = ["Authorization: Bearer " . $this->getAuthKey()];
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, self::PAYSTACK_VERIFY_URL . $reference);
        
        return curl_exec($ch);
    }
    
    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name          = 'Paystack Webpay';
        $element->payment_images        = 'Paystack';
        $element->payment_description   = 'Pay with your MasterCard, VISA and Verve Cards via Paystack';
        $element->payment_params->order_status  = 'created';
        
    }
}

?>
