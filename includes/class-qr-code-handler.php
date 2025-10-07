<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class Juleb_QR_Code_Handler {

    public function __construct() {
        add_action( 'init', array( $this, 'register_custom_order_statuses' ) );
        add_filter( 'wc_order_statuses', array( $this, 'add_custom_order_statuses' ) );

        // Add QR Code meta box to order admin page
        add_action( 'add_meta_boxes', array( $this, 'add_qr_code_meta_box' ) );

        // Register the REST API endpoint
        add_action( 'rest_api_init', array( $this, 'register_qr_code_endpoint' ) );

        // Add handler for printing the invoice
        add_action( 'admin_init', array( $this, 'handle_print_invoice_request' ) );
    }

    /**
     * Register custom order statuses.
     */
    public function register_custom_order_statuses() {
        register_post_status( 'wc-prepared', array(
            'label'                     => 'Prepared',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Prepared <span class="count">(%s)</span>', 'Prepared <span class="count">(%s)</span>', 'juleb-woocommerce-sync' )
        ) );

        register_post_status( 'wc-out-for-delivery', array(
            'label'                     => 'Out for Delivery',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Out for Delivery <span class="count">(%s)</span>', 'Out for Delivery <span class="count">(%s)</span>', 'juleb-woocommerce-sync' )
        ) );
        
        register_post_status( 'wc-delivered', array(
            'label'                     => 'Delivered',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Delivered <span class="count">(%s)</span>', 'Delivered <span class="count">(%s)</span>', 'juleb-woocommerce-sync' )
        ) );
    }

    /**
     * Add custom statuses to WC order statuses.
     */
    public function add_custom_order_statuses( $order_statuses ) {
        $new_order_statuses = array();

        foreach ( $order_statuses as $key => $status ) {
            $new_order_statuses[ $key ] = $status;
            if ( 'wc-processing' === $key ) {
                $new_order_statuses['wc-prepared'] = 'Prepared';
                $new_order_statuses['wc-out-for-delivery'] = 'Out for Delivery';
                $new_order_statuses['wc-delivered'] = 'Delivered';
            }
        }
        
        if ( ! array_key_exists( 'wc-prepared', $new_order_statuses ) ) {
            $new_order_statuses['wc-prepared'] = 'Prepared';
            $new_order_statuses['wc-out-for-delivery'] = 'Out for Delivery';
            $new_order_statuses['wc-delivered'] = 'Delivered';
        }

        return $new_order_statuses;
    }

    /**
     * Add QR Code meta box.
     */
    public function add_qr_code_meta_box() {
        add_meta_box(
            'juleb_qr_code_meta_box',
            'QR Code والفاتورة',
            array( $this, 'display_qr_code_meta_box' ),
            'shop_order',
            'side',
            'core'
        );
    }

    /**
     * Display the QR Code meta box content.
     */
    public function display_qr_code_meta_box( $post ) {
        if ( ! extension_loaded('gd') && ! extension_loaded('imagick') ) {
            echo '<div class="notice notice-error"><p><strong>خطأ في إضافة Juleb:</strong> لتوليد QR Code، يرجى تفعيل إضافة PHP المسماة <code>gd</code> أو <code>imagick</code> على الخادم الخاص بك.</p></div>';
            return;
        }

        $order_id = $post->ID;
        
        echo '<div style="text-align: center;">';

        // --- Display QR Code ---
        $qr_code_data_uri = $this->generate_qr_code_data_uri($order_id);
        if (is_wp_error($qr_code_data_uri)) {
            echo '<strong>لا يمكن إنشاء QR Code:</strong><br>' . esc_html($qr_code_data_uri->get_error_message());
        } else {
            echo '<img src="' . esc_attr($qr_code_data_uri) . '" alt="QR Code" style="width: 150px; height: 150px;" />';
            echo '<p>امسح هذا الكود لتحديث حالة الطلب.</p>';
        }

        echo '<hr style="margin: 15px -12px;">';

        // --- Print Button ---
        $print_url = wp_nonce_url(
            add_query_arg(
                [
                    'post' => $order_id,
                    'print_juleb_invoice' => 'true'
                ],
                admin_url('post.php')
            ),
            'juleb_print_invoice_' . $order_id
        );

        echo '<a href="' . esc_url($print_url) . '" class="button button-primary" target="_blank" style="width: 100%; text-align: center;">طباعة الفاتورة</a>';

        echo '</div>';
    }

    /**
     * Handle the QR code scan to update order status.
     */
    public function handle_qr_code_scan( $request ) {
        // This is the method for the delivery person's UI.
        // We will implement the receipt-style web page here later if requested.
        // For now, keeping the original logic.
        
        $order_id   = absint( $request->get_param( 'order_id' ) );
        $secret_key = sanitize_text_field( $request->get_param( 'secret_key' ) );

        $stored_secret = 'JULEB_SECRET';
        if ( ! $secret_key || $secret_key !== $stored_secret ) {
            wp_die( 'Error: Invalid or missing secret key.', 'Security Error', array( 'response' => 401 ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( 'Error: Order not found.', 'Order Error', array( 'response' => 404 ) );
        }

        $current_status = $order->get_status();
        $next_status    = '';
        $next_status_label = '';

        switch ($current_status) {
            case 'processing':
                $next_status = 'wc-prepared';
                $next_status_label = 'Prepared';
                break;
            case 'prepared':
            case 'wc-prepared':
                $next_status = 'wc-out-for-delivery';
                $next_status_label = 'Out for Delivery';
                break;
            
            case 'out-for-delivery':
            case 'wc-out-for-delivery':
                $next_status = 'completed';
                $next_status_label = 'Completed';
                break;
            default:
                $message = sprintf( 'Order #%s is already in status (%s) and cannot be updated via QR.', $order_id, wc_get_order_status_name( $current_status ) );
                wp_die( $message, 'No Update' );
        }

        if ( $next_status ) {
            $order->update_status( $next_status, 'Status updated via QR Code scan.' );
            $message = sprintf( '<h1>Success!</h1><p style="font-size: 1.2em;">Order #%s status has been updated to: <strong>%s</strong></p>', $order_id, $next_status_label );
            wp_die( $message, 'Update Successful' );
        } else {
            wp_die( 'Unexpected error.', 'Error', array( 'response' => 500 ) );
        }
    }

    /**
     * Register the REST API endpoint for QR code scans.
     */
    public function register_qr_code_endpoint() {
        register_rest_route( 'juleb-sync/v1', '/update-status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_qr_code_scan' ),
            'permission_callback' => '__return_true'
        ) );
    }

    /**
     * Checks for the print invoice request and serves the template.
     */
    public function handle_print_invoice_request() {
        if ( isset($_GET['print_juleb_invoice']) && $_GET['print_juleb_invoice'] === 'true' && isset($_GET['post']) ) {
            $order_id = absint($_GET['post']);
            
            if ( ! isset($_GET['_wpnonce']) || ! wp_verify_nonce($_GET['_wpnonce'], 'juleb_print_invoice_' . $order_id) ) {
                wp_die('رابط غير صالح أو منتهي الصلاحية.', 'خطأ أمني');
            }

            if ( ! current_user_can('edit_shop_order', $order_id) ) {
                wp_die('ليس لديك الصلاحية لعرض هذه الفاتورة.', 'خطأ في الصلاحيات');
            }

            $this->render_invoice_template($order_id);
            exit;
        }
    }

    /**
     * Generates and returns a Data URI for the order's QR code.
     *
     * @param int $order_id
     * @return string|WP_Error The Data URI on success, or WP_Error on failure.
     */
    private function generate_qr_code_data_uri($order_id) {
        $secret_key = 'JULEB_SECRET';
        $api_url = get_rest_url(null, sprintf('juleb-sync/v1/update-status?order_id=%d&secret_key=%s', $order_id, $secret_key));

        try {
            if (!class_exists('Endroid\\QrCode\\Builder\\Builder')) {
                throw new Exception('The Endroid/QrCode Builder class was not found.');
            }

            $writer = new PngWriter();
            $errorCorrectionLevel = null;

            if (interface_exists('Endroid\\QrCode\\ErrorCorrectionLevel\\ErrorCorrectionLevelInterface')) {
                $errorCorrectionLevel = new \Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow();
            } else {
                $errorCorrectionLevel = ErrorCorrectionLevel::Low;
            }

            $result = Builder::create()
                ->writer($writer)
                ->data($api_url)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel($errorCorrectionLevel)
                ->size(150)
                ->margin(5)
                ->build();

            return $result->getDataUri();
        } catch (Exception $e) {
            return new WP_Error('qr_generation_failed', $e->getMessage());
        }
    }

    /**
     * Renders the printable invoice HTML template.
     *
     * @param int $order_id
     */
    private function render_invoice_template($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die('الطلب غير موجود.');
        }

        $qr_code_data_uri = $this->generate_qr_code_data_uri($order_id);
        if (is_wp_error($qr_code_data_uri)) {
            $qr_code_html = '<p style="color: red;">' . esc_html($qr_code_data_uri->get_error_message()) . '</p>';
        } else {
            $qr_code_html = '<img src="' . esc_attr($qr_code_data_uri) . '" alt="QR Code" style="width: 150px; height: 150px; margin: auto;" />';
        }
        ?>
        <!DOCTYPE html>
        <html dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>فاتورة الطلب رقم <?php echo $order->get_order_number(); ?></title>
            <style>
                body { font-family: monospace, sans-serif; direction: rtl; text-align: right; background-color: #fff; margin: 0; padding: 10px; }
                .receipt { width: 300px; margin: auto; padding: 15px; }
                .header, .footer { text-align: center; }
                .header h2 { font-size: 1.4em; margin: 0; }
                .header p { margin: 2px 0; font-size: 0.9em; }
                .separator { border-top: 1px dashed #000; margin: 10px 0; }
                .details, .items, .totals { font-size: 0.9em; }
                .details p { margin: 5px 0; line-height: 1.4; }
                .items table { width: 100%; border-collapse: collapse; font-size: 0.85em;}
                .items th, .items td { padding: 5px 0; }
                .items thead th { border-bottom: 1px dashed #000; text-align: right; }
                .items .qty, .items .price { text-align: left; }
                .totals table { width: 100%; }
                .totals td { padding: 2px 0; }
                .totals tr.total td { font-weight: bold; padding-top: 5px; border-top: 1px dashed #000; }
                .qr-code { text-align: center; padding: 15px 0; }
                @media print {
                    body { padding: 0; }
                    .receipt { width: 100%; margin: 0; border: none; }
                }
            </style>
        </head>
        <body>
            <div class="receipt">
                <div class="header">
                    <h2><?php echo get_bloginfo('name'); ?></h2>
                    <p>فاتورة طلب</p>
                </div>
                <div class="separator"></div>
                <div class="details">
                    <p><strong>رقم الطلب:</strong> <?php echo $order->get_order_number(); ?></p>
                    <p><strong>التاريخ:</strong> <?php echo wc_format_datetime($order->get_date_created()); ?></p>
                    <p><strong>العميل:</strong> <?php echo $order->get_formatted_billing_full_name(); ?></p>
                    <?php if ($order->get_shipping_address_1()): ?>
                        <p><strong>العنوان:</strong> <?php echo $order->get_formatted_shipping_address(); ?></p>
                    <?php endif; ?>
                    <p><strong>الهاتف:</strong> <?php echo $order->get_billing_phone(); ?></p>
                </div>
                <div class="separator"></div>
                <div class="items">
                    <table>
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th class="qty">الكمية</th>
                                <th class="price">السعر</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order->get_items() as $item_id => $item) : ?>
                                <tr>
                                    <td><?php echo $item->get_name(); ?></td>
                                    <td class="qty"><?php echo $item->get_quantity(); ?></td>
                                    <td class="price"><?php echo wc_price($item->get_total(), array('currency' => $order->get_currency())); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="separator"></div>
                <div class="totals">
                    <table>
                        <?php foreach ($order->get_order_item_totals() as $key => $total) : ?>
                            <tr class="<?php echo esc_attr(str_replace('_', '-', $key)); ?>">
                                <td><?php echo $total['label']; ?></td>
                                <td style="text-align: left;"><?php echo $total['value']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <div class="separator"></div>
                <div class="qr-code">
                    <p>امسح الكود لتحديث حالة الطلب</p>
                    <?php echo $qr_code_html; ?>
                </div>
                <div class="separator"></div>
                <div class="footer">
                    <p>شكراً لتعاملكم معنا!</p>
                </div>
            </div>
            <script>
                window.onload = function() {
                    window.print();
                };
            </script>
        </body>
        </html>
        <?php
    }
}