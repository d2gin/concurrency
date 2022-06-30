<?php

namespace icy8\Concurrency;

use Co;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use function Swoole\Coroutine\run;

class Analyze
{
    public    $concurrency = 10; // 瞬间并发量
    public    $group       = [];
    public    $mode        = 'guzzle';// 运行模式 默认为guzzle
    public    $guzzle;
    protected $result      = [
        'ms_time_avg'           => 0, // 平均响应时间 单位：毫秒
        'run_total_time_ms'     => 0, // 脚本运行时长
        'requests_info'         => [],// 请求信息
        'request_total'         => 0, // 请求数
        'request_total_time_ms' => 0, // 所有请求响应的时间计数
    ];

    public function __construct()
    {
        $this->guzzle = new Client();
    }

    public function add($name = '', Url $url = null)
    {
        if ($name instanceof Url) {
            $url  = $name;
            $name = '';
        }
        $this->group[$name][] = $url;
    }

    public function withGuzzle()
    {
        $this->mode = 'guzzle';
        return $this;
    }

    public function withSwoole()
    {
        $this->mode = 'swoole';
        return $this;
    }

    /**
     * 使用guzzle自带的yield来完成并发
     */
    protected function runWithGuzzle()
    {
        $promises = [];
        $client   = $this->guzzle;
        $group    = array_values($this->group); //@todo 默认方法暂不支持分组运行
        /* @var Url $url */
        foreach ($group[0] as $url) {
            $promises[] = function () use ($client, $url) {
                $st              = microtime(true);
                $url->request_at = $st;
                $promise         = $client->requestAsync($url->method, $url->uri, array_merge($url->options, [
                    'body'    => $url->body,
                    'headers' => $url->headers,
                ]));
                $promise->then(function (Response $response) use ($url, $st) {
                    // 记录响应内容
                    $url->response    = $response->getBody()->getContents();
                    $url->complete_at = microtime(true);
                    // 统计响应时间
                    $this->statistic($url, $st);
                    $url->trigger('success');
                });
                return $promise;
            };
        }
        $pool    = new Pool($client, $promises, [
            'concurrency' => $this->concurrency,
        ]);
        $promise = $pool->promise();
        // 计算平均数据
        $promise->then(function () {
            $this->result['ms_time_avg'] = $this->result['request_total_time_ms'] / $this->result['request_total'];
        });
        $promise->wait();
    }

    /**
     * 使用swoole协程来完成并发
     */
    protected function runWithSwoole()
    {
        Co::set(['hook_flags' => SWOOLE_HOOK_ALL | SWOOLE_HOOK_CURL]);
        run(function () {
            foreach ($this->group as $name => $urls) {
                if ($name) {
                    // 分组
                    go(function () use ($urls, $name) {
                        /* @var Url $url */
                        foreach ($urls as $url) {
                            $this->swooleGoUnit($url, $name);
                        }
                    });
                } else {
                    // 没有分组
                    /* @var Url $url */
                    foreach ($urls as $url) {
                        go(function () use ($url, $name) {
                            $this->swooleGoUnit($url, $name);
                        });
                    }
                }
            }
        });
    }

    protected function swooleGoUnit(Url $url, $group)
    {
        try {
            $client           = $this->guzzle;
            $st               = microtime(true);
            $response         = $client->request($url->method, $url->uri, array_merge($url->options, [
                'body'    => $url->body,
                'headers' => $url->headers,
            ]));
            $url->request_at  = $st;
            $url->response    = $response->getBody()->getContents();
            $url->complete_at = microtime(true);
            $this->statistic($url, $st, $group);
            $url->trigger('success');
        } catch (\Throwable $e) {
        }
    }

    public function statistic(Url $url, $startMicrotime, $group = '')
    {
        // 请求用时 ms
        // @todo 计数不准
        $request_use_ms = (microtime(true) - $startMicrotime) * 1000;
        $info           = [
            'url'         => $url->toArray(),
            'use_time_ms' => $request_use_ms,
        ];
        if ($group) {
            $this->result['requests_info'][$group][] = $info;
        } else $this->result['requests_info'][] = $info;
        $this->result['request_total_time_ms'] += $request_use_ms;
        $this->result['request_total']++;
    }

    public function run()
    {
        $st = microtime(true);
        if ($this->mode == 'guzzle') {
            $this->runWithGuzzle();
        } else if ($this->mode == 'swoole') {
            $this->runWithSwoole();
            $this->result['ms_time_avg'] = $this->result['request_total_time_ms'] / $this->result['request_total'];// @todo 不准 仅供参考
        }
        $this->result['run_total_time_ms'] = (microtime(true) - $st) * 1000;
    }

    /**
     * @return array
     */
    public function getResult(): array
    {
        return $this->result;
    }
}
