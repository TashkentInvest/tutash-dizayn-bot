<?php

namespace Tests\Feature;

use App\Services\BotStats;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StatsCacheTest extends TestCase
{
    /**
     * The test environment caches in memory (no serialization), production caches in the database (serialized).
     * A cached Collection used to come back as __PHP_Incomplete_Class on the second request, which broke the dashboard.
     */
    public function test_stats_work_on_a_cache_hit_with_a_serializing_store(): void
    {
        $dir = storage_path('framework/testing/stats-cache-'.uniqid());
        config(['cache.default' => 'file', 'cache.stores.file.path' => $dir]);
        app('cache')->forgetDriver('file');

        $first = BotStats::downloadsPerDay(7);
        $second = BotStats::downloadsPerDay(7);   // served from the file cache

        $this->assertInstanceOf(Collection::class, $second);
        $this->assertSame($first->all(), $second->all());
        $this->assertCount(7, $second);

        $totals = BotStats::totals();
        $this->assertSame($totals, BotStats::totals(), 'totals are a plain array and survive the cache');

        Cache::flush();
        File::deleteDirectory($dir);
    }
}
