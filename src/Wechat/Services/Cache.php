<?php

namespace Overtrue\Wechat\Services;

class Cache extends Service
{
    /**
     * 缓存文件前缀
     *
     * @var string
     */
    protected $filePrefix;

    /**
     * 缓存写入器
     *
     * @var callable
     */
    protected $cacheSetter;

    /**
     * 缓存读取器
     *
     * @var callable
     */
    protected $cacheGetter;


    /**
     * 设置缓存文件前缀
     *
     * @return void
     */
    public function boot()
    {
        $this->filePrefix = $this->wechat->options->get('app_id');
    }

    /**
     * 默认的缓存写入器
     *
     * @param string  $key
     * @param mixed   $value
     * @param integer $lifetime
     *
     * @return void
     */
    public function set($key, $value, $lifetime = 7200)
    {
        if ($handler = $this->cacheSetter) {
            return call_user_func_array($handler, func_get_args());
        }

        $data = array(
                 'token'      => $value,
                 'expired_at' => time() + $lifetime - 2, //XXX: 减去2秒更可靠的说
                );

        if (!file_put_contents($this->getCacheFile($key), serialize($data))) {
            throw new Exception("Access toekn 缓存失败");
        }
    }

    /**
     * 默认的缓存读取器
     *
     * @param string   $key
     *
     * @return void
     */
    public function get($key)
    {
        if ($handler = $this->cacheGetter) {
            return call_user_func_array($handler, func_get_args());
        }

        $file = $this->getCacheFile($key);

        if (file_exists($file) && $token = unserialize(file_get_contents($file))) {
            return $token['expired_at'] > time() ? $token['token'] : null;
        }

        return null;
    }

    /**
     * 设置缓存写入器
     *
     * @param callable $handler
     *
     * @return void
     */
    public function cacheSetter($handler)
    {
        is_callable($handler) && $this->cacheSetter = $handler;
    }

    /**
     * 设置缓存读取器
     *
     * @param callable $handler
     *
     * @return void
     */
    public function cacheGetter($handler)
    {
        is_callable($handler) && $this->cacheGetter = $handler;
    }

    /**
     * 获取缓存文件名
     *
     * @param string $key
     *
     * @return string
     */
    protected function getCacheFile($key)
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . md5($this->filePrefix . $key);
    }
}