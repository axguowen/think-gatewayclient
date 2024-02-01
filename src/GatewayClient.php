<?php
// +----------------------------------------------------------------------
// | ThinkPHP Gateway Client [Gateway Client For ThinkPHP]
// +----------------------------------------------------------------------
// | ThinkPHP Gateway Client
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: axguowen <axguowen@qq.com>
// +----------------------------------------------------------------------

namespace think;

use axguowen\GatewayClient as BaseClient;

/**
 * Gateway主动推送客户端
 */
class GatewayClient extends BaseClient
{
    /**
     * make方法
     * @param Config $config 配置对象
     * @return GatewayClient
     */
    public static function __make(Config $config)
    {
        $client = new static();
        $client->setConfig($config);
        return $client;
    }

    /**
     * 设置配置对象
     * @access public
     * @param Config $config 配置对象
     * @return void
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * 获取配置参数
     * @access public
     * @param string $name 配置参数
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getConfig($name = '', $default = null)
    {
        if ('' !== $name) {
            return $this->config->get('gatewayclient.' . $name, $default);
        }

        return $this->config->get('gatewayclient', []);
    }
}
