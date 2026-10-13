<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Queue\Processor;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Db;
use Piwik\Exception\UnexpectedWebsiteFoundException;
use Piwik\Plugins\QueuedTracking\Configuration;
use Piwik\Tracker;
use Piwik\Tracker\RequestSet;
use Exception;

class Handler
{
    protected $transactionId;

    /**
     * MySQL/MariaDB errors after which InnoDB has already rolled back the entire transaction:
     * 1213 = deadlock, 1020 = record changed since last read (innodb_snapshot_isolation=ON,
     * the default since MariaDB 11.6.2). After these the connection is back in autocommit mode,
     * so any further statement would be committed immediately and could not be rolled back.
     */
    private const TRANSACTION_ABORTING_ERRORS = [1213, 1020];

    private $hasError = false;
    private $transactionAborted = false;
    private $requestSetsToRetry = array();
    private $count = 0;
    private $numTrackedRequestsBeginning = 0;

    public function init(Tracker $tracker)
    {
        $this->requestSetsToRetry = array();
        $this->hasError = false;
        $this->transactionAborted = false;
        $this->numTrackedRequestsBeginning = $tracker->getCountOfLoggedRequests();
        $this->transactionId = $this->getDb()->beginTransaction();
    }

    public function process(Tracker $tracker, RequestSet $requestSet)
    {
        $requestSet->restoreEnvironment();

        $this->count = 0;

        foreach ($requestSet->getRequests() as $request) {
            try {
                $startMs = round(microtime(true) * 1000);

                $tracker->trackRequest($request);

                $diffInMs = round(microtime(true) * 1000) - $startMs;
                if ($diffInMs > 2000) {
                    Common::printDebug(sprintf('The following request took more than 2 seconds (%d ms) to be tracked: %s', $diffInMs, var_export($request->getParams(), 1)));
                }

                $this->count++;
            } catch (UnexpectedWebsiteFoundException $ex) {
                // empty
            } catch (\Throwable $th) {
                // Log the error to help with debugging and visibility
                $message = "There was an error while trying to process a queued tracking request.";
                $message .= "\nError:\n" . $th->getMessage() . "\nStack trace:\n" . $th->getTraceAsString();
                $configuration = new Configuration();
                if ($configuration->shouldLogFailedTrackingRequestsBody()) {
                    // Since the config should only be enabled during debugging, we should be alright using plain text
                    $message .= "\nFailed request set:\n" . json_encode($requestSet->getState());
                }

                StaticContainer::get(\Piwik\Log\LoggerInterface::class)->warning($message);

                // Wrap any throwables so that they are caught by the try/catch in Processor, which is expecting Exceptions
                throw ($th instanceof \Exception ? $th : new \Exception($th->getMessage(), $th->getCode(), $th));
            }
        }

        $this->requestSetsToRetry[] = $requestSet;
    }

    public function onException(RequestSet $requestSet, Exception $e)
    {
        // todo: how do we want to handle DbException or RedisException?
        $this->hasError = true;

        Common::printDebug('Got exception: ' . $e->getMessage());

        if ($this->isTransactionAbortingError($e)) {
            // the whole batch was rolled back by the database, the processor has to stop and retry all of it
            $this->transactionAborted = true;
            Common::printDebug('Database rolled back the transaction, the whole batch needs to be retried');
            return;
        }

        // retry if a lock wait timeout happened (only the statement was rolled back, the transaction is still active)
        if (Db::get()->isErrNo($e, 1205)) {
            $this->requestSetsToRetry[] = $requestSet;
            Common::printDebug('Added requestSet with lock wait timeout to requestSetsToRetry');
            return;
        }

        if ($this->count > 0) {
            // remove the first one that failed and all following (standard bulk tracking behavior)
            $insertedRequests = array_slice($requestSet->getRequests(), 0, $this->count);
            $requestSet->setRequests($insertedRequests);
            $this->requestSetsToRetry[] = $requestSet;
        }
    }

    public function hasErrors()
    {
        return $this->hasError;
    }

    /**
     * Whether the database already rolled back the current transaction because of a deadlock or
     * snapshot isolation conflict. No further request sets may be processed in this batch.
     */
    public function isTransactionAborted(): bool
    {
        return $this->transactionAborted;
    }

    public function rollBack(Tracker $tracker)
    {
        $tracker->setCountOfLoggedRequests($this->numTrackedRequestsBeginning);

        try {
            $this->getDb()->rollBack($this->transactionId);
        } catch (Exception $e) {
            // the database may have rolled back the transaction already (eg deadlock), nothing left to roll back
            if (stripos($e->getMessage(), 'no active transaction') === false) {
                throw $e;
            }
            Common::printDebug('Ignoring rollback error as transaction is no longer active: ' . $e->getMessage());
        }
    }

    private function isTransactionAbortingError(Exception $e): bool
    {
        foreach (self::TRANSACTION_ABORTING_ERRORS as $errNo) {
            if (Db::get()->isErrNo($e, $errNo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return RequestSet[]
     */
    public function getRequestSetsToRetry()
    {
        return $this->requestSetsToRetry;
    }

    public function commit()
    {
        $this->getDb()->commit($this->transactionId);
        $this->requestSetsToRetry = array();
    }

    protected function getDb()
    {
        return Tracker::getDatabase();
    }
}
