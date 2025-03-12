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

use think\gatewayclient\Connection;

/**
 * Gateway主动推送客户端
 */
class GatewayClient
{
    /**
     * 连接实例
     * @var array
     */
    protected $instance = [];

    /**
     * 配置
     * @var Config
     */
    protected $config;

    /**
     * 架构方法
     * @access public
     * @param Config $config 配置对象
     * @return void
     */
    public function __construct(Config $config)
    {
        // 记录配置对象
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

    /**
     * 创建/切换连接
     * @access public
     * @param string|array|null $connection 连接配置标识
     * @return Connection
     */
    public function connect($connection = null)
    {
        // 如果是数组
        if(is_array($connection)){
            // 连接参数
            $options = array_merge([
                // Gateway注册服务地址
                'register_address' => '',
                // 密钥, 为对应Register服务设置的密钥
                'secret_key' => '',
                // 连接超时时间，单位：秒
                'connect_timeout' => 3,
                // 与Gateway是否是长链接
                'persistent_connection' => false,
            ], $connection);
            // 连接标识
            $name = hash('md5', json_encode($options));
            // 连接不存在
            if (!isset($this->instance[$name])) {
                // 创建连接
                $this->instance[$name] = new Connection($options);
            }

            return $this->instance[$name];
        }
        
        // 标识为空
        if (empty($connection)) {
            $connection = $this->getConfig('default', 'localhost');
        }
        // 连接不存在
        if (!isset($this->instance[$connection])) {
            // 获取配置中的全部连接配置
            $connections = $this->getConfig('connections');
            // 配置不存在
            if (!isset($connections[$connection])) {
                throw new \Exception('Undefined gatewayclient connections config:' . $connection);
            }
            // 创建链接
            $this->instance[$connection] = new Connection($connections[$connection]);
        }
        
        // 返回已存在连接实例
        return $this->instance[$connection];
    }

    /**
     * 获取所有连接实列
     * @access public
     * @return array
     */
    public function getInstance()
    {
        return $this->instance;
    }

    public function __call($method, array $args)
    {
        return call_user_func_array([$this->connect(), $method], $args);
    }
}
