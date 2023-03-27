<?php

namespace Codeception\ProgressReporter;

use Codeception\Event\FailEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Extension;
use Codeception\Test\Descriptor;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Class ProgressReporter
 */
class ProgressReporter extends Extension
{
    /** @var string */
    public const REPORT_NAME = 'failedTests';

    private array $failedTests = [];
    /**
     * We are listening for events
     *
     * @var array
     */
    public static $events = [];

    /**
     * Progress bar
     *
     * @var ProgressBar
     */
    protected $progress;

    /**
     * Status (counter)
     *
     * @var Status
     */
    protected $status;

    /**
     * Setup
     */
    public function _initialize():void
    {
        $this->subscribeToEvents();
        $format = '';
        if (!$this->options['silent']) {
            $format = "\nCurrent suite: <options=bold>%suite%</>\n" .
                "Current test: <options=bold>%file%</>\n" .
                "<fg=green>Success: %success%</> <fg=yellow>Errors: %errors%</> <fg=red>Fails: %fails%</>\n" .
                "<fg=cyan>[%bar%]</>\n%current%/%max% %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%\n";
        }

        $this->_reconfigure(['settings' => ['silent' => true]]); // turn off printing for everything else
        ProgressBar::setFormatDefinition('custom', $format);
        $this->status = new Status();
    }

    /**
     * Subscribe to all events
     */
    private function subscribeToEvents()
    {
        self::$events = [
            Events::SUITE_BEFORE => 'beforeSuite',
            Events::TEST_BEFORE => 'beforeTest',
            Events::TEST_END => 'afterTest',
            Events::TEST_SUCCESS => 'success',
            Events::TEST_ERROR => 'afterError',
            Events::TEST_FAIL => 'afterFail',
            Events::RESULT_PRINT_AFTER => 'endRun',
        ];
    }

    /**
     * Setup progress bar
     *
     * @param SuiteEvent $event
     */
    public function beforeSuite(SuiteEvent $event)
    {
        $this->status = new Status();

        $count = $event->getSuite()->getTestCount();
        $this->progress = new ProgressBar($this->output, $count);
        $this->progress->setFormat('custom');
        $this->progress->setBarWidth($count);
        $this->progress->setRedrawFrequency($count / 100);

        $this->progress->setMessage('none', 'file');
        $this->progress->setMessage($event->getSuite()->getBaseName(), 'suite');
        $this->progress->setMessage($this->status->getSuccess(), 'success');
        $this->progress->setMessage($this->status->getFails(), 'fails');
        $this->progress->setMessage($this->status->getErrors(), 'errors');

        $this->progress->start();
    }

    /**
     * After test
     */
    public function afterTest()
    {
        $this->progress->advance();
        $this->progress->setMessage($this->status->getSuccess(), 'success');
        $this->progress->setMessage($this->status->getFails(), 'fails');
        $this->progress->setMessage($this->status->getErrors(), 'errors');
    }

    /**
     * Before test
     *
     * @param TestEvent $event
     */
    public function beforeTest(TestEvent $event)
    {
        $message = $event->getTest()->getMetadata()->getFilename();
        $this->progress->setMessage(pathinfo($message, PATHINFO_FILENAME), 'file');
    }

    /**
     * Success event
     */
    public function success()
    {
        $this->status->incSuccess();
    }

    public function afterFail(FailEvent $event): void
    {
        $this->status->incFails();
        $this->output->write('[-] ');
        $this->failedTests[] = Descriptor::getTestFullName($event->getTest());
    }

    public function afterError(FailEvent $event): void
    {
        $this->status->incErrors();
        $this->failedTests[] = Descriptor::getTestFullName($event->getTest());
    }

    /**
     * Event after all Tests - write failed tests to report file
     */
    public function endRun(): void
    {
        if (empty($this->failedTests)) {
            $this->output->writeln('empty failed tests');
            return;
        }
        $file = $this->getLogDir() . $this->getUniqReportFile();
        if (is_file($file)) {
            unlink($file); // remove old reportFile
        }

        file_put_contents($file, implode(PHP_EOL, array_unique($this->failedTests)));
    }

    private function getUniqReportFile(): string
    {
        return self::REPORT_NAME . '_' . uniqid('', true) . '.txt';
    }
}
