<?php

namespace LaravelFly\ApplicationTrait;

use Illuminate\Events\EventServiceProvider;
//use LaravelFly\Routing\RoutingServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use LaravelFly\Simple\ProviderRepositoryInRequest;

trait Server
{

    /**
     * @var \LaravelFly\Server\ServerInterface|\LaravelFly\Server\HttpServer|\LaravelFly\Server\FpmHttpServer
     */
    protected $server;

    /**
     * @return \LaravelFly\Server\ServerInterface|\LaravelFly\Server\FpmHttpServer|\LaravelFly\Server\HttpServer
     */
    public function getServer()
    {
        return $this->server;
    }

    /**
     * @param \LaravelFly\Server\ServerInterface|\LaravelFly\Server\FpmHttpServer|\LaravelFly\Server\HttpServer $server
     */
    public function setServer($server)
    {
        $this->server = $server;
    }

    /**
     * @return int 0 or 1,  or bool from parent
     */
    public function isDownForMaintenance(): int
    {
        static $watch = null;
        if ($watch === null) {
            $watch = $this->getServer()->getConfig('watch_down');
        }
        return $watch ? $this->server->getMemory('isDown') : parent::isDownForMaintenance();
    }

}
