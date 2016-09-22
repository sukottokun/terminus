<?php

namespace Pantheon\Terminus\UnitTests\Commands\Connection;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\Connection\InfoCommand;
use Pantheon\Terminus\Config;

use Prophecy\Prophet;
use Terminus\Models\Environment;
use Terminus\Models\Site;
use VCR\VCR;

/**
 * Test suite for class for Pantheon\Terminus\Commands\Connection\InfoCommand
 */
class InfoCommandTest extends ConnectionCommandTest
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

        $this->command = new InfoCommand($this->getConfig());
        $this->command->setLogger($this->logger);
        $this->prophet = new Prophet;
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

    }
    /**
     * Exercises connection:info command invalid site
     *
     * @return void
     */
    public function testSiteImportInvalidSite()
    {

    }

    /**
     * Exercises the environmentParams protected method
     *
     * @return void
     */
    public function testEnvironmentParams()
    {
        $site_prophet = $this->prophet->prophesize(Site::class);
        $site_prophet->get('name')->willReturn('my_site');
        $site = $site_prophet->reveal();

        $env_prophet = $this->prophet->prophesize(Environment::class);
        $env_prophet->connectionInfo()->willReturn([
            'param_a' => 'value_a',
            'param_b' => 'value_b',
        ]);
        $environment       = $env_prophet->reveal();
        $environment->id   = 'my_env';
        $environment->site = $site;

        // should return all parameters
        $this->assertEquals(
            [
                ['env' => 'my_site.my_env', 'param' => 'param_a', 'value' => 'value_a'],
                ['env' => 'my_site.my_env', 'param' => 'param_b', 'value' => 'value_b'],
            ],
            $this->protectedMethodCall($this->command, 'environmentParams', [$environment])
        );
    }

    /**
     * Exercises the environmentParams protected method with a filter argument
     *
     * @return void
     */
    public function testEnvironmentParamsFilter()
    {
        $site_prophet = $this->prophet->prophesize(Site::class);
        $site_prophet->get('name')->willReturn('my_site');
        $site = $site_prophet->reveal();

        $env_prophet = $this->prophet->prophesize(Environment::class);
        $env_prophet->connectionInfo()->willReturn([
            'param_a' => 'value_a',
            'param_b' => 'value_b',
        ]);
        $environment       = $env_prophet->reveal();
        $environment->id   = 'my_env';
        $environment->site = $site;

        // should return only filtered parameter
        $this->assertEquals(
            [
                ['env' => 'my_site.my_env', 'param' => 'param_b', 'value' => 'value_b'],
            ],
            $this->protectedMethodCall($this->command, 'environmentParams', [$environment, 'param_b'])
        );
    }
}
