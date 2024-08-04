<?php
class Klaviyo_Integration {
    private $klaviyo_public_key;
    private $logger;

    public function __construct() {
        $this->klaviyo_public_key = get_option('klaviyo_public_api_key', '');
        $this->logger = new WC_Logger();
    }

    public function init() {
        // Add hooks for subscription events
        add_action('woocommerce_subscription_status_active', array($this, 'handle_subscription_status_active'), 10, 1);
        add_action('woocommerce_subscription_status_on-hold', array($this, 'handle_subscription_status_on_hold'), 10, 1);
        add_action('woocommerce_subscription_status_cancelled', array($this, 'handle_subscription_status_cancelled'), 10, 1);
        add_action('woocommerce_subscription_renewal_payment_complete', array($this, 'handle_subscription_renewal_payment_complete'), 10, 2);
        add_action('woocommerce_subscription_status_updated', array($this, 'handle_subscription_status_updated'), 10, 3);
        add_action('woocommerce_subscription_status_expired', array($this, 'handle_subscription_status_expired'), 10, 1);
    }

    public function handle_subscription_status_active($subscription) {
        $this->send_subscription_event_to_klaviyo($subscription, 'active');
    }

    public function handle_subscription_status_on_hold($subscription) {
        $this->send_subscription_event_to_klaviyo($subscription, 'on-hold');
    }

    public function handle_subscription_status_cancelled($subscription) {
        $this->send_subscription_event_to_klaviyo($subscription, 'cancelled');
    }

    public function handle_subscription_renewal_payment_complete($subscription, $order) {
        $this->send_subscription_event_to_klaviyo($subscription, 'renewed', null, $order->get_order_number());
    }

    public function handle_subscription_status_updated($subscription, $old_status, $new_status) {
        // Log status changes to debug
        $this->logger->add('klaviyo_integration', "Status updated from $old_status to $new_status for subscription {$subscription->get_id()}");
    
        // Fetch the actual status from the subscription object
        $current_status = $subscription->get_status();
    
        // Additional logging to understand the current state
        $this->logger->add('klaviyo_integration', "Current status: $current_status for subscription {$subscription->get_id()}");
    
        // Skip sending "updated" events for specific statuses
        if ($new_status == 'cancelled' || $current_status == 'cancelled' ||
            $new_status == 'on-hold' || $current_status == 'on-hold' ||
            $new_status == 'expired' || $current_status == 'expired' || $current_status == 'active' ||
            ($old_status == 'active' && $new_status == 'active') ||
            ($old_status == 'on-hold' && $new_status == 'active')) {
            $this->logger->add('klaviyo_integration', "Skipping update event for subscription {$subscription->get_id()}");
            return;
        } else {
            $this->logger->add('klaviyo_integration', "Sending update event for subscription {$subscription->get_id()}");
            $this->send_subscription_event_to_klaviyo($subscription, 'updated', $old_status);
        }
    }
    

    public function handle_subscription_status_expired($subscription) {
        $this->send_subscription_event_to_klaviyo($subscription, 'expired');
    }

    private function send_subscription_event_to_klaviyo($subscription, $event_type, $old_status = null, $successful_order_id = null) {

        $subscription_id = $subscription->get_id();
        $subscription_status = $subscription->get_status();
        $subscription_start_date = $this->get_start_date($subscription);
        $todayStartTS = date('Y-m-d', time());
        
        $wc_event_name = $this->get_event_name($subscription_status, $subscription_start_date, $todayStartTS, $event_type);

        $line_items_array = $this->get_line_items($subscription);
        $shipping_line_array = $this->get_shipping_lines($subscription);

        $last_payment_date = $this->get_last_payment_date($subscription);
        $next_payment_date = $this->get_next_payment_date($subscription);
        $subscription_end_date = $this->get_end_date($subscription);
        $subscription_cancelled_date = $this->get_cancelled_date($subscription);
        $payment_url = $this->get_payment_url($subscription);

        $random_event_id = $this->generate_random_string();

        $klaviyo_metric_payload = array(
            "token" => $this->klaviyo_public_key,
            "event" => $wc_event_name,
            "customer_properties" => array(
                '$email' => $subscription->get_billing_email()
            ),
            "properties" => array(
                '$event_id' => $subscription_id . "-" . $subscription_status . "-" . $random_event_id,
                "SubscriptionID" => $subscription_id,
                "BillingPeriod" => $subscription->get_billing_period(),
                "BillingInterval" => $subscription->get_billing_interval(),
                "OriginOrderID" => $subscription->get_parent_id(),
                "Status" => $subscription_status,
                "SubscriptionValue" => $subscription->get_total(),
                "ShippingTotal" => $subscription->get_shipping_total(),
                "Shipping" => $shipping_line_array,
                "PaymentMethod" => $subscription->get_payment_method_title(),
                "CustomerID" => $subscription->get_customer_id(),
                "DateCreated" => $subscription_start_date,
                "Items" => $line_items_array,
                "NextPayment" => $next_payment_date,
                '$value' => $subscription->get_total(),
                "SubscriptionUrl" => $subscription->get_view_order_url(),
                "PaymentUrl" => $payment_url,
                "LastPaymentDate" => $last_payment_date,
            ),
        );

        if ($event_type == 'renewed') {
            $klaviyo_metric_payload['properties']['OrderNumber'] = $successful_order_id;
        }

        if ($event_type == 'cancelled') {
            $klaviyo_metric_payload['properties']['EndDate'] = $subscription_end_date;
            $klaviyo_metric_payload['properties']['CancelledDate'] = $subscription_cancelled_date;
        }

        if ($event_type == 'updated') {
            $klaviyo_metric_payload['properties']['PreviousStatus'] = $old_status;
            $klaviyo_metric_payload['properties']['CurrentStatus'] = $subscription_status;
            $klaviyo_metric_payload['properties']['EndDate'] = $subscription_end_date;
            $klaviyo_metric_payload['properties']['CancelledDate'] = $subscription_cancelled_date;            
        }

        if ($event_type == 'expired') {
            $klaviyo_metric_payload['properties']['EndDate'] = $subscription_end_date;
            $klaviyo_metric_payload['properties']['ExpiredDate'] = $subscription_end_date;
        }

        $this->send_to_klaviyo($klaviyo_metric_payload);
    }

