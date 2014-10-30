<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\CronArchive\Jobs;

use Piwik\CronArchive;
use Piwik\CronArchive\AlgorithmOptions;
use Piwik\CronArchive\BaseJob;
use Piwik\Option;
use Piwik\Piwik;

/**
 * TODO
 */
class ArchiveDayVisits extends BaseJob
{
    /**
     * TODO
     */
    public function __construct($idSite, $date, $token_auth, AlgorithmOptions $options)
    {
        parent::__construct($idSite, $date, 'day', $segment = false, $token_auth, $options);
    }

    /**
     * TODO
     */
    public function jobStarting()
    {
        list($idSite, $date, $period, $segment) = $this->parseJobUrl();

        /**
         * This event is triggered before the cron archiving process starts archiving data for a single
         * site.
         *
         * @param int $idSite The ID of the site we're archiving data for.
         */
        Piwik::postEvent('CronArchive.archiveSingleSite.start', array($idSite));
    }

    /**
     * TODO
    // TODO: instead of passing options to distributed callbacks, we should depend on DI container
     */
    public function jobFinished($response)
    {
        $context = $this->makeCronArchiveContext();

        list($idSite, $date, $period, $segment) = $this->parseJobUrl();
        list($visits, $visitsLast) = $this->parseVisitsApiResponse($context, $response, $idSite);

        if ($visits === null) {
            // TODO: move handleError to BaseJob?
            $context->handleError("Empty or invalid response '$response' for website id $idSite, skipping period and segment archiving.\n"
                . "(URL used: {$this->url})");
            $context->getAlgorithmStats()->skipped++;
            return;
        }

        $shouldArchivePeriods = $context->getAlgorithmState()->getShouldArchivePeriodsForWebsite($idSite);

        // If there is no visit today and we don't need to process this website, we can skip remaining archives
        if ($visits == 0
            && !$shouldArchivePeriods
        ) {
            $context->getAlgorithmLogger()->log("Skipped website id $idSite, no visit today");
            $context->getAlgorithmStats()->skipped++;
            return;
        }

        if ($visitsLast == 0
            && !$shouldArchivePeriods
            && $this->cronArchiveOptions->shouldArchiveAllSites
        ) {
            $context->getAlgorithmLogger()->log("Skipped website id $idSite, no visits in the last " . $date . " days");
            $context->getAlgorithmStats()->skipped++;
            return;
        }

        if (!$shouldArchivePeriods) {
            $context->getAlgorithmLogger()->log("Skipped website id $idSite periods processing, already done "
                . $context->getAlgorithmState()->getElapsedTimeSinceLastArchiving($idSite, $pretty = true)
                . " ago");
            $context->getAlgorithmStats()->skippedDayArchivesWebsites++;
            $context->getAlgorithmStats()->skipped++;
            return;
        }

        // mark 'day' period as successfully archived
        Option::set(CronArchive::lastRunKey($idSite, "day"), time());

        $context->getAlgorithmState()->getFailedRequestsSemaphore($idSite)->decrement();

        $context->getAlgorithmStats()->visitsToday += $visits;
        $context->getAlgorithmStats()->websitesWithVisitsSinceLastRun++;

        // TODO: used in two places, but should just be in one
        $context->queuePeriodAndSegmentArchivingFor($idSite); // TODO: all queuing must increase site's active request semaphore

        $this->archivingRequestFinished($context, $idSite, $period, $date, $segment, $visits, $visitsLast);
    }
}