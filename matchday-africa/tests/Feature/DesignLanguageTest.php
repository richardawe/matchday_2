<?php

namespace Tests\Feature;

use App\Design\Canvas;
use App\Design\Components;
use App\Design\CopyRules;
use App\Design\DesignCheck;
use App\Design\DesignViolation;
use App\Design\Film;
use App\Design\Motion;
use App\Design\Tokens;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The design language (docs/design-language.md): the tokens match the brief, every renderer reads
 * them instead of hard-coding, every component lays out inside the safe zone and fills it, and the
 * rules the engine enforces (copy, contrast, images, credits, repeats) hold.
 */
class DesignLanguageTest extends TestCase
{
    /** Every file that draws video or social output. */
    private const RENDERERS = [
        'app/Design/Components.php', 'app/Design/Scene.php', 'app/Design/Film.php', 'app/Design/Canvas.php',
        'app/Services/Story/StoryFrames.php', 'app/Services/Story/StoryPlanner.php',
        'app/Services/RadioVideo.php',
        'app/Services/Slideshow/SlideshowRenderer.php', 'app/Services/Slideshow/SlideshowVideo.php', 'app/Services/Slideshow/SlideshowDeck.php',
        'app/Services/StoryCardRenderer.php', 'app/Services/FixtureCardRenderer.php', 'app/Services/BanterCardRenderer.php',
        'app/Services/Griot/ChallengeCardRenderer.php',
        'reel/src/Reel.jsx', 'reel/src/ReelV2.jsx',
    ];

    public function test_the_tokens_are_the_brief(): void
    {
        $this->assertSame(['black' => '#0A0A0A', 'yellow' => '#FFD23F', 'white' => '#FFFFFF', 'grey' => '#C8C8C8'],
            array_intersect_key(Tokens::colours(), array_flip(['black', 'yellow', 'white', 'grey'])));
        $this->assertSame(['black'], Tokens::get('contrast.yellow'));
        $this->assertContains('yellow', Tokens::get('contrast.black'));
        $this->assertNotContains('yellow', Tokens::get('contrast.white'));
        foreach (['hook' => [120, 96], 'headline' => [72, 56], 'score' => [160, 120], 'body' => [40, 32], 'label' => [28, 24], 'credit' => [20, 18]] as $style => [$size, $min]) {
            $this->assertSame([$size, $min], [Tokens::type($style)['size'], Tokens::type($style)['min']], $style);
        }
        $this->assertSame('display', Tokens::type('hook')['font']);
        $this->assertSame('display', Tokens::type('score')['font']);
        $this->assertSame(['semibold', 'bold', 'regular'], [Tokens::type('body')['font'], Tokens::type('label')['font'], Tokens::type('credit')['font']]);
        foreach (array_keys((array) Tokens::get('font')) as $font) {
            $this->assertFileExists(Tokens::font($font));
        }
        $this->assertStringContainsString('Anton', Tokens::font('display'));
        $this->assertStringContainsString('Inter', Tokens::font('body' === 'x' ? 'x' : 'semibold'));
        [$w, $h] = Tokens::canvas();
        $safe = Tokens::safe();
        $this->assertSame([1080, 1920], [$w, $h]);
        $this->assertSame([150, $h - 380, $w - 140], [$safe['top'], $safe['bottom'], $safe['right']]);
        $this->assertFileExists(Tokens::musicFile());
        $this->assertSame('matchday_original_beat_v1.mp3', basename(Tokens::musicFile()));
        $this->assertEqualsWithDelta(0.35, (float) Tokens::motion('transition'), 0.051);
        $this->assertSame([3.0, 5.0], [(float) Tokens::get('motion.cut.min'), (float) Tokens::get('motion.cut.max')]);
        foreach (Components::ALL as $component) {
            $this->assertNotNull(Tokens::get("components.{$component}", null), "{$component} has its motion documented");
        }
        // Config reads the tokens rather than repeating them.
        $this->assertSame($safe, config('story.safe'));
        $this->assertSame($safe, config('slideshow.safe'));
        $this->assertSame(Tokens::musicFile(), \App\Services\Story\StoryVideos::musicFile());
        $this->assertSame(Tokens::musicFile(), \App\Services\Slideshow\Slideshows::musicFile());
    }

