<?php

namespace AlibabaCloud\Dara;

use Adbar\Dot;
use AlibabaCloud\Dara\Exception\DaraException;
use AlibabaCloud\Dara\RetryPolicy\RetryOptions;
use AlibabaCloud\Dara\RetryPolicy\RetryPolicyContext;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;



/**
 * Class Dara.
 */
class Dara
{
    /**
     * @var array
     */
    private static $config = [];

    const MAX_DELAY_TIME = 120 * 1000;
    const MIN_DELAY_TIME = 100;

    public static function config(array $config)
    {
        self::$config = $config;
    }

    /**
     * @throws GuzzleException
     *
     * @return Response
     */
    public static function send(Request $request, array $config = [])
    {
        if (method_exists($request, 'getPsrRequest')) {
            $request = $request->getPsrRequest();
        }

        $config = self::resolveConfig($config);
        $res = self::client()->send(
            $request,
            $config
        );

        return new Response($res);
    }

    /**
     * @return PromiseInterface
     */
    public static function sendAsync(RequestInterface $request, array $config = [])
    {
        if (method_exists($request, 'getPsrRequest')) {
            $request = $request->getPsrRequest();
        }

        $config = self::resolveConfig($config);

        return self::client()->sendAsync(
            $request,
            $config
        );
    }

    /**
     * @return Client
     */
    public static function client(array $config = [])
    {
        if (isset(self::$config['handler'])) {
            $stack = self::$config['handler'];
        } else {
            $stack = HandlerStack::create();
            $stack->push(Middleware::mapResponse(static function (ResponseInterface $response) {
                return new Response($response);
            }));
        }

        self::$config['handler'] = $stack;

        if (!isset(self::$config['on_stats'])) {
            self::$config['on_stats'] = function (TransferStats $stats) {
                Response::$info = $stats->getHandlerStats();
            };
        }

        $new_config = Helper::merge([self::$config, $config]);
        return new Client($new_config);
    }

