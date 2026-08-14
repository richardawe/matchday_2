<?php
namespace App\Http\Controllers;
use App\Models\SponsorPlacement;
class SponsorController extends Controller { public function click(SponsorPlacement $sponsor){$sponsor->increment('clicks');return redirect()->away($sponsor->destination_url);} }
