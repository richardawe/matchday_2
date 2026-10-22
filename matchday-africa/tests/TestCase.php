<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    private float $startedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = microtime(true) - 1;
    }

    protected function tearDown(): void
    {
        // Reels and radio audio are published as static copies in public/generated; remove only
        // the files this test wrote, never media that was already there.
        $generated = public_path('generated');
        foreach (is_dir($generated) ? File::allFiles($generated) : [] as $file) {
            if ($file->getMTime() >= $this->startedAt) {
                File::delete($file->getPathname());
            }
        }
        parent::tearDown();
    }
}
