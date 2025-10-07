<?php

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Juleb_Order_Sync {

    private $api_handler;
    private $customer_sync;

    public function __construct() {
        $this->api_handler = new Juleb_API_Handler();
        $this->customer_sync = new Juleb_Customer_Sync();
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'sync_new_order' ), 10, 1 );
    }

    private function log_order_message($level, $order_id, $message, $context = []) {
        $context['order_id'] = $order_id;
        $this->api_handler->log($level, $message, $context);
    }

    public function sync_new_order( $order_id ) {
        $this->log_order_message('INFO', $order_id, '-- Starting Order Sync --');

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $this->log_order_message('ERROR', $order_id, 'Could not retrieve WC_Order object.');
            return;
        }

        // DEBUG LOGGING START
        $this->log_order_message('DEBUG', $order_id, 'Order Shipping Details', [
            'shipping_country' => $order->get_shipping_country(),
            'shipping_state' => $order->get_shipping_state(),
            'shipping_city' => $order->get_shipping_city(),
            'shipping_postcode' => $order->get_shipping_postcode(),
        ]);
        // DEBUG LOGGING END

        $options = get_option('juleb_sync_options');
        $branch_id = null;

        // --- City-Based Routing (Primary) ---
        $city_map_settings = $options['city_map'] ?? [];
        $city_to_branch_map = [];
        foreach ($city_map_settings as $map) {
            if (!empty($map['city'])) {
                $city_to_branch_map[$map['city']] = $map['branch'];
            }
        }

        $shipping_city = strtoupper($order->get_shipping_city());
        $branch_id = $city_to_branch_map[$shipping_city] ?? null;

        $this->log_order_message('DEBUG', $order_id, 'Juleb Sync: Attempting City-Based Routing', [
            'city_map_from_settings' => $city_to_branch_map,
            'customer_shipping_city' => $shipping_city,
            'determined_branch_id' => $branch_id,
        ]);

        // --- Zone-Based Routing (Fallback) ---
        if (empty($branch_id)) {
            $this->log_order_message('INFO', $order_id, 'No match in City Routing. Falling back to Zone Routing.');
            
            $shipping_zone_map = $options['shipping_zone_map'] ?? [];
            if (!empty($shipping_zone_map)) {
                $package = $this->get_package_from_order($order);
                $shipping_zone = WC_Shipping_Zones::get_zone_matching_package($package);
                $zone_id = $shipping_zone->get_id();
                $branch_id = $shipping_zone_map[$zone_id] ?? null;

                $this->log_order_message('DEBUG', $order_id, 'Juleb Sync: Attempting Zone-Based Routing (Fallback)', [
                    'zone_map_from_settings' => $shipping_zone_map,
                    'customer_zone_id' => $zone_id,
                    'determined_branch_id' => $branch_id,
                ]);
            }
        }

        if (empty($branch_id)) {
            $this->log_order_message('ERROR', $order_id, 'Could not determine Juleb branch. No mapping found for the shipping city or zone.', ['shipping_city' => $shipping_city]);
            $order->add_order_note('Juleb Sync Failed: Could not determine Juleb branch for the customer\'s city or shipping zone.');
            return;
        }
        
        update_post_meta($order_id, '_juleb_branch_id', $branch_id);
        $all_companies = $this->api_handler->get_companies();
        $branch_name = 'Unknown';
        if (!is_wp_error($all_companies) && isset($all_companies['data'])) {
            foreach($all_companies['data'] as $company) {
                if ($company['id'] == $branch_id) {
                    $branch_name = $company['name'];
                    break;
                }
            }
        }

        $this->log_order_message('INFO', $order_id, 'Determined Juleb branch for order.', ['branch_id' => $branch_id, 'branch_name' => $branch_name]);
        $order->add_order_note(sprintf('Juleb Branch \'%s\' assigned to order.', $branch_name));

        // Step 2: Get Branch-Specific Session and Payment Method
        $branch_settings = $options['branches'][$branch_id] ?? [];
        $juleb_session = $branch_settings['session'] ?? null;

        if (empty($juleb_session)) {
            $this->log_order_message('ERROR', $order_id, 'Juleb Session is not set for the determined branch.', ['branch_id' => $branch_id]);
            $order->add_order_note(sprintf('Juleb Sync Failed: Juleb Session is not configured for the \'%s\' branch.', $branch_name));
            return;
        }

        $payment_method_key = $order->get_payment_method();
        $payment_id_option_key = 'payment_map_' . $payment_method_key;
        $juleb_payment_id = $branch_settings[$payment_id_option_key] ?? null;

        if (empty($juleb_payment_id)) {
            $this->log_order_message('ERROR', $order_id, 'Juleb Payment Method ID is not mapped for the current payment method in the determined branch.', [
                'payment_key' => $payment_method_key,
                'branch_id' => $branch_id
            ]);
            $order->add_order_note(sprintf('Juleb Sync Failed: Payment method is not mapped for the \'%s\' branch.', $branch_name));
            return;
        }

        // Step 3: Get or Create Juleb Partner ID (Unchanged)
        $partner_id = $this->get_juleb_partner_id_for_order($order);
        if ( ! $partner_id ) {
            $this->log_order_message('WARNING', $order_id, 'Could not get or create a Juleb partner. Order will not be synced.');
            return; 
        }

        // Step 4: Create POS Session
        $session_response = $this->api_handler->create_pos_session($juleb_session);
        if ( is_wp_error( $session_response ) || empty($session_response) ) {
            $this->log_order_message('ERROR', $order_id, 'Failed to create POS session.', ['juleb_session_id' => $juleb_session]);
            return;
        }
        $pos_session_id = intval($session_response);
        $this->log_order_message('INFO', $order_id, 'Successfully created POS session.', ['pos_session_id' => $pos_session_id]);

        // Step 5: Prepare Line Items (Unchanged)
        $juleb_lines = $this->prepare_juleb_lines($order);
        if ( is_wp_error($juleb_lines) ) {
            $this->log_order_message('ERROR', $order_id, 'Failed to prepare order lines.', ['error' => $juleb_lines->get_error_message()]);
            return;
        }

        // Step 6: Construct Final Payload
        $order_data = [
            'pos_session_id'    => $pos_session_id,
            'pricelist_id'      => 1, // Assuming default pricelist
            'payment_method_id' => intval($juleb_payment_id),
            'partner_id'        => $partner_id,
            'lines'             => $juleb_lines,
        ];

        $this->log_order_message('INFO', $order_id, 'Final payload prepared.', $order_data);

        // Step 7: Create the order
        $response = $this->api_handler->create_order( $order_data );

        if ( is_wp_error( $response ) ) {
            $this->log_order_message('ERROR', $order_id, 'Failed to sync order to Juleb.');
            $order->add_order_note('Juleb Sync Failed. Check the sync logs for more details.');
        } else {
            $juleb_order_ref = isset($response[0]['pos_reference']) ? $response[0]['pos_reference'] : 'N/A';
            $order->add_order_note( 'Order successfully synced to Juleb. Juleb Reference: ' . $juleb_order_ref );
            $this->log_order_message('INFO', $order_id, 'SUCCESS! Order successfully synced to Juleb.', ['juleb_ref' => $juleb_order_ref]);
        }
        $order->save();
    }

    private function get_package_from_order($order) {
        return [
            'destination' => [
                'country'   => $order->get_shipping_country(),
                'state'     => $order->get_shipping_state(),
                'postcode'  => $order->get_shipping_postcode(),
                'city'      => $order->get_shipping_city(),
            ],
        ];
    }

    private function get_juleb_partner_id_for_order($order) {
        $partner_id = null;
        $customer_id = $order->get_customer_id();
        $order_id = $order->get_id();

        if ($customer_id > 0) {
            $customer = new WC_Customer( $customer_id );
            $partner_id = $this->customer_sync->get_or_create_juleb_partner_id( $customer );
        } else {
            $this->log_order_message('INFO', $order_id, 'Order is from a guest. Attempting to get or create a Juleb partner from order details.');
            $guest_email = $order->get_billing_email();
            $response = $this->api_handler->find_partner_by_email( $guest_email );
            if ( ! is_wp_error( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
                foreach ( $response['data'] as $partner_data ) {
                    if ( isset( $partner_data['email']) && strtolower( trim($partner_data['email']) ) === strtolower( trim($guest_email) ) ) {
                        $partner_id = $partner_data['id'];
                        $this->log_order_message( 'INFO', $order_id, 'Found matching partner by email for guest.', ['partner_id' => $partner_id] );
                        break;
                    }
                }
            }

            if ( ! $partner_id ) {
                $this->log_order_message( 'INFO', $order_id, 'Partner not found in Juleb for guest, creating a new one.' );
                $partner_data = array(
                    'name'      => $order->get_formatted_billing_full_name(),
                    'email'     => $guest_email,
                    'mobile'    => $order->get_billing_phone(),
                    'phone'     => $order->get_billing_phone(),
                    'street'    => $order->get_billing_address_1(),
                    'street2'   => $order->get_billing_address_2(),
                    'city'      => $order->get_billing_city(),
                    'zip'       => $order->get_billing_postcode(),
                );
                $creation_response = $this->api_handler->create_partner( $partner_data );
                if ( is_wp_error( $creation_response ) ) {
                    $this->log_order_message( 'ERROR', $order_id, 'Failed to create Juleb partner for guest.' );
                } else {
                    $new_partner_id = $creation_response['id'] ?? $creation_response['data']['id'] ?? (is_numeric($creation_response) ? $creation_response : null);
                    if ( $new_partner_id ) {
                        $partner_id = $new_partner_id;
                        $this->log_order_message( 'INFO', $order_id, 'Successfully created new Juleb partner for guest.', ['new_partner_id' => $new_partner_id] );
                    }
                }
            }
        }
        return $partner_id;
    }

    private function prepare_juleb_lines($order) {
        $juleb_lines = array();
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $sku = $product->get_sku();
            if ( ! $sku ) {
                return new WP_Error('missing_sku', sprintf('A product in the order (ID: %d) is missing an SKU.', $product->get_id()));
            }

            $juleb_product_response = $this->api_handler->find_product_by_sku($sku);
            $juleb_product_id = null;

            if ( !is_wp_error($juleb_product_response) && !empty($juleb_product_response['data']) ) {
                foreach ($juleb_product_response['data'] as $product_data) {
                    if (isset($product_data['default_code']) && $product_data['default_code'] == $sku) {
                        $juleb_product_id = $product_data['id'];
                        break;
                    }
                }
            }

            if ( is_null($juleb_product_id) ) {
                return new WP_Error('juleb_product_not_found', sprintf('Could not find a Juleb product with a matching SKU: %s', $sku));
            }

            $juleb_lines[] = [
                'product_id' => $juleb_product_id,
                'qty' => $item->get_quantity(),
                'lot_name' => null,
                'discount_by_percent' => 0
            ];
        }
        return $juleb_lines;
    }
}