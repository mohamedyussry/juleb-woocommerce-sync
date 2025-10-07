<?php

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Juleb_API_Handler {

    private $base_url;
    private $bearer_token;

    public function __construct() {
        $options = get_option( 'juleb_sync_options' );
        $this->base_url = isset($options['juleb_base_url']) ? trailingslashit($options['juleb_base_url']) : '';
        $this->bearer_token = isset($options['juleb_bearer_token']) ? $options['juleb_bearer_token'] : '';
    }

    private function send_request( $method, $endpoint, $body = [] ) {
        if ( empty($this->base_url) || empty($this->bearer_token) ) {
            $this->log('ERROR', 'API credentials are not set.');
            return new WP_Error( 'api_credentials_missing', __( 'Juleb API credentials are not configured.', 'juleb-sync' ) );
        }

        $url = $this->base_url . $endpoint;

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->bearer_token,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 45,
        );

        if ( ! empty( $body ) ) {
            $args['body'] = json_encode( $body );
        }

        $this->log('DEBUG', 'Sending API Request', ['method' => $method, 'url' => $url, 'body' => $body]);

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log('ERROR', 'WP_Error during API request.', ['error_message' => $response->get_error_message()]);
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $decoded_body = json_decode( $response_body, true );

        if ( $response_code >= 400 ) {
            $this->log('ERROR', 'API returned an error code.', [
                'response_code' => $response_code,
                'request_body' => $body,
                'response_body' => $decoded_body
            ]);
            return new WP_Error( 'api_error', 'API Error: ' . $response_code, $decoded_body );
        }
        
        $this->log('DEBUG', 'API Request Successful.', ['response_code' => $response_code, 'response_body' => $decoded_body]);

        return $decoded_body;
    }

    public function find_partner_by_email($email) {
        return $this->send_request('GET', 'resources/partner?filter=email*' . urlencode($email));
    }

    public function find_partner_by_phone($phone) {
        // Try searching by 'mobile' first
        $response = $this->send_request('GET', 'resources/partner?filter=mobile*' . urlencode($phone));
        // If we find a result, return it immediately.
        if ( ! is_wp_error( $response ) && ! empty( $response['data'] ) ) {
            return $response;
        }
    
        // If not found by mobile, try searching by 'phone' as a fallback.
        return $this->send_request('GET', 'resources/partner?filter=phone*' . urlencode($phone));
    }

    public function find_product_by_sku($sku) {
        return $this->send_request('GET', 'inventory/product?filter=default_code*' . urlencode($sku));
    }

    public function create_partner($data) {
        return $this->send_request('POST', 'resources/partner', $data);
    }

    public function create_pos_session($config_id) {
        return $this->send_request('POST', 'pos/session', ['configId' => $config_id]);
    }

    public function create_order($data) {
        return $this->send_request('POST', 'pos/order', $data);
    }

    public function test_connection() {
        return $this->send_request('GET', 'resources/partner?limit=1');
    }

    public function update_product($product_id, $data) {
        return $this->send_request('POST', 'inventory/product?sku=' . $product_id, $data);
    }

    public function get_pos_configs() {
        $all_configs = [];
        $page = 1;
        $limit = 1000; // Max limit
        $endpoint = 'pos/config';

        do {
            $paginated_endpoint = $endpoint . '?' . http_build_query(['limit' => $limit, 'page' => $page]);

            $response = $this->send_request('GET', $paginated_endpoint);

            if (is_wp_error($response) || empty($response['data'])) {
                break;
            }

            $all_configs = array_merge($all_configs, $response['data']);

            if (isset($response['pagination']['nextPage']) && !is_null($response['pagination']['nextPage'])) {
                $page++;
            } else {
                break;
            }

        } while (true);

        return ['data' => $all_configs];
    }

    public function get_companies() {
        return $this->send_request('GET', 'resources/company');
    }

    public function get_payment_methods($company_id = null) {
        $all_payment_methods = [];
        $page = 1;
        $limit = 1000; // Max limit
        $endpoint = 'pos/payment-method';

        $query_params = ['limit' => $limit];

        if ($company_id) {
            $query_params['filter'] = 'company_id=' . $company_id;
        }

        do {
            $query_params['page'] = $page;
            $paginated_endpoint = $endpoint . '?' . http_build_query($query_params);

            $response = $this->send_request('GET', $paginated_endpoint);

            if (is_wp_error($response) || empty($response['data'])) {
                break;
            }

            $all_payment_methods = array_merge($all_payment_methods, $response['data']);

            if (isset($response['pagination']['nextPage']) && !is_null($response['pagination']['nextPage'])) {
                $page++;
            } else {
                break;
            }

        } while (true);

        return $all_payment_methods;
    }

    public function log( $level, $message, $context = [] ) {
        try {
            $log_file = JULEB_SYNC_PLUGIN_DIR . 'logs/sync.log';
            $formatted_message = '[' . date('Y-m-d H:i:s') . ']';
            $formatted_message .= '[' . strtoupper($level) . '] ';
            $formatted_message .= $message;

            if (!empty($context)) {
                $formatted_message .= ' | Context: ' . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $formatted_message .= "\n";

            file_put_contents( $log_file, $formatted_message, FILE_APPEND );
        } catch (Exception $e) {
            // Failsafe in case logging itself fails
        }
    }
}