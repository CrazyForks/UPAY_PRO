<?php

/**
 * UPAY 支付插件 - 易支付对接版本
 * 
 * 优化内容：
 * 1. 修复API参数问题：将'appid'字段改为'type'字段，符合后端API要求
 * 2. 添加币种选择功能：支持USDT-TRC20、TRX、USDT-Polygon三种币种
 * 3. 增强错误处理：添加参数验证、API响应检查和详细的错误日志
 * 4. 改进调试功能：记录API请求和响应信息，便于问题排查
 * 
 * 使用说明：
 * - 在插件配置中选择支持的币种
 * - 确保API地址和Token配置正确
 * - 查看服务器错误日志获取详细的调试信息
 */

// 定义 UPAY 支付插件类
class UPAY_plugin {
    // 插件的基本信息，包括名称、作者、支付类型和输入参数
    static public $info = [
        'name'        => 'UPAY', // 插件名称
        'showname'    => 'UPAY', // 展示名称
        'author'      => 'UPAY', // 作者
        'link'        => 'https://github.com/wangegou/UPAY_PRO', // 官方链接
        'types'       => ['USDT-TRC20', 'TRX', 'USDT-Polygon'], // 支持的支付类型
        'inputs' => [ // 插件需要的输入参数
            'appurl' => [ // API 接口地址
                'name' => 'API接口地址',
                'type' => 'input',
                'note' => '以http://或https://开头，末尾不要有斜线/', // API 地址格式要求
            ],
            'appkey' => [ // API Token，用于签名
                'name' => 'API Token',
                'type' => 'input',
                'note' => '输入UPAY的API Token',
            ],
            'currency' => [ // 支付币种
                'name' => '支付币种',
                'type' => 'select',
                'options' => [
                    'USDT-TRC20' => 'USDT-TRC20',
                    'TRX' => 'TRX',
                    'USDT-Polygon' => 'USDT-Polygon'
                ],
                'note' => '选择支持的支付币种',
            ],
        ],
        'select' => null, // 预留的下拉选择框（当前未使用）
        'note' => '', // 预留的备注信息
        'bindwxmp' => false, // 是否绑定微信公众号
        'bindwxa' => false, // 是否绑定微信小程序
    ];

    /**
     * 获取支持的币种类型
     * @return array 返回支持的币种数组
     */
    static public function getSupportedTypes() {
        global $channel;
        
        // 如果用户选择了币种，返回该币种
        if (isset($channel['currency']) && !empty($channel['currency'])) {
            return [$channel['currency']];
        }
        
        // 默认返回所有支持的币种
        return ['USDT-TRC20', 'TRX', 'USDT-Polygon'];
    }

    /**
     * 处理支付提交
     * @return array 返回跳转到支付页面的 URL
     */
    static public function submit() {
        global $siteurl, $channel, $order, $sitename;

        // 检查订单支付类型是否在支持的币种中
        if (in_array($order['typename'], self::getSupportedTypes())) {
            // 返回跳转类型的支付 URL
            return ['type' => 'jump', 'url' => '/pay/UPAY/' . TRADE_NO . '/?sitename=' . $sitename];
        }
    }

    /**
     * 移动端 API 支付调用
     * @return mixed 调用 UPAY 方法
     */
    static public function mapi() {
        global $order;
        
        // 检查订单支付类型是否在支持的币种中
        if (in_array($order['typename'], self::getSupportedTypes())) {
            return self::UPAY($order['typename']);
        }
    }

    /**
     * 获取 API 接口 URL
     * @return string 返回处理后的 API 地址
     */
    static private function getApiUrl() {
        global $channel;
        
        // 获取用户设置的 API 地址
        $apiurl = $channel['appurl'];
        
        // 确保 API 地址末尾没有 '/'
        if (substr($apiurl, -1) == '/') {
            $apiurl = substr($apiurl, 0, -1);
        }
        return $apiurl;
    }

