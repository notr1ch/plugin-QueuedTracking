<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\tests\Framework\Mock;

use Piwik\Tracker\Request;

class Tracker extends \Piwik\Tests\Framework\Mock\Tracker
{
    /**
     * Value of the marker param of every request that was tracked, in tracking order.
     * @var array
     */
    public $trackedMarkers = array();

    private $thrownOnce = array();

    public function trackRequest(Request $request)
    {
        $allParams = $request->getRawParams();
        if (!empty($allParams['forceThrow'])) {
            // forceThrowOnce only throws the first time the request is tracked, eg to simulate a deadlock
            $onceKey = $allParams['forceThrowOnce'] ?? null;
            if ($onceKey === null || empty($this->thrownOnce[$onceKey])) {
                if ($onceKey !== null) {
                    $this->thrownOnce[$onceKey] = true;
                }
                throw new ForcedException($allParams['forceThrowMessage'] ?? "forced exception");
            }
        }

        if (isset($allParams['marker'])) {
            $this->trackedMarkers[] = $allParams['marker'];
        }

        return parent::trackRequest($request);
    }
}
