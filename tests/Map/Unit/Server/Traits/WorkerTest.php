<?php

namespace LaravelFly\Tests\Map\Unit\Server\Traits;

use Illuminate\Support\Facades\Artisan;
use LaravelFly\Tests\Map\MapTestCase;
use Symfony\Component\EventDispatcher\GenericEvent;


class WorkerTest extends MapTestCase

{


    function testDownFile()
    {

        $appRoot = static::$laravelAppRoot;

        $constances=[
        ];

        $options = [
            // use two process for two workers, worker 0 used for watchDownFile, worker 1 used for phpunit
            'worker_num' => 2,
            'mode' => 'Simple',
            'listen_port' => 9503,
            'daemonize' => false,
            'log_file' => $appRoot . '/storage/logs/swoole.log',
            'pre_include' => false,
            'watch_down' => true,
        ];

        $mychan = static::$chan = new \Swoole\Channel(1024 * 256);

        $r = self::createFlyServerInProcess($constances,$options, function ($server) use ($appRoot, $options,  $mychan) {


            $dispatcher = $server->getDispatcher();

            // use assert in server, these tests can not reported by phpunit , but if assert failed, error output in console
            $dispatcher->addListener('worker.ready', function (GenericEvent $event) use ($server, $appRoot, $mychan) {

                $app = $event['app'];

                @unlink($server->getDownFileDir() . '/down');

//                self::assertEquals(0, $app->isDownForMaintenance());
                $r[] = $app->isDownForMaintenance();

                /**
                 * worker 0 used for watchDownFile, worker 1 used for phpunit
                 *
                 * if only one worker,closure added by swoole_event_add in watchDownFile() only run
                 * after the closure here finish
                 * $server->getMemory('isDown') will not change.
                 *
                 */
                if ($event['workerid'] === 0) return;

//                self::assertEquals(0, $server->getMemory('isDown'));
                $r[] = $server->getMemory('isDown');

                passthru("cd $appRoot && php artisan down");
                sleep(1);
//                self::assertEquals(1, $app->isDownForMaintenance());
                $r[] = $app->isDownForMaintenance();

//                file_put_contents($server->path('storage/framework/ok3'), $server->getMemory('isDown'));
//                self::assertEquals(1, $server->getMemory('isDown'));
                $r[] = $server->getMemory('isDown');


                passthru("cd $appRoot && php artisan up");
                sleep(2);
//                self::assertEquals(0, $app->isDownForMaintenance());
                $r[] = $app->isDownForMaintenance();

                sleep(1);
//                self::assertEquals(0, $server->getMemory('isDown'));
                $r[] = $server->getMemory('isDown');


                $mychan->push(json_encode($r));


            });

            $server->start();

        },  10);

        $rr = json_decode($mychan->pop());
//        var_dump("RRR:"); var_dump($rr);
        self::assertEquals([0, 0, 1, 1, 0, 0], $rr);
    }
}
