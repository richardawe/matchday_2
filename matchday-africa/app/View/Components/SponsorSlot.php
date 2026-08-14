<?php
namespace App\View\Components;
use App\Models\SponsorPlacement;
use Illuminate\View\Component;
use Illuminate\Support\Facades\Schema;
class SponsorSlot extends Component { public $sponsor=null; public function __construct(public string $slot){if(Schema::hasTable('sponsor_placements')){$this->sponsor=SponsorPlacement::live($slot)->inRandomOrder()->first();if($this->sponsor)$this->sponsor->increment('impressions');}} public function render(){return view('components.sponsor-slot');} }