    /**
     * @param string              $method
     * @param string|UriInterface $uri
     * @param array               $options
     *
     * @throws GuzzleException
     *
     * @return ResponseInterface
     */
    public static function request($method, $uri, $options = [])
    {
        return self::client()->request($method, $uri, $options);
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array  $options
     *
     * @throws GuzzleException
     *
     * @return string
     */
    public static function string($method, $uri, $options = [])
    {
        return (string) self::client()->request($method, $uri, $options)
            ->getBody();
    }

    /**
     * @param string              $method
     * @param string|UriInterface $uri
     * @param array               $options
     *
     * @return PromiseInterface
     */
    public static function requestAsync($method, $uri, $options = [])
    {
        return self::client()->requestAsync($method, $uri, $options);
    }

    /**
     * @param string|UriInterface $uri
     * @param array               $options
     *
     * @throws GuzzleException
     *
     * @return null|mixed
     */
    public static function getHeaders($uri, $options = [])
    {
        return self::request('HEAD', $uri, $options)->getHeaders();
    }

    /**
     * @param string|UriInterface $uri
     * @param string              $key
     * @param null|mixed          $default
     *
     * @throws GuzzleException
     *
     * @return null|mixed
     */
    public static function getHeader($uri, $key, $default = null)
    {
        $headers = self::getHeaders($uri);

        return isset($headers[$key][0]) ? $headers[$key][0] : $default;
    }

    /**
     * @param int   $retryTimes
     * @param float $now
     *
     * @return bool
     */
    public static function allowRetry(array $runtime, $retryTimes, $now)
    {
        unset($now);
        if (!isset($retryTimes) || null === $retryTimes || !\is_numeric($retryTimes)) {
            return false;
        }
        if ($retryTimes > 0 && (empty($runtime) || !isset($runtime['retryable']) || !$runtime['retryable'] || !isset($runtime['maxAttempts']))) {
            return false;
        }
        $maxAttempts = $runtime['maxAttempts'];
        $retry       = empty($maxAttempts) ? 0 : (int) $maxAttempts;

        return $retry >= $retryTimes;
    }

    /**
     * @param int $retryTimes
     *
     * @return int
     */
    public static function getBackoffTime(array $runtime, $retryTimes)
    {
        $backOffTime = 0;
        $policy      = isset($runtime['policy']) ? $runtime['policy'] : '';

        if (empty($policy) || 'no' == $policy) {
            return $backOffTime;
        }

        $period = isset($runtime['period']) ? $runtime['period'] : '';
        if (null !== $period && '' !== $period) {
            $backOffTime = (int) $period;
            if ($backOffTime <= 0) {
                return $retryTimes;
            }
        }

        return $backOffTime;
    }

    public static function sleep($time)
    {
        sleep($time);
    }

    public static function isRetryable($retry, $retryTimes = 0)
    {
        if ($retry instanceof DaraException) {
            return true;
        }
        if (\is_array($retry)) {
            $max = isset($retry['maxAttempts']) ? (int) ($retry['maxAttempts']) : 3;

            return $retryTimes <= $max;
        }

        return false;
    }


    /**
     * 
     * @param RetryOptions $options
     * @param RetryPolicyContext $optctxions
     * @return bool
     */
    public static function shouldRetry($options, $ctx) {
        if($ctx->getRetryCount() === 0) {
            return true;
        }

        if (!$options || !$options->getRetryable()) {
            return false;
        }
    
        $retriesAttempted = $ctx->getRetryCount();
        $ex = $ctx->getException();
        $conditions = $options->getNoRetryCondition();
    
        foreach ($conditions as $condition) {
            if (in_array($ex->getName(), $condition->getException()) || in_array($ex->getErrCode(), $condition->getErrorCode())) {
                return false;
            }
        }
    
        $conditions = $options->getRetryCondition();
        foreach ($conditions as $condition) {
            if (!in_array($ex->getName(), $condition->getException()) && !in_array($ex->getErrCode(), $condition->getErrorCode())) {
                continue;
            }
            if ($retriesAttempted >= $condition->getMaxAttempts()) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * 
     * @param RetryOptions $options
     * @param RetryPolicyContext $optctxions
     * @return int
     */
    public static function getBackoffDelay($options, $ctx) {
        $ex = $ctx->getException();
        $fullClassName = get_class($ex);
        $classNameParts = explode('\\', $fullClassName);
        $className = end($classNameParts);
        $conditions = $options->getRetryCondition();
        foreach ($conditions as $condition) {

            if (!in_array($className, $condition->getException()) && !in_array($ex->getErrCode(), $condition->getErrorCode())) {
                continue;
            }
    
            $maxDelay = $condition->getMaxDelay() ?: self::MAX_DELAY_TIME;
            $retryAfter = method_exists($ex, 'getRetryAfter') ? $ex->getRetryAfter() : null;
    
            if ($retryAfter !== null) {
                return min($retryAfter, $maxDelay);
            }
            
            
            $backoff = $condition->getBackoff();
            if (!isset($backoff) || null === $backoff) {
                return self::MIN_DELAY_TIME;
            }
    
            return min($backoff->getDelayTime($ctx), $maxDelay);
        }
    
        return self::MIN_DELAY_TIME;
    }

    /**
     * @param mixed|Model[] ...$item
     *
     * @return mixed
     */
    public static function merge(...$item)
    {
        $tmp = [];
        $n   = 0;
        foreach ($item as $i) {
            if (\is_object($i)) {
                if ($i instanceof Model) {
                    $i = $i->toMap();
                } else {
                    $i = json_decode(json_encode($i), true);
                }
            }
            if (null === $i) {
                continue;
            }
            if (\is_array($i)) {
                $tmp[$n++] = $i;
            }
        }

        if (\count($tmp)) {
            return \call_user_func_array('array_merge', $tmp);
        }

        return [];
    }

    private static function resolveConfig(array $config = [])
    {
        $options = new Dot(['http_errors' => false]);
        if (isset($config['httpProxy']) && !empty($config['httpProxy'])) {
            $options->set('proxy.http', $config['httpProxy']);
        }
        if (isset($config['httpsProxy']) && !empty($config['httpsProxy'])) {
            $options->set('proxy.https', $config['httpsProxy']);
        }
        if (isset($config['noProxy']) && !empty($config['noProxy'])) {
            $options->set('proxy.no', $config['noProxy']);
        }
        if (isset($config['ignoreSSL']) && !empty($config['ignoreSSL'])) {
            $options->set('verify',!((bool)$config['ignoreSSL']));
        }
        if (isset($config['stream']) && !empty($config['stream'])) {
            $options->set(RequestOptions::STREAM, (bool)$config['stream']);
        }
        // readTimeout&connectTimeout unit is millisecond
        $read_timeout = isset($config['readTimeout']) && !empty($config['readTimeout']) ? (int) $config['readTimeout'] : 3000;
        $con_timeout  = isset($config['connectTimeout']) && !empty($config['connectTimeout']) ? (int) $config['connectTimeout'] : 3000;
        // timeout unit is second
        $options->set('timeout', ($read_timeout + $con_timeout) / 1000);

        return $options->all();
    }
}
