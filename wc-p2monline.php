<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Plugin Name: woo.p2m.online
 * Description: Provides a p2m.online integration for WooCommerce
 * Version: 1.0
 * Author: itbrain
 */


/* Add a custom payment class to WC
  ------------------------------------------------------------ */
add_action('plugins_loaded', 'woocommerce_p2monline', 0);
add_action('wp_ajax_p2monline_ajax',  'p2monline_ajax_callback');
add_action('wp_ajax_nopriv_p2monline_ajax',  'p2monline_ajax_callback');
//thankyou custom
/*
add_action( 'template_redirect', 'thankyou_custom_payment_redirect');

function thankyou_custom_payment_redirect()
{
    if ( is_wc_endpoint_url( 'order-received' ) ) {
        global $wp;
        if(session_id() == '') {
            session_start();
        }
        $order_id =  $_SESSION['p2monline_order_id'] ;
        if($order_id&&!isset($_GET['key'])) {
            $order = wc_get_order( $order_id );
            if( $order->get_payment_method() == 'p2monline' ){
                wp_redirect( add_query_arg('order-received', $order->get_id(), add_query_arg('key', $order->get_order_key(), get_permalink(woocommerce_get_page_id('pay')))) );
                exit();
            }
        }
    }
}*/
//end thankyou custom

function p2monline_ajax_callback()
{
    if(session_id() == '') {
        session_start();
    }
    $order = new WC_Order((int)$_POST['order_id']);
    $WC_Payment_Gateway = wc_get_payment_gateway_by_order( $order );
    unset($_POST['action']);
    $options = array(
        'http' => array(
            'content' => json_encode($_POST),
            'header'  => "X-API-KEY: ".$WC_Payment_Gateway->settings['MNT_API_CODE']."\r\n".
                "Accept: application/json"."\r\n",
            "Content-type: application/x-www-form-urlencoded\r\n".
            "Content-Length: ".strlen(json_encode($_POST))."\r\n",
            'method'  => 'POST',
        )
    );
    $context = stream_context_create($options);
    $result = file_get_contents($WC_Payment_Gateway->settings['MNT_URL']. "/api/v3/deals", false, $context);
    if ($result === FALSE) {
        echo "{'error':'HTTP_ERROR'}";
    } else {
        //
        $_SESSION['p2monline_order_id'] = (int)$_POST['order_id'];
        echo $result;
    }
    wp_die();
}