    public function test_no_renderer_hard_codes_colours_or_fonts_and_every_one_reads_the_tokens(): void
    {
        $patterns = [
            'a hex colour' => '/[\'"`]#[0-9A-Fa-f]{3,8}[\'"`]|(?<![&\w])#[0-9A-Fa-f]{6}\b(?![\'"])/',
            'an RGB triplet' => '/\[\s*\d{2,3}\s*,\s*\d{2,3}\s*,\s*\d{2,3}\s*\]/',
            'a literal GD colour' => '/imagecolorallocate(alpha)?\(\$\w+,\s*\d/',
            'a CSS rgb() literal' => '/rgba?\(\s*\d/',
            'a font file' => '/[\w-]+\.(ttf|otf|woff2?)\b/',
        ];
        $root = base_path();
        foreach (self::RENDERERS as $file) {
            $code = (string) file_get_contents("{$root}/{$file}");
            foreach (explode("\n", $code) as $n => $line) {
                if (str_contains($line, '% 100') || preg_match('/^\s*(\*|\/\/)/', $line)) {
                    continue; // ordinal suffixes; comments
                }
                foreach ($patterns as $what => $pattern) {
                    $this->assertDoesNotMatchRegularExpression($pattern, $line, "{$file}:".($n + 1)." hard-codes {$what}");
                }
            }
            $this->assertMatchesRegularExpression('/namespace App\\\\Design;|App\\\\Design\\\\|from \'\.\/design\.js\'/', $code, "{$file} does not import the design module");
        }
    }

    public function test_every_component_renders_inside_the_safe_zone_and_fills_it(): void
    {
        $samples = json_decode((string) file_get_contents(resource_path('design/samples.json')), true);
        $film = app(Film::class);
        $check = app(DesignCheck::class);
        $seen = [];
        foreach ($samples['cards'] as $card) {
            $card = $this->resolve($card, $samples) + ['start' => 0, 'seconds' => 4];
            $img = $film->stillImage($card);
            $manifest = $film->manifest([$card])[0];
            $this->assertSame([], $check->manifestProblems([$manifest]), $card['component']);
            $this->assertLessThanOrEqual((float) Tokens::get('grid.max_empty'), $check->emptiness($img, $manifest), "{$card['component']} is too empty");
            $this->assertSame([], $check->offPalette($img, $manifest), "{$card['component']} uses colours outside the tokens");
            imagedestroy($img);
            $seen[] = $card['component'];
        }
        $this->assertEqualsCanonicalizing(Components::ALL, $seen, 'samples.json has a reference card for every component');
    }

    public function test_the_engine_enforces_the_rules(): void
    {
        $kit = app(Components::class);
        // Banned copy.
        foreach (['Arsenal quietly win it' => 'quietly', "It's not luck, it's class" => 'not-x-but-y', 'Most people missed this' => 'most-people',
            'Goal → watch now' => 'arrows', '🚨 Breaking' => 'siren', 'WHAT A GOAL THAT WAS' => 'all-caps'] as $text => $rule) {
            $this->assertContains($rule, CopyRules::violations($text), $text);
        }
        $this->assertSame([], CopyRules::violations('Photo: Jane Doe / Wikimedia Commons / CC BY-SA 4.0'));
        $this->assertSame([], CopyRules::violations('Did Morocco deserve the win?'));
        $this->assertSame('Goal watch now', CopyRules::clean('Goal → watch now 🚨'));
        $this->assertThrows(fn () => $kit->make('closing', ['title' => 'Your call', 'question' => 'Most people think Morocco were lucky', 'cta' => 'x', 'follow' => 'Follow', 'url' => 'matchday.africa']),
            DesignViolation::class);
        // Contrast: yellow only on black, black on yellow; team colours take black or white by contrast.
        $this->assertThrows(fn () => Canvas::assertPair('yellow', 'white'), DesignViolation::class);
        Canvas::assertPair('black', 'yellow');
        $this->assertSame('black', Canvas::ink('#FFD23F'));
        $this->assertSame('white', Canvas::ink('#C1272D'));
        // Images: never the crested statues; other photos need a licence credit.
        $this->assertThrows(fn () => Canvas::assertImage(base_path('public/war/warriors/arsenal.png'), 'someone'), DesignViolation::class);
        $photo = storage_path('app/testing-design/photo.jpg');
        File::ensureDirectoryExists(dirname($photo));
        $img = imagecreatetruecolor(800, 800);
        imagejpeg($img, $photo);
        $this->assertThrows(fn () => $kit->make('profile', ['name' => 'A Player', 'photo' => ['file' => $photo, 'credit' => null]]), DesignViolation::class);
        $scene = $kit->make('profile', ['name' => 'A Player', 'photo' => ['file' => $photo, 'credit' => 'Jane Doe / Wikimedia Commons / CC BY-SA 4.0']]);
        $this->assertSame('Photo: Jane Doe / Wikimedia Commons / CC BY-SA 4.0', $scene->credit);
        File::deleteDirectory(dirname($photo));
        // No card twice in one video.
        $this->assertThrows(fn () => app(Film::class)->assertNoRepeats([['component' => 'banter', 'line' => 'x'], ['component' => 'banter', 'line' => 'x']]), DesignViolation::class);
        // One easing curve, ending where it should.
        $this->assertSame([0.0, 1.0], [Motion::ease(0), Motion::ease(1)]);
        $this->assertGreaterThan(0.5, Motion::ease(0.5));
    }

