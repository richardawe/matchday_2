<?php
namespace App\Console\Commands;
use App\Models\{FootballMatch,NotificationPreference,PredictionSet};
use Illuminate\Console\Command;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendWeeklyMatchdayDigest extends Command {
    protected $signature='matchday:send-digest {--dry-run}'; protected $description='Send each opted-in supporter a weekly Matchday digest';
    public function handle(): int {
        $preferences=NotificationPreference::with('user')->where('weekly_digest',true)->where('digest_day',strtolower(now()->format('l')))->get();
        if($this->option('dry-run')){$this->info($preferences->count().' digest(s) ready.');return self::SUCCESS;}
        foreach($preferences as $preference){$preference->user?->notify(new class extends Notification {
            public function via($notifiable){return ['mail'];}
            public function toMail($notifiable){
                $fixtures=FootballMatch::whereBetween('match_date',[now(),now()->addDays(7)])->count();
                $calls=PredictionSet::where('status','active')->where('prediction_deadline','>',now())->count();
                return (new MailMessage)->subject('Your Matchday Africa week')->greeting('Your watchtower is ready, '.$notifiable->name)
                    ->line($fixtures.' fixtures are on the horizon.')->line($calls.' prediction challenges are open.')
                    ->action('Enter Matchday',route('home'))->line('Follow it. Predict it. Experience it.');
            }
        });}
        $this->info($preferences->count().' digest(s) sent.'); return self::SUCCESS;
    }
}
