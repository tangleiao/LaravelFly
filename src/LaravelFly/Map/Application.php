<?php

namespace LaravelFly\Map;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use LaravelFly\Map\IlluminateBase\EventServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Log\LogServiceProvider;

use Illuminate\Filesystem\Filesystem;
use LaravelFly\Simple\ProviderRepository;

class Application extends \Illuminate\Foundation\Application
{

    use \LaravelFly\ApplicationTrait\ProvidersInRequest;
    use \LaravelFly\ApplicationTrait\InConsole;
    use \LaravelFly\ApplicationTrait\Server;
    use \LaravelFly\ApplicationTrait\NoMorePackageManifest;

    /**
     * @var bool
     */
    protected $bootedOnWorker = false;

    /**
     * @var bool
     */
//    protected $bootedInRequest = [];

    /**
     * @var array
     */
    protected $providersToBootOnWorker = [];

    /**
     * @var array
     */
    protected $acrossServiceProviders = [];

    protected $CFServices = [];
    protected $cloneServices = [];
    protected $updateForClone = [];

    protected static $arrayAttriForObj = ['resolved', 'bindings', 'methodBindings', 'instances', 'aliases', 'abstractAliases', 'extenders', 'tags', 'buildStack', 'with', 'contextual', 'reboundCallbacks', 'globalResolvingCallbacks', 'globalAfterResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks',

        'bootingCallbacks',
        'bootedCallbacks',
        'terminatingCallbacks',
        'serviceProviders',
        'loadedProviders',
        'deferredServices'
    ];
    protected static $normalAttriForObj = [
        'hasBeenBootstrapped' => false,
        'booted' => false,
        'bootedInRequest' => false,
    ];

    public function __construct($basePath = null)
    {
        parent::__construct($basePath);
        static::$instance = $this;
    }

    /*
     * Override
     * use new providers for
     * 1. new services with __clone
     * 2. compiled all routes which are made before request
     */
    protected function registerBaseServiceProviders()
    {
        $this->register(new EventServiceProvider($this));

        $this->register(new LogServiceProvider($this));

        $this->register(new RoutingServiceProvider($this));
    }

    public function initForRequestCorontine($cid)
    {
        parent::initForRequestCorontine($cid);

        /**
         * replace $this->register(new RoutingServiceProvider($this));
         *
         * order is important, because relations:
         *  router.routes
         *  url.routes
         */

        /**
         *
         * url is not needed to implement __clone() method, because it's  attributes will updated auto.
         * so it should be before routes which is cloned by {@link \Illuminate\Routing\Router::initForRequestCorontine } .
         *
         * @see \Illuminate\Routing\RoutingServiceProvider::registerUrlGenerator()
         * @todo test
         */
        Facade::initForRequestCorontine($cid);
        $this->make('events')->initForRequestCorontine($cid);
        // make an new url object which is independent from origin url object
        $this->instance('url', clone $this->make('url'));
        $this->make('router')->initForRequestCorontine($cid);

        $this->make('events')->dispatch('request.corinit', [$cid]);

        foreach($this->cloneServices as $service){
            $this->instance($service, clone $this->make($service));
        }
        foreach ($this->updateForClone as $item ){
            $item['closure']->call($this->make($item['this']));
        }
    }

    function unsetForRequestCorontine(int $cid)
    {

        $this->make('events')->dispatch('request.corunset', [$cid]);

        $this->make('router')->unsetForRequestCorontine($cid);

        Facade::unsetForRequestCorontine($cid);

        //this should be the last second, events maybe used by anything, like dispatch 'request.corunset'
        $this->make('events')->unsetForRequestCorontine($cid);

        //this should be the last line, otherwise $this->make('events') can not work
        parent::unsetForRequestCorontine($cid);
    }

    public function setProvidersToBootOnWorker($providers)
    {
        if ($providers)
            $this->providersToBootOnWorker = $providers;
    }

    public function setCFServices($services)
    {
        if ($services)
            $this->CFServices = $services;
    }

    public function setCloneServices($services, $update)
    {
        if ($services){
            $this->cloneServices = $services;
        }

        if ($update)
            $this->updateForClone = $update;
    }

    public function registerAcrossProviders()
    {

        if ($providers = $this->make('config')->get('app.providers')) {
            $serviceProvidersBack = static::$corDict[WORKER_COROUTINE_ID]['serviceProviders'];
            static::$corDict[WORKER_COROUTINE_ID]['serviceProviders'] = [];

            //todo update code
            (new ProviderRepository($this, new Filesystem, $this->getCachedServicesPathAcross()))
                ->load($providers);

            $this->acrossServiceProviders = static::$corDict[WORKER_COROUTINE_ID]['serviceProviders'];
            static::$corDict[WORKER_COROUTINE_ID]['serviceProviders'] = $serviceProvidersBack;
        }

    }

    public function getCachedServicesPathAcross(): string
    {
        return $this->bootstrapPath() . '/cache/laravelfly_services_across.json';
    }

    public function registerConfiguredProvidersBootOnWorker()
    {

        //todo study official registerConfiguredProviders
        $providerRepository = new ProviderRepository($this, new Filesystem, $this->getCachedServicesPathBootOnWorker());
        $providerRepository->loadForWorker($this->providersToBootOnWorker);

    }

    public function getCachedServicesPathBootOnWorker(): string
    {
        return $this->bootstrapPath() . '/cache/laravelfly_services_on_worker.json';
    }

    public function bootOnWorker()
    {

        if ($this->bootedOnWorker) {
            return;
        }

        $this->fireAppCallbacks(static::$corDict[WORKER_COROUTINE_ID]['bootingCallbacks']);

        array_walk(static::$corDict[WORKER_COROUTINE_ID]['serviceProviders'], function ($p) {
//            print_r(get_class($p));echo " -- \n";
            $this->bootProvider($p);
        });

        $this->bootedOnWorker = true;

        /**
         * moved to {@link bootInRequest()}
         */
        // $this->fireAppCallbacks($this->bootedCallbacks);
    }

    public function makeCFServices()
    {
        foreach ($this->CFServices as $service) {
            if ($this->bound($service)) $this->make($service);
            else {
                echo \LaravelFly\Fly::getServer()->colorize(
                    "[NOTE] $service not bound\n",
                    'NOTE');
            }
        }
    }

    public function instanceResolvedOnWorker($abstract): bool
    {
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        return isset(static::$corDict[WORKER_COROUTINE_ID]['instances'][$abstract]);

    }

    public function resetServiceProviders()
    {
        static::$corDict[\Co::getUid()]['serviceProviders'] = [];
    }

    public function bootInRequest()
    {
        $cid = \Co::getUid();
        if (static::$corDict[$cid]['bootedInRequest']) {
            return;
        }

        $this->registerConfiguredProvidersInRequest();

        /**
         * moved to {@link bootOnWorker()}
         */
        // $this->fireAppCallbacks($this->bootingCallbacks);

        array_walk($this->acrossServiceProviders, function ($p) {
            $this->bootProvider($p);
        });
        array_walk(static::$corDict[$cid]['serviceProviders'], function ($p) {
            $this->bootProvider($p);
        });

        static::$corDict[$cid]['bootedInRequest'] = static::$corDict[$cid]['booted'] = true;

        $this->fireAppCallbacks(static::$corDict[$cid]['bootedCallbacks']);
    }

    public function addDeferredServices(array $services)
    {
        $cid = \Co::getUid();
        static::$corDict[$cid]['deferredServices'] = array_merge(static::$corDict[$cid]['deferredServices'], $services);
    }
}