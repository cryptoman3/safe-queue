<?php

namespace Digbang\SafeQueue;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker as IlluminateWorker;
use Illuminate\Queue\WorkerOptions;
use Symfony\Component\Debug\Exception\FatalThrowableError;
use Throwable;

class Worker extends IlluminateWorker
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * Worker constructor.
     *
     * @param QueueManager              $manager
     * @param Dispatcher                $events
     * @param EntityManagerInterface    $entityManager
     * @param ExceptionHandler          $exceptions
     * @param  \callable $isDownForMaintenance
     */
    public function __construct(
        QueueManager $manager,
        Dispatcher $events,
        EntityManagerInterface $entityManager,
        ExceptionHandler $exceptions,
        callable $isDownForMaintenance
    ) {
        parent::__construct($manager, $events, $exceptions, $isDownForMaintenance);

        $this->entityManager = $entityManager;
    }

    /**
     * Wrap parent::getNextJob to make sure we have a good EM before processing the next job.
     * This allow us to avoid incrementing the attempts on the job if the worker fails because of the EM.
     *
     * Get the next job from the queue connection.
     *
     * @param  \Illuminate\Contracts\Queue\Queue  $connection
     * @param  string  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    protected function getNextJob($connection, $queue)
    {
        $exception = null;

        try {
            $this->assertEntityManagerOpen();
            $this->assertEntityManagerClear();
            $this->assertGoodDatabaseConnection();
        } catch (EntityManagerClosedException $e) {
            $exception = $e;
        } catch (Exception $e) {
            $exception = new QueueSetupException("Error in queue setup while getting next job", 0, $e);
        } catch (Throwable $e) {
            $exception = new QueueSetupException("Error in queue setup while getting next job", 0, new FatalThrowableError($e));
        } 
        
        if ($exception) {
            $this->shouldQuit = true;
            $this->exceptions->report($exception);

            return null;
        }

        return parent::getNextJob($connection, $queue);
    }

    /**
     * @throws EntityManagerClosedException
     */
    private function assertEntityManagerOpen()
    {
        if ($this->entityManager->isOpen()) {
            return;
        }

        throw new EntityManagerClosedException;
    }

    /**
     * To clear the em before doing any work.
     */
    private function assertEntityManagerClear()
    {
        $this->entityManager->clear();
    }

    /**
     * Some database systems close the connection after a period of time, in MySQL this is system variable
     * `wait_timeout`. Given the daemon is meant to run indefinitely we need to make sure we have an open
     * connection before working any job. Otherwise we would see `MySQL has gone away` type errors.
     */
    private function assertGoodDatabaseConnection()
    {
        $connection = $this->entityManager->getConnection();

        if ($connection->ping() === false) {
            $connection->close();
            $connection->connect();
        }
    }
}