function woocommerce_p2monline()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (class_exists('WC_P2monline')) {
        return;
    }

    class WC_p2monline extends WC_Payment_Gateway
    {
        public function __construct()
        {

            $plugin_dir = plugin_dir_url(__FILE__);
            global $woocommerce;
            $this->id = 'p2monline';
            $this->icon = apply_filters('woocommerce_p2monline_icon', '' . $plugin_dir . 'p2monline.svg');
            $this->has_fields = false;
            // Load the settings
            $this->init_form_fields();
            $this->init_settings();
            $this->MNT_SECRET_CODE = $this->get_option('MNT_SECRET_CODE');
            // Define user set variables
            $this->title = $this->get_option('title');
            $this->MNT_URL = $this->get_option('MNT_URL');
            $this->MNT_API_CODE = $this->get_option('MNT_API_CODE');
            $this->description = $this->get_option('description');
            $this->instructions = $this->get_option('instructions');
            // Actions
            add_action('woocommerce_receipt_p2monline', array($this, 'receipt_page'));
            // Save options
            add_action('woocommerce_update_options_payment_gateways_p2monline', array($this, 'process_admin_options'));
            // Payment listener/API hook
            add_action('woocommerce_api_wc_p2monline', array($this, 'check_assistant_response'));
            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }

        private function getSignature($params=array())
        {
            ksort($params);
            $a = array();
            foreach ($params as $key => $val) {
                $a[]=$val;
            }
            return md5(implode("", $a).$this->MNT_SECRET_CODE);
        }
        /**
         * Check if this gateway is enabled and available in the user's country
         */
        public function is_valid_for_use()
        {
            if (!in_array(get_option('woocommerce_currency'), array('RUB'))) {
                return false;
            }
            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 0.1
         **/
        public function admin_options()
        {
            ?>
            <h3><?php _e('P2MOnline', 'woocommerce'); ?></h3>
            <p><?php _e('Настройка приема электронных платежей через P2M online', 'woocommerce'); ?></p>

            <?php if ($this->is_valid_for_use()) : ?>

            <table class="form-table">

                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table><!--/.form-table-->

        <?php else : ?>
            <div class="inline error"><p>
                    <strong><?php _e('Шлюз отключен', 'woocommerce'); ?></strong>: <?php _e('p2monline не поддерживает валюты Вашего магазина.', 'woocommerce'); ?>
                </p></div>
        <?php
        endif;

        } // End admin_options()

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Включить/Выключить', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Включен', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Название', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Это название, которое пользователь видит во время проверки.', 'woocommerce'),
                    'default' => __('p2monline', 'woocommerce')
                ),
                'MNT_API_CODE' => array(
                    'title' => __('Ключ для подтверждения', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Пожалуйста введите ключ для подтверждения, указанный в настройках Вашего проекта', 'woocommerce'),
                    'default' => ''
                ),
                'MNT_SECRET_CODE' => array(
                    'title' => __('Секретный код', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Пожалуйста введите секретный код, указанный в настройках Вашего проекта', 'woocommerce'),
                    'default' => ''
                ),

                'MNT_CALLBACK_URL' => array(
                    'title' => __('CALLBACK_URL', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Пожалуйста вставьте его в соответствующее поле в соответствующем месте', 'woocommerce'),
                    'default' => str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_p2monline', home_url( '/' ) ) )
                ),
                /*
                'MNT_RETURN_URL' => array(
                    'title' => __('RETURN_URL', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Пожалуйста вставьте его в соответствующее поле в соответствующем месте', 'woocommerce'),
                    'default' => add_query_arg('order-received', true, get_permalink(woocommerce_get_page_id('pay')))
                ),
                */
                'MNT_SUCCESS_URL' => array(
                    'title' => __('SUCCESS_URL', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Пожалуйста вставьте его в соответствующее поле в соответствующем месте', 'woocommerce'),
                    'default' => str_replace( 'https:', 'http:', add_query_arg('p2monline', 'success', add_query_arg( 'wc-api', 'WC_p2monline', home_url( '/' ) ) ) )
                ),
                'MNT_FAIL_URL' => array(
                    'title' => __('FAIL_URL', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Пожалуйста вставьте его в соответствующее поле в соответствующем месте', 'woocommerce'),
                    'default' => str_replace( 'https:', 'http:', add_query_arg('p2monline', 'fail', add_query_arg( 'wc-api', 'WC_p2monline', home_url( '/' ) ) ) )
                ),
                'MNT_URL' => array(
                    'title' => __('API URL', 'woocommerce'),
                    'type'=>'text',
                    //'type' => 'select',
                    //'options' => array(
                    //    'https://api.p2m.online' => 'https://api.p2m.online',
                    //    'http://p2m.2mx.org:8484' => 'http://p2m.2mx.org:8484',
                    //),
                    'description' => __('Пожалуйста введите API URL сервера оплаты.', 'woocommerce'),
                    'default' => ''
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Описанием метода оплаты которое клиент будет видеть на вашем сайте.', 'woocommerce'),
                    'default' => 'Оплата с помощью p2monline.'
                ),
                'instructions' => array(
                    'title' => __('Instructions', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Инструкции, которые будут добавлены на страницу благодарностей.', 'woocommerce'),
                    'default' => 'Оплата с помощью p2monline.'
                ),
            );

        }

        /**
         * Дополнительная информация в форме выбора способа оплаты
         **/
        public function payment_fields()
        {

            if ($this->description)
            {
                echo wpautop(wptexturize($this->description));
            }

            if ( isset($_GET['pay_for_order']) && ! empty($_GET['key']) )
            {
                $order = wc_get_order( wc_get_order_id_by_order_key( wc_clean( $_GET['key'] ) ) );
                $this->receipt_page($order->get_id());
            }
        }

        /**
         * Process the payment and return the result
         **/
        public function process_payment($order_id)
        {
            /** @var WC_Order $order */
            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => add_query_arg('order', $order->get_id(), add_query_arg('key', $order->get_order_key(), get_permalink(woocommerce_get_page_id('pay'))))
            );
        }

        /**
         * Форма оплаты
         **/
        public function receipt_page($order_id)
        {
            $order = new WC_Order($order_id);
            $amount = number_format($order->order_total, 2, '.', '');
            $currency = get_woocommerce_currency();
            if ($currency == 'RUR') $currency = 'RUB';
            $args = array(
                "order_id" => $order_id,
                "order_return" => $this->get_return_url( $order ),
                "order_desc" => 'Оплата заказа '.$order_id,
                "order_amount" => $amount,
            );
            $args['signature'] = $this->getSignature($args);
            $args["action"]  = "p2monline_ajax";
            $args_array = array();
            foreach ($args as $key => $value) {
                $args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }


            if ( isset($_GET['pay_for_order']) && ! empty($_GET['key']) ) {
                $form_html = '<form></form><form action="'.esc_url( $this->MNT_URL . "/api/v3/deals") . '" method="POST" id="p2monline_payment_form" name="paymentform">'."\n".
                    implode("\n", $args_array).
                    '<input type="submit" class="button alt" style="display: none" id="submit_p2monline_payment_form" value="' . __('Оплатить', 'woocommerce') . '" />'."\n" .
                    '</form>'."\n".
                    '<script type="text/javascript">'."\n".
                    'jQuery(function() {'."\n".
                    '    jQuery("#order_review").submit(function(ev) {'."\n".
                    '        if (jQuery("#payment_method_p2monline").prop("checked")) {'."\n".
                    '            ev.preventDefault();'."\n".
                    '            jQuery("#submit_p2monline_payment_form").click();'."\n".
                    '        }'."\n".
                    '    });'."\n".
                    '});</script>';
            } else {
                $form_html = '<form action="' . esc_url( $this->MNT_URL . "/api/v3/deals") . '" method="POST" id="p2monline_payment_form" name="paymentform">' . "\n" .
                    implode("\n", $args_array) .
                    '<input type="submit" class="button alt" id="submit_p2monline_payment_form" value="' . __('Оплатить', 'woocommerce') . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Отказаться от оплаты и вернуться в корзину', 'woocommerce') . '</a>' . "\n" .
                    '</form>
									';
            }
            $form_html .= '<script type="text/javascript">
								jQuery( "#p2monline_payment_form" ).submit(function( event ) {
									event.preventDefault();
									jQuery.ajax({
										type: \'POST\',
										dataType: \'html\',
										url: woocommerce_params.ajax_url,
										data: jQuery( \'#p2monline_payment_form\' ).serialize(),
										success: function(data){
											console.log(data);
											var data = jQuery.parseJSON( data );
											if(typeof(data.error) != \'undefined\') {
												alert(data.error);
											} else if(typeof(data.redirect) != \'undefined\') {
												document.location.href = data.redirect;
											} else {
												console.log(data);
											}
										},
										error : function(jqXHR, textStatus, errorThrown) {
											console.log(textStatus);
										}
									});
								});
							</script>';
            echo $form_html;
        }

        /**
         * Check p2monline Pay URL validity
         **/
        private function check_assistant_request_is_valid($posted)
        {
            if ($this->checkRequest($posted)) {
                $signature = $this->getSignature(array(
                        "create_date" => $posted['create_date'],
                        "expire_date" => $posted['expire_date'],
                        "object_id" => $posted['object_id'],
                        "order_desc" => $posted['order_desc'],
                        "order_fulldesc" => $posted['order_fulldesc'],
                        "order_id" => $posted['order_id'],
                        "redirect" => $posted['redirect'],
                        "status" => $posted['status'],
                        "update_date" => $posted['update_date'],
                        "order_amount" => $posted['order_amount'],
                    )
                );
                if ($posted['signature'] !== $signature) {
                    return false;
                } else {
                    $expire_date = strtotime($posted['expire_date']);
                    if(time()>$expire_date) {
                        return false;
                    }
                }
            } else {
                return false;
            }
            return true;
        }

        private function checkRequest(array $data)
        {
            return isset($data['order_id']) && isset($data['order_desc']) && isset($data['order_amount']) && isset($data['signature']) && isset($data['create_date']) && isset($data['expire_date']) && isset($data['object_id']) && isset($data['order_fulldesc']) && isset($data['redirect']) && isset($data['status']) && isset($data['update_date']);
        }

        /**
         * Check Response

        Available order states:

        created+
        paid+
        canceled
        completed
        paymentprocesserror+
        unknown

         **/

        public function check_assistant_response()
        {
            global $woocommerce;
            if(session_id() == '') {
                session_start();
            }
            $_REQUEST = stripslashes_deep($_REQUEST);
            $MNT_TRANSACTION_ID = $_REQUEST['order_id'];
            if (isset($_REQUEST['signature'])&&isset($_REQUEST['status'])) {
                @ob_clean();
                if ($this->check_assistant_request_is_valid($_REQUEST)) {
                    $order = new WC_Order($MNT_TRANSACTION_ID);
                    // Check order not already completed
                    if ($order->status == 'completed') {
                        echo 'FAIL';
                        exit;
                    }
                    switch($_REQUEST['status']) {
                        case 'paid':
                            // Payment completed
                            $order->add_order_note(__('Платеж успешно завершен.', 'woocommerce'));
                            $order->update_status('processing', __('Платеж успешно оплачен', 'woocommerce'));
                            $order->payment_complete();
                            if (($admin_email = get_option('admin_email')) && $admin_email)
                                wp_mail( $admin_email, 'Оплата заказа №' . $order->get_id(), 'Заказ №' . $order->get_id() . ' оплачен.', '' );
                            break;
                        case 'canceled':
                            //change order status to canceled, add order note
                            $order->add_order_note(__('Платеж отменён пользователем.', 'woocommerce'));
                            $order->update_status('cancelled', __('Платеж отменён пользователем', 'woocommerce'));
                            if (($admin_email = get_option('admin_email')) && $admin_email)
                                wp_mail( $admin_email, 'Отмена заказа №' . $order->get_id(), 'Заказ №' . $order->get_id() . ' отменён пользователем в Pay2Me.', '' );
                            break;
                        default:
                            //do nothing
                    }
                    echo 'SUCCESS';
                    exit;
                } else {
                    echo 'FAIL';
                    exit;
                }
            } else if (isset($_REQUEST['p2monline']) AND $_REQUEST['p2monline'] == 'success') {
                //
                $MNT_TRANSACTION_ID = $_SESSION['p2monline_order_id'];
                $_SESSION['p2monline_order_id'] = '';
                $order = new WC_Order($MNT_TRANSACTION_ID);
                $woocommerce->cart->empty_cart();
                wp_redirect($this->get_return_url($order));
                exit;
            } else if (isset($_REQUEST['p2monline']) AND $_REQUEST['p2monline'] == 'fail') {
                //
                $MNT_TRANSACTION_ID = $_SESSION['p2monline_order_id'];
                $_SESSION['p2monline_order_id'] = '';
                $order = new WC_Order($MNT_TRANSACTION_ID);
                $order->update_status('failed', __('Платеж не оплачен', 'woocommerce'));
                wp_redirect($order->get_cancel_order_url());
                exit;
            }
        }
    }

    /**
     * Add the gateway to WooCommerce
     **/
    function add_p2monline_gateway($methods)
    {
        $methods[] = 'WC_P2monline';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_p2monline_gateway');
}

?>
