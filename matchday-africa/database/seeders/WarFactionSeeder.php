<?php
namespace Database\Seeders;
use App\Models\Team;
use App\Models\WarFaction;
use Illuminate\Database\Seeder;

class WarFactionSeeder extends Seeder
{
    public function run():void
    {
        $rows=[
            ['Arsenal','Roman Legion','Disciplined · Methodical','arsenal.png','#e30613'],['Aston Villa','Anglo-Saxon Housecarls','Sturdy · Defensive','aston-villa.png','#95bfe5'],['Bournemouth','Barbary Corsairs','Fast · Opportunistic','bournemouth.png','#da291c'],['Brentford','Prussian Line Infantry','Efficient · Drilled','brentford.png','#e30613'],['Brighton','Byzantine Cataphracts','Technical · Elegant','brighton.png','#0057b8'],['Chelsea','Ottoman Janissaries','Elite · Disciplined','chelsea.png','#034694'],['Coventry City','Zulu Impi','Disciplined · Fearless','coventry.png','#69b3e7'],['Crystal Palace','Saxon Fyrd','Scrappy · Resilient','crystal-palace.png','#1b458f'],['Everton','Norman Knights','Old-guard · Stubborn','everton.png','#3a64b7'],['Fulham','Venetian Marines','Composed · Understated','fulham.png','#dddddd'],['Hull City','Numidian Cavalry','Swift · Unpredictable','hull.png','#f5a12d'],['Ipswich Town','Rus Varangian Guard','Loyal · Steadfast','ipswich.png','#3a64b7'],['Leeds United','White Rose Yorkist Army','Proud · Resurgent','leeds.png','#ffcd00'],['Liverpool','Norse Vikings','Ferocious · Relentless','liverpool.png','#c8102e'],['Manchester City','Mongol Horde','Overwhelming · Fast','manchester-city.png','#6cabdd'],['Manchester United','Napoleonic Grenadiers','Historic · Powerful','manchester-united.png','#da291c'],['Newcastle United','Norse-Northumbrian Raiders','Passionate · Raiding','newcastle.png','#dddddd'],['Nottingham Forest','Robin Hood’s Outlaws','Guerrilla · Cunning','nottingham-forest.png','#dd0000'],['Sunderland','Northern Rebel Clans','Underdog · Gritty','sunderland.png','#eb172b'],['Tottenham Hotspur','Spartan Hoplites','Proud · Historic · Brittle','tottenham.png','#dddddd']
        ];
        foreach($rows as [$club,$faction,$trait,$image,$colour]){
            $team=Team::where('name','like',"{$club}%")->orWhere('common_name',$club)->first();
            WarFaction::updateOrCreate(['club_name'=>$club],['team_id'=>$team?->id,'faction_name'=>$faction,'trait'=>$trait,'image'=>$image,'colour'=>$colour,'active'=>true]);
        }
    }
}
