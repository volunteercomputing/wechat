<?php

namespace Overtrue\Wechat;

use Exception;
use Overtrue\Wechat\Utils\Bag;
use Overtrue\Wechat\Utils\XML;
use Overtrue\Wechat\Utils\Http;
use Overtrue\Wechat\Messages\AbstractMessage;

/**
 * @property \Overtrue\Wechat\Utils\Bag $options
 */
class Wechat
{
    /**
     * POST输入
     *
     * @var \Overtrue\Wechat\Utils\Bag
     */
    protected $post;

    /**
     * GET输入
     *
     * @var \Overtrue\Wechat\Utils\Bag
     */
    protected $query;

    /**
     * 选项
     *
     * @var \Overtrue\Wechat\Utils\Bag
     */
    protected $options;

    /**
     * 监听器
     *
     * @var \Overtrue\Wechat\Utils\Bag
     */
    protected $listeners;

    /**
     * 是否为加密模式
     *
     * @var boolean
     */
    protected $security = false;

    /**
     * 错误处理器
     *
     * @var callable
     */
    protected $errorHandler;

    /**
     * access_token
     *
     * @var string
     */
    protected $accessToken;

    /**
     * 自动添加access_token
     *
     * @var boolean
     */
    protected $autoRequestToken = true;

    /**
     * 已经实例化过的服务
     *
     * @var array
     */
    protected $resolved = array();

    const API_TOKEN_GET = 'https://api.weixin.qq.com/cgi-bin/token';


    /**
     * 获取实例
     *
     * @param array $options
     */
    public function __construct($options)
    {
        if (empty($options['app_id'])
            || empty($options['secret'])
            || empty($options['token'])) {
            throw new Exception("配置至少包含三项'app_id'、'secret'、'token'且不能为空！");
        }

        $this->listeners = new Bag;
        $this->options   = new Bag($options);
        $this->query     = new Bag($_REQUEST);
        $this->post      = new Bag($this->getPost());

        set_exception_handler(function($e){
            if ($this->errorHandler) {
                return call_user_func_array($this->errorHandler, array($e));
            }

            throw $e;
        });
    }

    private function __clone() {}

    /**
     * 监听
     *
     * @param string   $target
     * @param string   $type
     * @param callable $callback
     *
     * @return string
     */
    public function on($target, $type, $callback = null)
    {
        if (func_num_args() == 2) {
            $callback = $type;
            $type     = '*';
        }

        if (!is_callable($callback)) {
            return false;
        }

        if (!$listeners = $this->listeners->get("{$target}.{$type}")) {
            $listeners = array();
        }

        array_push($listeners, $callback);

        $this->listeners->set("{$target}.{$type}", $listeners);
    }

    /**
     * 监听事件
     *
     * @param string   $type
     * @param callable $callback
     *
     * @return mixed
     */
    public function event($type, $callback)
    {
        return $this->on("event", $type, $callback);
    }

    /**
     * 监听消息
     *
     * @param string   $type
     * @param callable $callback
     *
     * @return string
     */
    public function message($type, $callback)
    {
        return $this->on("message", $type, $callback);
    }

    /**
     * handle服务端并返回字符串内容
     *
     * @return mixed
     */
    public function serve()
    {
        $input = array(
                $this->options->get('token'),
                $this->query->get('timestamp'),
                $this->query->get('nonce'),
              );

        if (!$this->signature($input) === $this->query->get('signature')) {
            throw new Exception("Bad Request", 400);
        }

        if ($this->query->has('echostr')) {
            return $this->query['echostr'];
        }

        $response = $this->handleRequest();

        return $this->response($response);
    }

    /**
     * 错误处理器
     *
     * @param function $handler
     *
     * @return void
     */
    public function error($handler)
    {
        is_callable($handler) && $this->errorHandler = $handler;
    }

    /**
     * 获取服务
     *
     * @param string $service
     *
     * @return mixed
     */
    public function service($service)
    {
        if (isset($this->resolved[$service])) {
            return $this->resolved[$service];
        }

        $service = "Overtrue\Wechat\Services\\" . ucfirst($service);

        if (!class_exists($service)) {
            throw new Exception("未知的服务'$service'");
        }

        return $this->resolved[$service] = new $service($this);
    }

    /**
     * 获取监听器
     *
     * @param string $type
     *
     * @return array
     */
    public function getListeners($type = null)
    {
        return $type ? $this->listeners->get($type) : $this->listeners->all();
    }

    /**
     * 自动添加access_token参数
     *
     * @param boolean $status
     *
     * @return void
     */
    public function autoRequestToken($status)
    {
        $this->autoRequestToken = (bool) $status;
    }

    /**
     * 生成url
     *
     * @param string $url     基础网址
     * @param array  $queries 查询
     *
     * @return string
     */
    public function makeUrl($url, $queries = array())
    {
        if ($this->autoRequestToken) {
            $queries['access_token'] = $this->getAccessToken();
        }

        return $url . (empty($queries) ? '' : ('?' . http_build_query($queries)));
    }