    /**
     * 发送 HTTP 请求到 UPAY API
     * @param string $url API 端点路径
     * @param array $param 发送的参数
     * @return array 返回 JSON 解析后的响应数据
     * @throws Exception 请求失败时抛出异常
     */
    static private function sendRequest($url, $param) {
        // 组合完整的 API URL
        $fullUrl = self::getApiUrl() . $url;
        
        // 将参数转换为 JSON
        $post = json_encode($param, JSON_UNESCAPED_UNICODE);
        
        // 记录请求信息
        error_log('UPAY API请求: ' . $fullUrl);
        error_log('UPAY请求数据: ' . $post);
        
        // 发送 HTTP 请求
        $response = get_curl($fullUrl, $post, 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        
        // 检查响应是否为空
        if (empty($response)) {
            throw new Exception('API响应为空，请检查网络连接或API地址');
        }
        
        // 解析 JSON 响应
        $result = json_decode($response, true);
        
        // 检查 JSON 解析是否成功
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('UPAY JSON解析失败: ' . json_last_error_msg());
            error_log('UPAY原始响应: ' . $response);
            throw new Exception('API响应格式错误: ' . json_last_error_msg());
        }
        
        return $result;
    }

    /**
     * 生成 API 签名
     * @param array $params 需要签名的参数
     * @param string $apiToken API Token
     * @return string 返回 MD5 签名字符串
     */
    static public function Sign($params, $apiToken) {
        // 按参数名进行 ASCII 排序
        ksort($params);
        $str = '';
        
        // 拼接参数
        foreach ($params as $k => $val) {
            if ($val !== '') {
                $str .= $k . '=' . $val . '&';
            }
        }
        
        // 在末尾拼接 API Token
        $str = rtrim($str, '&') . $apiToken;
        
        // 返回 MD5 加密的签名
        return md5($str);
    }

    /**
     * 创建 UPAY 订单
     * @return string 返回支付链接
     * @throws Exception 订单创建失败时抛出异常
     */
    static private function CreateOrder() {
        global $siteurl, $channel, $order, $conf;

        // 验证必要参数
        if (empty($order['typename'])) {
            throw new Exception('支付币种类型不能为空');
        }
        
        if (!in_array($order['typename'], ['USDT-TRC20', 'TRX', 'USDT-Polygon'])) {
            throw new Exception('不支持的币种类型: ' . $order['typename']);
        }

        // 构造请求参数
        $param = [
            'order_id' => TRADE_NO, // 订单号
            'amount' => floatval($order['realmoney']), // 订单金额
            'type' => $order['typename'], // 支付币种类型
            'notify_url' => $conf['localurl'] . 'pay/notify/' . TRADE_NO . '/', // 异步通知 URL
            'redirect_url' => $siteurl . 'pay/return/' . TRADE_NO . '/', // 同步跳转 URL
        ];

        // 生成签名
        $param['signature'] = self::Sign($param, $channel['appkey']);

        // 记录请求参数（用于调试）
        error_log('UPAY请求参数: ' . json_encode($param, JSON_UNESCAPED_UNICODE));

        // 发送订单请求
        $result = self::sendRequest('/api/create_order', $param);
        
        // 记录响应结果（用于调试）
        error_log('UPAY响应结果: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

        // 处理响应数据
        if (isset($result["status_code"]) && $result["status_code"] == 200) {
            \lib\Payment::updateOrder(TRADE_NO, $result['data']);
            return $result['data']['payment_url'];
        } else {
            $errorMsg = $result["message"] ?? '返回数据解析失败';
            error_log('UPAY创建订单失败: ' . $errorMsg);
            throw new Exception($errorMsg);
        }
    }

    /**
     * 执行 UPAY 订单创建流程
     * @return array 返回跳转支付的 URL 或错误信息
     */
    static public function UPAY() {
        try {
            $code_url = self::CreateOrder();
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => 'UPAY创建订单失败！' . $ex->getMessage()];
        }
        return ['type' => 'jump', 'url' => $code_url];
    }

    /**
     * 处理 UPAY 异步通知
     * @return array 返回异步通知结果
     */
    static public function notify() {
        global $channel, $order;

        // 获取通知数据
        $resultJson = file_get_contents("php://input");
        $resultArr = json_decode($resultJson, true);

        // 获取签名并移除签名字段
        $Signature = $resultArr["signature"];
        unset($resultArr['signature']);

        // 计算本地签名
        $sign = self::Sign($resultArr, $channel['appkey']);

        // 校验签名是否正确
        if ($sign === $Signature) {
            $out_trade_no = $resultArr['order_id'];
            if ($out_trade_no == TRADE_NO && $resultArr['status'] == 2) {
                // 处理回调通知（订单支付成功）
                processNotify($order, $out_trade_no);
                return ['type' => 'html', 'data' => 'ok'];
            }
        }
        return ['type' => 'html', 'data' => 'fail'];
    }

    /**
     * 处理同步跳转返回
     * @return array 返回跳转页面
     */
    static public function return() {
        return ['type' => 'page', 'page' => 'return'];
    }
}