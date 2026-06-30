<?php

namespace Tests\Unit;

use App\Models\DistributionChannel;
use App\Services\GeoFlow\BloggerPublisher;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\GeoFlow\FacebookPagePublisher;
use Tests\TestCase;

class SocialPublisherResolutionTest extends TestCase
{
    public function test_it_resolves_blogger_publisher(): void
    {
        $channel = new DistributionChannel(['channel_type' => 'blogger']);
        $manager = app(DistributionPublisherManager::class);

        $this->assertInstanceOf(BloggerPublisher::class, $manager->forChannel($channel));
    }

    public function test_it_resolves_facebook_page_publisher(): void
    {
        $channel = new DistributionChannel(['channel_type' => 'facebook_page']);
        $manager = app(DistributionPublisherManager::class);

        $this->assertInstanceOf(FacebookPagePublisher::class, $manager->forChannel($channel));
    }
}
