<?php
defined( 'ABSPATH' ) || exit;
class Wpyaa_WC_AW_Wechat extends WC_Payment_Gateway {
    /**
	 * 支付说明
     * @var string
     */
    private $instructions;

    /**
	 * 微信支付网关
     * @var Wpyaa_WC_AW_Wechat
     */
    private static $_instance;

    /**
	 * 返回微信支付网关实例
     * @return Wpyaa_WC_AW_Wechat
     */
    public static function instance(){
		if(!self::$_instance){
			self::$_instance = new self();
		}
		return self::$_instance;
	}

    /**
     * Wpyaa_WC_AW_Wechat constructor.
     */
	private function __construct() {
		$this->id                 = 'wpyaa_wc_aw_wechat';
		$this->icon               = plugins_url('icon/wechat.png',WPYAA_WC_AW_FILE);
		$this->has_fields         = false;
        if($this->get_option ( 'refund','yes' )==='yes'){
            $this->supports         []= 'refunds';
        }

		$this->method_title       = '微信支付';
		$this->method_description = 'PC电脑端扫码支付，微信内置浏览器/手机浏览器端自动唤起微信APP支付,订单退款。';

		$this->title              = $this->get_option ( 'title','微信支付' );
		$this->description        = $this->get_option ( 'description');
		$this->instructions       = $this->get_option('instructions');

		$this->init_form_fields ();
		$this->init_settings ();

		$this->enabled            = $this->get_option ( 'enabled' );

		add_filter ( 'woocommerce_payment_gateways', array($this,'woocommerce_add_gateway') );
		add_action ( 'woocommerce_update_options_payment_gateways_' .$this->id, array ($this,'process_admin_options') );
		//兼容低版本wordpress
		add_action ( 'woocommerce_update_options_payment_gateways', array ($this,'process_admin_options') );
		add_action ( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		add_action ( 'woocommerce_thankyou_'.$this->id, array( $this, 'thankyou_page' ) );
	}
    /**
     * Initialise Gateway Settings Form Fields
     *
     * @access public
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = array (
            'enabled' => array (
                'title'       => '微信支付',
                'type'        => 'checkbox',
                'label'       => '启用/禁用',
                'default'     => 'no'
            ),
            'title' => array (
                'title'       => '标题',
                'type'        => 'text',
                'desc_tip'    => true,
                'default'     =>  '微信支付',
				'description'=>'这控制用户在结帐时看到的标题。'
            ),
            'description' => array (
                'title'       => '描述',
                'desc_tip'    => true,
                'type'        => 'textarea',
                'description'=>'顾客支付的时候会看到关于该支付方式的说明'
            ),
            'instructions' => array(
                'title'       =>'说明',
                'type'        => 'textarea',
                'desc_tip'    => true,
                'description' => '说明将会被显示在订单确认页面和相关邮件中'
            ),
            'app_id' => array(
                'title'       =>'开发者ID(AppID)',
                'type'        => 'text',
                'description'=>'您可以在<a href="https://www.wpyaa.com/post/1.html" target="_blank">帮助文档</a>内获悉如何获取配置信息'
            ),
            'app_secret' => array(
                'title'       => '开发者密码(AppSecret)',
                'type'        => 'text'
            ),
            'mch_id' => array(
                'title'       => '微信支付商户号',
                'type'        => 'text',
                'description'=>'支付授权目录：<code>'.rest_url('/wpyaa/wc/aw/v1/wechat/').'</code>'
            ),
            'mch_key' => array(
                'title'       => 'APIv3密钥',
                'type'        => 'text'
            ),
            'jsapi' => array(
                'title'       => 'JSAPI支付',
                'type'        => 'checkbox',
                'label'       =>'启用/禁用',
                'default'     =>'yes',
                'disabled'    =>true,
                'description' =>'微信内置浏览器内唤起微信APP支付，您需要在<a href="https://pay.weixin.qq.com/index.php/extend/product/lists?tid=3" target="_blank">微信商户平台/产品大全</a> 下开通“JSAPI支付”'
            ),
            'native' => array(
                'title'       => 'NATIVE支付',
                'type'        => 'checkbox',
                'label'       =>'启用/禁用',
                'default'     =>'yes',
                'disabled'    =>true,
                'description' =>'PC电脑端，微信扫码支付，您需要在<a href="https://pay.weixin.qq.com/index.php/extend/product/lists?tid=3" target="_blank">微信商户平台/产品大全</a> 下开通“NATIVE支付”'
            ),
            'h5' => array(
                'title'       => 'H5支付',
                'type'        => 'checkbox',
                'label'       =>'启用/禁用',
                'description' =>'手机浏览器内唤起微信APP支付，您需要在<a href="https://pay.weixin.qq.com/index.php/extend/product/lists?tid=3" target="_blank">微信商户平台/产品大全</a> 下开通“H5支付”'
            ),
            'refund' => array(
                'title'       => '微信退款',
                'type'        => 'checkbox',
                'label'       =>'启用/禁用',
                'default'     =>'yes',
                'description' =>'使用条件：您需要参考<a href="" target="_blank">帮助文档-第7步骤</a>，完成证书部署。'
            )
        );
    }

    public function process_refund( $order_id, $amount = null, $reason = ''){
        $wc_order = wc_get_order ($order_id );
        if(!$wc_order){
            return new WP_Error( 'invalid_order','订单信息异常');
        }

        $total = $wc_order->get_total ();
        if($amount<=0||$amount>$total){
            return new WP_Error( 'invalid_order','退款金额超出总金额或为0' );
        }

        $args = array(
            'appid' => $this->get_option('app_id'),
            'mch_id' => $this->get_option('mch_id'),
            'nonce_str' => str_shuffle(time()),
            'total_fee' => round($total * 100,0),
            'refund_fee' => round($total * 100,0),
            'sign_type' => 'MD5',
            'transaction_id'=>$wc_order->get_transaction_id(),
           // 'out_trade_no'=>'',
            'out_refund_no'=>date_i18n('Ymdhis').$wc_order->get_id(),
            'refund_desc'=>$reason
        );
        try {

            $args['sign'] = self::sign($args);
            $hooks = new Requests_Hooks();
            $hooks->register('curl.before_request',function(&$handler){
                $root_path = plugin_dir_path(WPYAA_WC_AW_FILE);
                curl_setopt($handler,CURLOPT_SSLCERTTYPE,'PEM');
                curl_setopt($handler,CURLOPT_SSLCERT,$root_path.'cert/apiclient_cert.pem');
                curl_setopt($handler,CURLOPT_SSLKEYTYPE,'PEM');
                curl_setopt($handler,CURLOPT_SSLKEY,$root_path.'cert/apiclient_key.pem');
            });

            $response =  wp_remote_post('https://api.mch.weixin.qq.com/secapi/pay/refund',array(
                'hooks'=>$hooks,
                //强制使用curl模式
                'transport'=> 'Requests_Transport_cURL',
                'body' =>  self::arrayToXml($args)
            ));

            if(is_wp_error($response)){
                throw new Exception($response->get_error_message());
            }

            $response = self::xmlToArray(wp_remote_retrieve_body($response));
            if($response['return_code']!=='SUCCESS'){
                throw new Exception("code:{$response['return_code']},msg:{$response['return_msg']}");
            }
            if($response['result_code']!=='SUCCESS'){
                throw new Exception("code:{$response['err_code']},msg:{$response['err_code_des']}");
            }
        }catch(Exception $e){
            wc_get_logger()->error('微信退款失败：',$e->getMessage());
            return new WP_Error( 'refuse_error', $e->getMessage());
        }

        return true;
    }

    /**
	 * 注册支付网关
     * @param $methods
     * @return array
     */
    public function woocommerce_add_gateway($methods) {
        $methods [] = $this;
        return $methods;
    }

    /**
     * 支付成功邮件：支付说明显示
     *
     * @param WC_Order $order 订单信息
     * @param bool $sent_to_admin 是否发送给管理员
     * @param bool $plain_text 是否是纯文本邮件
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        $method = $order->get_payment_method();
        if ( $this->instructions && ! $sent_to_admin && $this->id === $method) {
            echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
        }
    }

    /**
     * 感谢支付页面：支付说明显示
     */
    public function thankyou_page() {
        if ( $this->instructions ) {
            echo wpautop( wptexturize( $this->instructions ) );
        }
    }

	public function process_payment($order_id) {
        return array(
            'result'  => 'success',
            'redirect'=>  add_query_arg([
                'id'=>$order_id
            ],rest_url("/wpyaa/wc/aw/v1/wechat/index"))
        );
	}

	public static function sign($args){
        ksort($args, SORT_STRING);
        $buff = "";
        ksort($args);
        foreach ($args as $k => $v) {
            if (is_null($v) || $v === '' || is_array($v)||$k==='sign') {
                continue;
            }

            if ($buff) {
                $buff .= '&';
            }
            $buff .= "{$k}={$v}";
        }

        $signStr = strtoupper(md5($buff . "&key=" .  Wpyaa_WC_AW_Wechat::instance()->get_option('mch_key')));
        return $signStr;
    }

    public static function arrayToXml($arr){
        $xml = "<xml>";
        foreach ($arr as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
            } else
                $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
        }
        $xml .= "</xml>";
        return $xml;
    }

    public static function xmlToArray($xml){
        $xml_parser = xml_parser_create();
        if(!xml_parse($xml_parser,$xml,true)){
            xml_parser_free($xml_parser);
            return false;
        }else{
            libxml_disable_entity_loader(true);
            return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        }
    }
}