<?php

namespace Tests\Feature;

use App\Http\Controllers\DiscoveryController;
use App\Models\Player;
use App\Models\Team;
use App\Services\PlayerService;
use App\Support\AfricanScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/** Tanzania (TAN), Gambia (GAM), Niger (NIG) and others were stored under codes the African list didn't have. */
class AfricanNationalityCodesTest extends TestCase
{
    use RefreshDatabase;

    private function code(string $nationality): string
    {
        $method = new ReflectionMethod(PlayerService::class, 'getNationalityCode');

        return $method->invoke(app(PlayerService::class), $nationality);
    }

    public function test_the_codes_the_player_sync_writes_count_as_african(): void
    {
        foreach (['Tanzania' => 'TAN', 'Gambia' => 'GAM', 'Niger' => 'NIG', 'Libya' => 'LBY', 'Sudan' => 'SDN', 'Malawi' => 'MWI',
            'Burundi' => 'BDI', 'Mauritania' => 'MTN', 'Eswatini' => 'SWZ', 'Ivory Coast' => 'CIV', 'DR Congo' => 'COD',
            'São Tomé and Príncipe' => 'STP', 'Nigeria' => 'NGA'] as $country => $code) {
            $this->assertSame($code, $this->code($country), $country);
            $this->assertContains($code, DiscoveryController::AFRICA, $country);
            $this->assertNotNull(AfricanScope::nation($code), $country);
        }
    }

    public function test_tanzanian_and_gambian_players_appear_on_the_african_players_page(): void
    {
        $team = Team::create(['name' => 'Example FC']);
        Player::create(['team_id' => $team->id, 'name' => 'Mbwana Samatta', 'nationality' => 'Tanzania', 'nationality_code' => 'TAN', 'is_active' => true]);
        Player::create(['team_id' => $team->id, 'name' => 'Example Gambian', 'nationality' => 'Gambia', 'nationality_code' => 'GAM', 'is_active' => true, 'shirt_number' => 9]);

        $this->get('/african-players')->assertOk()->assertSee('Mbwana Samatta')->assertSee('Example Gambian');
    }

    public function test_stored_three_letter_fallbacks_are_recoded(): void
    {
        $team = Team::create(['name' => 'Example FC']);
        $libya = Player::create(['team_id' => $team->id, 'name' => 'A Libyan', 'nationality' => 'Libya', 'nationality_code' => 'LIB', 'is_active' => true, 'shirt_number' => 1]);
        $malawi = Player::create(['team_id' => $team->id, 'name' => 'A Malawian', 'nationality' => 'Malawi', 'nationality_code' => 'MAL', 'is_active' => true, 'shirt_number' => 2]);
        $malta = Player::create(['team_id' => $team->id, 'name' => 'A Maltese', 'nationality' => 'Malta', 'nationality_code' => 'MAL', 'is_active' => true, 'shirt_number' => 3]);

        $this->assertSame(2, PlayerService::fixAfricanNationalityCodes());

        $this->assertSame('LBY', $libya->fresh()->nationality_code);
        $this->assertSame('MWI', $malawi->fresh()->nationality_code);
        $this->assertSame('MAL', $malta->fresh()->nationality_code);
        $this->assertTrue(AfricanScope::inScope($malawi->fresh()));
        $this->assertFalse(AfricanScope::inScope($malta->fresh()));
    }
}
