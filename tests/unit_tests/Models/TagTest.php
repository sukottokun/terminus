<?php

namespace Terminus\UnitTests\Collections;

use Terminus\Models\Organization;
use Terminus\Models\OrganizationSiteMembership;
use Terminus\Models\Site;
use Terminus\Models\Tag;
use Terminus\Request;

/**
 * Testing class for Terminus\Models\Tag
 */
class TagTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Tag
     */
    private $tag;

    /**
     * @inheritdoc
     */
    public function setUp()
    {
        parent::setUp();
        $this->tag = $this->getMockBuilder(Tag::class)
          ->disableOriginalConstructor()
          ->getMock();
        $this->tag->org_site_membership = $this->getMockBuilder(OrganizationSiteMembership::class)
          ->disableOriginalConstructor()
          ->getMock();
        $this->tag->org_site_membership->site = $this->getMockBuilder(Site::class)
          ->disableOriginalConstructor()
          ->getMock();
        $this->tag->org_site_membership->organization = $this->getMockBuilder(Organization::class)
          ->disableOriginalConstructor()
          ->getMock();
        $this->tag->request = $this->getMockBuilder(Request::class)
          ->disableOriginalConstructor()
          ->getMock();
    }

    /**
     * Tests Tag::delete()
     */
    public function testDelete()
    {
        $this->tag->id = 'tag_id';
        $this->tag->org_site_membership->site->id = 'site_uuid';
        $this->tag->org_site_membership->organization->id = 'org_uuid';
        $this->tag->request->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('organizations/org_uuid/tags/tag_id/sites?entity=site_uuid'),
                $this->equalTo(['method' => 'delete',])
            );
        $this->tag->delete();
    }
}