    public function test_the_design_check_draws_a_contact_sheet_and_flags_problems(): void
    {
        if (!$this->hasFfmpeg()) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }
        $samples = json_decode((string) file_get_contents(resource_path('design/samples.json')), true);
        $cuts = [];
        $at = 0.0;
        foreach (['matchup', 'goal', 'closing'] as $name) {
            $card = collect($samples['cards'])->firstWhere('component', $name);
            $cuts[] = $this->resolve($card, $samples) + ['start' => $at, 'seconds' => 3.5];
            $at += 3.5;
        }
        $dir = storage_path('app/testing-design');
        File::ensureDirectoryExists($dir);
        $video = "{$dir}/clip.mp4";
        $fps = 6;
        $film = app(Film::class);
        $p = new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'image2pipe', '-framerate', (string) $fps, '-c:v', 'mjpeg', '-i', '-',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-r', (string) $fps, $video]);
        $p->setInput(implode('', iterator_to_array($film->frames($cuts, $fps), false)));
        $p->setTimeout(120)->mustRun();
        file_put_contents("{$dir}/design.json", json_encode($film->manifest($cuts)));

        $this->artisan('design:check', ['video' => $video, '--manifest' => "{$dir}/design.json", '--sheet' => "{$dir}/sheet.png", '--every' => 3])
            ->expectsOutputToContain('PASS')->assertSuccessful();
        $this->assertSame([4 * (270 + 24) + 24, 90 + 480 + 92 + 48], array_slice(getimagesize("{$dir}/sheet.png"), 0, 2)); // frames at 0, 3, 6 and 9 s

        // A manifest that says a text box sits under the app's buttons, in an off-token font, and a repeated card.
        $bad = $film->manifest($cuts);
        $bad[0]['text'][0]['box'] = [900, 1600, 1060, 1700];
        $bad[0]['text'][0]['font'] = 'Comic.ttf';
        $bad[2]['key'] = $bad[1]['key'];
        $problems = app(DesignCheck::class)->manifestProblems($bad);
        $this->assertNotEmpty(array_filter($problems, fn ($p) => str_contains($p, 'unsafe zone')));
        $this->assertNotEmpty(array_filter($problems, fn ($p) => str_contains($p, 'not a font token')));
        $this->assertNotEmpty(array_filter($problems, fn ($p) => str_contains($p, 'repeats cut')));

        // An empty frame is caught from the pixels alone.
        $blank = imagecreatetruecolor(1080, 1920);
        imagefill($blank, 0, 0, Canvas::col($blank, 'black'));
        $this->assertEqualsWithDelta(1.0, app(DesignCheck::class)->emptiness($blank), 0.001);
        File::deleteDirectory($dir);
    }

    private function hasFfmpeg(): bool
    {
        try {
            (new Process(['ffmpeg', '-version']))->mustRun();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolve(array $card, array $samples): array
    {
        if (isset($card['sides']) && is_string($card['sides'])) {
            $card['sides'] = $samples[$card['sides']];
        }
        array_walk_recursive($card, function (&$v, $k) {
            if (is_string($v) && in_array($k, ['flag', 'art', 'file'], true) && !str_starts_with($v, '/')) {
                $v = base_path($v);
            }
        });

        return $card;
    }
}
