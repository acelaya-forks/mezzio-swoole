<?php

/**
 * @see       https://github.com/mezzio/mezzio-swoole for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-swoole/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio\Swoole;

use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Http\Request as SwooleHttpRequest;
use Swoole\Http\Response as SwooleHttpResponse;
use Swoole\Http\Server as SwooleHttpServer;

/**
 * Starts a Swoole web server that handles incoming requests.
 *
 * Registers callbacks on each server event that marshal a typed event with the
 * arguments provided to the callback, and then dispatches the event using a
 * PSR-14 dispatcher.
 */
class SwooleRequestHandlerRunner extends RequestHandlerRunner
{
    /**
     * Default Process Name
     */
    public const DEFAULT_PROCESS_NAME = 'mezzio';

    private EventDispatcherInterface $dispatcher;

    private SwooleHttpServer $httpServer;

    public function __construct(
        SwooleHttpServer $httpServer,
        EventDispatcherInterface $dispatcher
    ) {
        // The HTTP server should not yet be running
        if ($httpServer->getMasterPid() > 0 || $httpServer->getManagerPid() > 0) {
            throw new Exception\InvalidArgumentException('The Swoole server has already been started');
        }
        $this->httpServer = $httpServer;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Run the application
     *
     * Determines which action was requested from the command line, and then
     * executes the task associated with it. If no action was provided, it
     * assumes "start".
     */
    public function run(): void
    {
        $this->httpServer->on('start', [$this, 'onStart']);
        $this->httpServer->on('shutdown', [$this, 'onShutdown']);
        $this->httpServer->on('managerstart', [$this, 'onManagerStart']);
        $this->httpServer->on('managerstop', [$this, 'onManagerStop']);
        $this->httpServer->on('workerstart', [$this, 'onWorkerStart']);
        $this->httpServer->on('workerstop', [$this, 'onWorkerStop']);
        $this->httpServer->on('workererror', [$this, 'onWorkerError']);
        $this->httpServer->on('request', [$this, 'onRequest']);
        $this->httpServer->on('beforereload', [$this, 'onBeforeReload']);
        $this->httpServer->on('afterreload', [$this, 'onAfterReload']);
        $this->httpServer->on('task', [$this, 'onTask']);
        $this->httpServer->on('finish', [$this, 'onTaskFinish']);
        $this->httpServer->start();
    }

    /**
     * Handle a start event for swoole HTTP server process.
     */
    public function onStart(SwooleHttpServer $server): void
    {
        $this->dispatcher->dispatch(new Event\ServerStartEvent($server));
    }

    /**
     * Handle a managerstart event for swoole HTTP server manager process
     */
    public function onManagerStart(SwooleHttpServer $server): void
    {
        $this->dispatcher->dispatch(new Event\ManagerStartEvent($server));
    }

    /**
     * Handle a managerstop event for swoole HTTP server manager process
     */
    public function onManagerStop(SwooleHttpServer $server): void
    {
        $this->dispatcher->dispatch(new Event\ManagerStopEvent($server));
    }

    /**
     * Handle a workerstart event for swoole HTTP server worker process
     */
    public function onWorkerStart(SwooleHttpServer $server, int $workerId): void
    {
        $this->dispatcher->dispatch(new Event\WorkerStartEvent($server, $workerId));
    }

    /**
     * Handle a workerstop event for swoole HTTP server worker process
     */
    public function onWorkerStop(SwooleHttpServer $server, int $workerId): void
    {
        $this->dispatcher->dispatch(new Event\WorkerStopEvent($server, $workerId));
    }

    /**
     * Handle a workererror event for swoole HTTP server worker process
     */
    public function onWorkerError(SwooleHttpServer $server, int $workerId, int $exitCode, int $signal): void
    {
        $this->dispatcher->dispatch(new Event\WorkerErrorEvent($server, $workerId, $exitCode, $signal));
    }

    /**
     * Handle an incoming HTTP request
     */
    public function onRequest(SwooleHttpRequest $request, SwooleHttpResponse $response): void
    {
        $this->dispatcher->dispatch(new Event\RequestEvent($request, $response));
    }

    /**
     * Handle a beforereload event (hot code reloading)
     */
    public function onBeforeReload(SwooleHttpServer $server): void
    {
        $this->dispatcher->dispatch(new Event\BeforeReloadEvent($server));
    }

    /**
     * Handle an afterreload event (hot code reloading)
     */
    public function onAfterReload(SwooleHttpServer $server): void
    {
        $this->dispatcher->dispatch(new Event\AfterReloadEvent($server));
    }

    /**
     * Handle a "task" event (process a task)
     *
     * @param mixed $data
     */
    public function onTask(SwooleHttpServer $server, int $taskId, int $workerId, $data): void
    {
        $event = $this->dispatcher->dispatch(new Event\TaskEvent($server, $taskId, $workerId, $data));
        $server->finish($server, $taskId, $event->getReturnValue());
    }

    /**
     * Handle a task "finish" event
     *
     * @param mixed $returnData Return value provided to "finish" event.
     */
    public function onTaskFinish(SwooleHttpServer $server, int $taskId, $returnData): void
    {
        $this->dispatcher->dispatch(new Event\TaskFinishEvent($server, $taskId, $returnData));
    }

    /**
     * Handle the shutting down of the server
     */
    public function onShutdown(SwooleHttpServer $server): void
    {
        $this->dispatcher->dispatch(new Event\ServerShutdownEvent($server));
    }
}
