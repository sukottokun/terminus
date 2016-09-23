<?php

namespace Pantheon\Terminus\UnitTests\Commands\Connection;
use Pantheon\Terminus\Commands\Site\ImportCommand;
use Pantheon\Terminus\Config;

use Prophecy\Prophet;
use Terminus\Models\Environment;
use Terminus\Models\Site;
use VCR\VCR;

/**
 * Test suite for class for Pantheon\Terminus\Commands\Connection\ImportCommand
 */
class ImportCommandTest extends ConnectionCommandTest
{
    private $prophet;
    /**
     * Test suite setup
     *
     * @return void
     */
    protected function setup()
    {
        parent::setUp();
        $this->setInput(['command' => 'site:import', 'site' => '', 'url' => '']);
    }

    protected function tearDown()
    {
        parent::tearDown();
        $this->prophet->checkPredictions();
    }


    /**
     * Exercises site:import command with a valid site
     *
     * @return void
     *
     * @vcr site_connection-info
     */
    public function testSiteImport()
    {
     $this->assertEquals('Hello World!', $this->runCommand()->fetchTrimmedOutput());
    }
    /**
     * Exercises connection:info command invalid site
     *
     * @return void
     */
    public function testSiteImportInvalidSite()
    {

    }
}
