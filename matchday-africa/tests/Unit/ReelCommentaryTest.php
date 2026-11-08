<?php

namespace Tests\Unit;

use App\Services\Griot;
use App\Services\ReelCommentary;
use PHPUnit\Framework\TestCase;

class ReelCommentaryTest extends TestCase
{
    private ReelCommentary $call;

    protected function setUp(): void
    {
        parent::setUp();
        $this->call = new ReelCommentary(['home' => 'Man United', 'away' => 'Aston Villa']);
    }

    public function test_goals_are_called_with_the_surname_club_minute_and_score(): void
    {
        $this->assertSame([
            'Fernandes puts Man United in front, 63 minutes! It\'s two one to Man United.',
            'Fernandes puts Man United in front! It\'s two one to Man United.',
            'Goal, Fernandes! It\'s two one to Man United.',
            'Goal! It\'s two one to Man United.',
        ], $this->call->beat(['type' => 'goal', 'side' => 'home', 'player' => 'Bruno Fernandes', 'minute' => 63,
            'home_score' => 2, 'away_score' => 1, 'angle' => 'lead']));

        $this->assertSame('Watkins levels it for Aston Villa, deep in stoppage time! Four all.',
            $this->call->beat(['type' => 'goal', 'side' => 'away', 'player' => 'Ollie Watkins', 'minute' => 90, 'extra' => 4,
                'home_score' => 4, 'away_score' => 4, 'angle' => 'equaliser'])[0]);
        $this->assertSame('Red card! Casemiro is sent off for Man United, 55 minutes. Down to ten!',
            $this->call->beat(['type' => 'red_card', 'side' => 'home', 'player' => 'Casemiro', 'minute' => 55, 'home_score' => 1, 'away_score' => 1])[0]);
        $this->assertSame('Half-time, and nil nil.', $this->call->beat(['type' => 'half_time', 'side' => null, 'home_score' => 0, 'away_score' => 0])[0]);
    }

    public function test_the_opening_and_the_final_whistle_frame_the_match(): void
    {
        $this->assertSame("Man United against Aston Villa. Here's how it went!", $this->call->title(4, 4)[0]);
        $this->assertSame("And there's the final whistle! Man United four, Aston Villa four. Honours even!", $this->call->final(4, 4)[0]);
        $this->assertSame('Full time! Aston Villa win it, two nil!', $this->call->final(0, 2)[1]);
        $this->assertSame('Full time, goalless!', $this->call->final(0, 0)[2]);
    }

    public function test_surnames_keep_their_particles(): void
    {
        $this->assertSame('Van Dijk', $this->call->surname('Virgil van Dijk'));
        $this->assertSame('De Bruyne', $this->call->surname('Kevin De Bruyne'));
        $this->assertSame('Casemiro', $this->call->surname('Casemiro'));
        $this->assertNull($this->call->surname(''));
    }

    public function test_captions_say_when_it_happened(): void
    {
        $this->assertSame(' in the 41st minute', Griot::at('en', 41));
        $this->assertSame(' in the 12th minute', Griot::at('en', 12));
        $this->assertSame(' in the 23rd minute', Griot::at('za', 23));
        $this->assertSame(' in stoppage time (90+3)', Griot::at('en', 90, 3));
        $this->assertSame(' for minute 41', Griot::at('pcm', 41));
        $this->assertSame('', Griot::at('en', null));
        $this->assertSame('GOAL! Saka scores for Arsenal. First goal.', Griot::joined(['GOAL! Saka scores for Arsenal', 'First goal.']));
    }
}