    private function generate_random_string($length = 5) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $characters_length = strlen($characters);
        $random_string = '';
        for ($i = 0; $i < $length; $i++) {
            $random_string .= $characters[rand(0, $characters_length - 1)];
        }
        return $random_string;
    }    

    private function get_event_name($status, $subscription_start_date, $today, $event_type) {
        if ($event_type == "active" && $subscription_start_date == $today) {
            return "WC Subscription Created";
        } else if ($event_type == "renewed") {
            return "WC Subscription Renewed";
        } else if ($event_type == "cancelled") {
            return "WC Subscription Cancelled";
        } else if ($event_type == "on-hold") {
            return "WC Subscription Paused";
        } else if ($event_type == "expired") {
            return "WC Subscription Expired";
        } else {
            return "WC Subscription Updated";
        }
    }

    private function get_line_items($subscription) {
        $line_items = array();
        foreach ($subscription->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $line_items[] = array(
                "ProductName" => $item->get_name(),
                "Quantity" => $item->get_quantity(),
                "Subtotal" => $item->get_subtotal(),
                "ImageURL" => wp_get_attachment_url($product->get_image_id()),
            );
        }
        return $line_items;
    }

    private function get_shipping_lines($subscription) {
        $shipping_lines = array();
        foreach ($subscription->get_shipping_methods() as $shipping) {
            $shipping_lines[] = array(
                "MethodName" => $shipping->get_method_title(),
                "Total" => $shipping->get_total()
            );
        }
        return $shipping_lines;
    }

    private function get_start_date($subscription) {
        $start_date = $subscription->get_date('start', 'site');
        return $start_date ? date('Y-m-d', strtotime($start_date)) : '';
    }    

    private function get_end_date($subscription) {
        $start_date = $subscription->get_date('end', 'site');
        return $start_date ? date('Y-m-d', strtotime($start_date)) : '';
    }    
    
    private function get_cancelled_date($subscription) {
        $start_date = $subscription->get_date('cancelled', 'site');
        return $start_date ? date('Y-m-d', strtotime($start_date)) : '';
    }        

    private function get_last_payment_date($subscription) {
        $last_payment_date = $subscription->get_date('last_payment', 'site');
        return $last_payment_date ? date('Y-m-d', strtotime($last_payment_date)) : '';
    }

    private function get_next_payment_date($subscription) {
        $next_payment_date = $subscription->get_date('next_payment', 'site');
        return $next_payment_date ? date('Y-m-d', strtotime($next_payment_date)) : '';
    }

    private function get_payment_url($subscription) {
        $related_orders = $subscription->get_related_orders();
        foreach ($related_orders as $order_id) {
            $order = wc_get_order($order_id);
            if ($order->has_status(array('failed', 'pending'))) {
                return $order->get_checkout_payment_url();
            }
        }

        // Default payment URL
        return site_url('/my-account/subscriptions');
    }

    private function send_to_klaviyo($payload) {
        $url = 'https://a.klaviyo.com/api/track';
        $args = array(
            'body'        => json_encode($payload),
            'headers'     => array(
                'Content-Type' => 'application/json'
            ),
            'timeout'     => 60,
            'data_format' => 'body',
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $this->logger->add('klaviyo_integration', 'Error sending to Klaviyo: ' . $response->get_error_message());
        } else {
            $this->logger->add('klaviyo_integration', 'Successfully sent to Klaviyo: ' . wp_remote_retrieve_body($response));
        }
    }
}
