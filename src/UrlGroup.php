<?php

namespace icy8\Concurrency;
class UrlGroup
{
    protected $list = [];

    public function push(Url $item)
    {
        $this->list[] = $item;
        return $this;
    }

    public function unshift()
    {
    }

    public function getList()
    {
        return $this->list;
    }
}