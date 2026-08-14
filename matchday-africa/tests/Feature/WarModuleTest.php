<?php

namespace Tests\Feature;

use Tests\TestCase;

class WarModuleTest extends TestCase
{
    public function test_war_experience_is_available(): void
    {
        $this->get('/war')
            ->assertOk()
            ->assertSee('War — Matchday Africa', false)
            ->assertSee('war-root', false);
    }
}
