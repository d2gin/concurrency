<?php

namespace icy8\Concurrency;
class Url
{
    public    $headers     = [];
    public    $uri         = '';
    public    $method      = 'GET';
    public    $body        = null;
    public    $options     = [];
    public    $response    = null;
    public    $request_at  = 0;
    public    $complete_at = 0;
    protected $onSuccess;
    protected $onFail;

    public function __construct($uri = '')
    {
        $this->uri = $uri;
    }

    public function then($success = null, $fail = null)
    {
        $this->onSuccess = $success;
        $this->onFail    = $fail;
    }

    public function trigger($event)
    {
        $method = $this->{'on' . ucwords($event)};
        if ($method) {
            return call_user_func_array($method, [$this]);
        }
        return false;
    }

    public function toArray()
    {
        return [
            'headers'     => $this->headers,
            'uri'         => $this->uri,
            'method'      => $this->method,
            'body'        => $this->body,
            'options'     => $this->options,
            'response'    => $this->response,
            'request_at'  => $this->request_at,
            'complete_at' => $this->complete_at,
        ];
    }

    public function __toString()
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }
}