    /**
     * 获取access_token
     *
     * @return string
     */
    protected function getAccessToken()
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $key = 'overtrue.wechat.access_token';

        if ($cached = $this->service('cache')->get($key)) {
            return $cached;
        }

        // 关闭自动加access_token参数
        $this->autoRequestToken(false);

        $url = $this->makeUrl(self::API_TOKEN_GET, array(
                                                    'appid'      => $this->options->get('app_id'),
                                                    'secret'     => $this->options->get('secret'),
                                                    'grant_type' => 'client_credential',
                                                   ));
        // 开启自动加access_token参数
        $this->autoRequestToken(true);

        $token = $this->request('GET', $url);

        $this->service('cache')->set($key, $token['access_token'], $token['expires_in']);

        return $token['access_token'];
    }

    /**
     * 发起一个HTTP/HTTPS的请求
     *
     * @param string $method  请求类型   GET | POST
     * @param string $url     接口的URL
     * @param array  $params  接口参数
     * @param array  $files   图片信息
     * @param array  $headers 图片信息
     *
     * @return array
     */
    public function request($method, $url, array $params = array(), array $files = array(), $headers = array())
    {
        $response = Http::request($method, $url, $params, $headers, $files);

        if (empty($response)) {
            throw new Exception("服务器无响应");
        }

        $contents = json_decode($response, true);

        if(isset($contents['errcode'])){
            if ($contents['errmsg'] == 'ok') {
                return true;
            }

            throw new Exception("[{$contents['errcode']}] ".$contents['errmsg'], $contents['errcode']);
        }

        return $contents;
    }

    /**
     * 生成回复内容
     *
     * @param mixed $response
     *
     * @return string
     */
    protected function response($response)
    {
        if (is_string($response)) {
            //TODO：修改消息生成方式
            $response = Message::make(Message::TEXT)->with('content', $response);
        }

        if ($response instanceof AbstractMessage) {
            $response->from($this->post->get('ToUserName'))->to($this->post->get('FromUserName'));

            $xml = $response->formatToServer();

            if ($this->security) {
                return $this->service('crypt')->encryptMsg($xml, $this->query->get('nonce'), $this->query->get('timestamp'));
            }

            return $xml;
        }

        return null;
    }

    /**
     * 检查微信签名有效性
     */
    protected function signature($input)
    {
        sort($input, SORT_STRING);

        return sha1(implode($input));
    }

    /**
     * 获取POST请求数据
     *
     * @return array
     */
    protected function getPost()
    {
        $xmlInput = !empty($GLOBALS["HTTP_RAW_POST_DATA"])
                ? $GLOBALS["HTTP_RAW_POST_DATA"] : file_get_contents("php://input");

        $input = XML::parse($xmlInput);

        if ($this->query->get('encrypt_type') == 'aes') {
            $this->security = true;

            $input = $this->service('crypt')->decryptMsg($this->query->get('msg_signature'),
                            $this->query->get('nonce'), $this->query->get('timestamp'), $xmlInput);
        }

        return array_merge($_POST, (array) $input);
    }

    /**
     * 处理微信的请求
     *
     * @return mixed
     */
    protected function handleRequest()
    {
        if ($this->post->has('MsgId')) {
            return $this->handleMessage($this->post);
        } else if ($this->post->has('MsgType') && $this->post->get('MsgType') == 'event') {
            return $this->handleEvent($this->post);
        }

        return false;
    }

    /**
     * 处理消息
     *
     * @param array $message
     *
     * @return mixed
     */
    protected function handleMessage($message)
    {
        if (!is_null($response = $this->call("message.*", array($message)))) {
            return $response;
        }

        return $this->call("message.{$message['MsgType']}", array($message));
    }

    /**
     * 处理事件
     *
     * @param array $event
     *
     * @return mixed
     */
    protected function handleEvent($event)
    {
        if (!is_null($response = $this->call("event.*", array($event)))) {
            return $response;
        }

        return $this->call("event.{$event['Event']}", array($event));
    }

    /**
     * 调用监听器
     *
     * @param string $key
     * @param array  $args
     *
     * @return mixed
     */
    protected function call($key, $args)
    {
        $handlers = (array) $this->listeners[$key];

        if (empty($handlers)) {
            return null;
        }

        foreach ($handlers as $handler) {
            if (!is_callable($handler)) {
                continue;
            }

            $res = call_user_func_array($handler, $args);

            if (!is_null($res)) {
                return $res;
            }
        }

        return null;
    }

        /**
     * 魔术调用
     *
     * @param string $method
     * @param array  $args
     *
     * @return mixed
     */
    public function __call($method, $args)
    {
        return $this->service($method);
    }

    /**
     * 处理魔术调用
     *
     * @param string $property
     *
     * @return mixed
     */
    public function __get($property)
    {
        if (property_exists($this, $property)) {
            return $this->{$property};
        }

        return $this->service($property);
    }

    /**
     * 防止序列化
     *
     * @return null
     */
    public function __sleep()
    {
        return null;
    }
}