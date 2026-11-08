<?php

namespace Tests\Feature;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReelTimelineTest extends TestCase
{
    public function test_the_v2_reel_is_always_twenty_seconds(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if (!$node) {
            $this->markTestSkipped('Node is not installed');
        }
        $process = new Process([$node, base_path('reel/test-timeline.mjs')], base_path('reel'));
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }
}
