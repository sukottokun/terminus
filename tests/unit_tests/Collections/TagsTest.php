<?php

namespace Terminus\UnitTests\Collections;

use Terminus\Collections\Tags;
use Terminus\Models\Organization;
use Terminus\Models\OrganizationSiteMembership;
use Terminus\Models\Site;
use Terminus\Request;

/**
 * Testing class for Terminus\Collections\Tags
 */
class TagsTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Tags
     */
    private $tags;

    /**
     * @inheritdoc
     */
    public function setUp()
    {
        parent::setUp();
        $this->tags = $this->getMockBuilder(Tags::class)
          ->disableOriginalConstructor()
          ->getMock();
        $this->tags->org_site_membership = $this->getMockBuilder(OrganizationSiteMembership::class)
          ->disableOriginalConstructor()
          ->getMock();
        $this->tags->org_site_membership->site = $this->getMockBuilder(Site::class)
          ->disableOriginalConstructor()
          ->getMock();
        $this->tags->org_site_membership->organization = $this->getMockBuilder(Organization::class)
          ->disableOriginalConstructor()
          ->getMock();
        $this->tags->request = $this->getMockBuilder(Request::class)
          ->disableOriginalConstructor()
          ->getMock();
    }

    /**
     * Tests Tags::create($tag)
     */
    public function testCreate()
    {
        $tag_id = 'tag_id';
        $this->tags->org_site_membership->site->id = 'site_uuid';
        $this->tags->org_site_membership->organization->id = 'org_uuid';
        $this->tags->request->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('organizations/org_uuid/tags'),
                $this->equalTo(
                    [
                        'form_params' => [$tag_id => ['sites' => ['site_uuid',],],],
                        'method' => 'put',
                    ]
                )
            );
        $this->tags->create($tag_id);
    }

    /**
     * Tests Tags::fetch($options)
     */
    public function testFetch()
    {
        $data = ['tag1',];
        $this->tags->expects($this->once())
          ->method('add')
          ->with((object)['id' => 'tag1',]);
        $this->tags->fetch(compact('data'));
    }
}
