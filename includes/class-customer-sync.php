<?php

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Juleb_Customer_Sync {

    private $api_handler;

    public function __construct() {
        $this->api_handler = new Juleb_API_Handler();
        add_action( 'woocommerce_created_customer', array( $this, 'handle_new_customer_hook' ), 10, 1 );
    }

    /**
     * Handle the hook for when a new customer is created in WooCommerce.
     */
    public function handle_new_customer_hook( $customer_id ) {
        $customer = new WC_Customer( $customer_id );
        $this->get_or_create_juleb_partner_id( $customer );
    }

    /**
     * Gets a Juleb Partner ID for a WooCommerce customer.
     * If the partner doesn't exist in Juleb, it creates one.
     *
     * @param WC_Customer $customer The WooCommerce customer object.
     * @return int|null The Juleb Partner ID or null on failure.
     */
    public function get_or_create_juleb_partner_id( $customer ) {
        if ( ! is_a( $customer, 'WC_Customer' ) ) {
            return null;
        }

        $phone = $customer->get_billing_phone();
        $email = $customer->get_email();
        $partner_id = null;

        $this->api_handler->log( 'INFO', 'Getting or creating Juleb partner for customer.', ['email' => $email, 'phone' => $phone] );

        // Step 1: Try to find the partner by phone number first
        if ( ! empty( $phone ) ) {
            $response = $this->api_handler->find_partner_by_phone( $phone );

            if ( ! is_wp_error( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
                foreach ( $response['data'] as $partner_data ) {
                    // Check both mobile and phone fields for a match
                    $api_mobile = isset($partner_data['mobile']) ? preg_replace('/[^0-9]/', '', $partner_data['mobile']) : '';
                    $api_phone = isset($partner_data['phone']) ? preg_replace('/[^0-9]/', '', $partner_data['phone']) : '';
                    $customer_phone = preg_replace('/[^0-9]/', '', $phone);

                    if ( !empty($customer_phone) && ($api_mobile === $customer_phone || $api_phone === $customer_phone) ) {
                        $partner_id = $partner_data['id'];
                        $this->api_handler->log( 'INFO', 'Found matching partner by phone number.', ['partner_id' => $partner_id] );
                        break; // Exit the loop once found
                    }
                }
            }
        }

        // Step 2: If not found by phone, try to find the partner by email as a fallback
        if ( ! $partner_id && ! empty( $email ) ) {
            $this->api_handler->log( 'INFO', 'Partner not found by phone, trying email.' );
            $response = $this->api_handler->find_partner_by_email( $email );

            if ( ! is_wp_error( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
                foreach ( $response['data'] as $partner_data ) {
                    if ( isset( $partner_data['email'] ) && strtolower( trim($partner_data['email']) ) === strtolower( trim($email) ) ) {
                        $partner_id = $partner_data['id'];
                        $this->api_handler->log( 'INFO', 'Found matching partner by email.', ['partner_id' => $partner_id] );
                        break; // Exit the loop once found
                    }
                }
            }
        }

        // If we found an existing partner, update meta and return the ID
        if ( $partner_id ) {
            $this->api_handler->log( 'INFO', 'Using existing Juleb partner.', ['partner_id' => $partner_id] );
            update_user_meta( $customer->get_id(), '_juleb_partner_id', $partner_id );
            return $partner_id;
        }

        // Step 3: If not found by phone or email, create a new partner.
        $this->api_handler->log( 'INFO', 'Partner not found in Juleb, creating a new one.' );
        
        $name = trim($customer->get_first_name() . ' ' . $customer->get_last_name());
        if (empty($name)) {
            $name = $customer->get_display_name();
        }

        $partner_data = array(
            'name'      => $name,
            'email'     => $email,
            'mobile'    => $phone,
            'phone'     => $phone,
            'street'    => $customer->get_billing_address_1(),
            'street2'   => $customer->get_billing_address_2(),
            'city'      => $customer->get_billing_city(),
            'zip'       => $customer->get_billing_postcode(),
        );

        $creation_response = $this->api_handler->create_partner( $partner_data );

        if ( is_wp_error( $creation_response ) ) {
            $this->api_handler->log( 'ERROR', 'Failed to create Juleb partner.', ['error' => $creation_response->get_error_message()] );
            return null;
        }
        
        $new_partner_id = null;
        if ( is_numeric($creation_response) ) {
            $new_partner_id = $creation_response;
        } elseif (isset($creation_response['data']['id'])) {
            $new_partner_id = $creation_response['data']['id'];
        } elseif (isset($creation_response['id'])) {
            $new_partner_id = $creation_response['id'];
        }

        if ( $new_partner_id ) {
            $this->api_handler->log( 'INFO', 'Successfully created new Juleb partner.', ['new_partner_id' => $new_partner_id] );
            update_user_meta( $customer->get_id(), '_juleb_partner_id', $new_partner_id );
            return $new_partner_id;
        } else {
            $this->api_handler->log( 'ERROR', 'Failed to create Juleb partner: Could not extract ID from response.', ['response' => $creation_response] );
            return null;
        }
    }
}