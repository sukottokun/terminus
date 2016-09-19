<?php
namespace Pantheon\Terminus\UnitTests\Commands;

class importCommandTest extends CommandTestCase
{

    public function setUp()
    {
        parent::setUp();
        $this->setInput(['command' => 'import', 'name' => 'test']);
    }

    /**
     * @test
     */
    public function import_command_requests_workflow_when_a_site_exists()
    {
       $this->assertEquals('Hello World!', $this->runCommand()->fetchTrimmedOutput());
   }

    /**
     * @test
     */
    public function import_command_requests_workflow_when_a_site_exists()
    {
        $this->setInput(['command' => 'art', 'name' => 'foo']);
        $this->assertEquals('Not a valid work of art!', $this->runCommand()->fetchTrimmedOutput());
    }
}
