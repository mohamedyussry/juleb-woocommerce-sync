<?php

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Juleb_Product_Sync {

    private $api_handler;

    public function __construct() {
        $this->api_handler = new Juleb_API_Handler();

        // 1. Sync from WooCommerce to Juleb
        add_action( 'woocommerce_update_product', array( $this, 'sync_product_update_to_juleb' ), 10, 1 );

        // 2. Create a custom REST API endpoint for Juleb to send updates to WooCommerce
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    /**
     * When a product is updated in Woo, send changes to Juleb.
     * We only send price and description as requested.
     */
    public function sync_product_update_to_juleb( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        // Use SKU as the identifier for Juleb
        $sku = $product->get_sku();
        if ( empty( $sku ) ) {
            $this->api_handler->log( 'Product ID ' . $product_id . ' has no SKU. Cannot sync to Juleb.' );
            return; // Juleb needs an identifier
        }

        $data = array(
            'price'       => $product->get_price(),
            'description' => $product->get_description(),
            // Add other fields if needed
        );

        $this->api_handler->log( 'Syncing product update to Juleb for SKU: ' . $sku . ' Data: ' . print_r($data, true) );
        $response = $this->api_handler->update_product( $sku, $data );

        if ( is_wp_error( $response ) ) {
            $this->api_handler->log( 'Failed to sync product SKU: ' . $sku . '. Error: ' . $response->get_error_message() );
        } else {
            $this->api_handler->log( 'Successfully synced product SKU: ' . $sku . ' to Juleb.' );
        }
    }

    /**
     * Register our custom REST API endpoint.
     * Namespace: juleb-sync/v1
     * Route: /inventory
     */
    public function register_rest_routes() {
        register_rest_route( 'juleb-sync/v1', '/inventory', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_inventory_update_from_juleb' ),
            'permission_callback' => '__return_true' // Should be secured with a key in a real-world scenario
        ) );
    }

    /**
     * Handle the incoming webhook from Juleb to update inventory.
     */
    public function handle_inventory_update_from_juleb( WP_REST_Request $request ) {
        $params = $request->get_json_params();

        if ( ! isset( $params['product_sku'] ) || ! isset( $params['stock_quantity'] ) ) {
            $this->api_handler->log('Invalid inventory webhook received: ' . print_r($params, true));
            return new WP_Error( 'invalid_params', 'Missing product_sku or stock_quantity', array( 'status' => 400 ) );
        }

        $sku = sanitize_text_field($params['product_sku']);
        $stock_quantity = intval($params['stock_quantity']);

        $this->api_handler->log('Received inventory webhook from Juleb. SKU: ' . $sku . ', New Stock: ' . $stock_quantity);

        // Find product by SKU
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( ! $product_id ) {
            $this->api_handler->log('Could not find product with SKU: ' . $sku);
            return new WP_Error( 'product_not_found', 'Product with SKU ' . $sku . ' not found', array( 'status' => 404 ) );
        }

        $product = wc_get_product($product_id);
        if ($product) {
            $product->set_stock_quantity($stock_quantity);
            $product->save();
            $this->api_handler->log('Successfully updated stock for SKU: ' . $sku);
            return new WP_REST_Response( array( 'success' => true, 'message' => 'Stock updated successfully.' ), 200 );
        } else {
             return new WP_Error( 'product_not_found', 'Product with SKU ' . $sku . ' not found', array( 'status' => 404 ) );
        }
    }
}
