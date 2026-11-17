<?php

namespace App\Services\Story;

/**
 * On-screen words and caption phrases for story videos, in English and French. Names of clubs,
 * players and competitions are never translated.
 */
class StoryText
{
    private const T = [
        'en' => [
            'full_time' => 'Full time', 'goal' => 'Goal', 'own_goal' => 'Own goal', 'penalty' => 'Penalty', 'red_card' => 'Red card',
            'assist' => 'Assist: :name', 'sent_off' => ':name is sent off', 'scores_for' => ':name scores for :club',
            'own_goal_for' => 'Own goal, :name. :club benefit', 'what_at_stake' => 'What was at stake',
            'table_going_in' => 'Going in: :home :hpos, :away :apos', 'table_now' => 'In the table: :home :hpos, :away :apos',
            'kickoff_at' => 'Kick-off :time', 'at_venue' => 'At :venue', 'stage' => ':stage',
            'key_stats' => 'Key stats', 'possession' => 'Possession', 'shots' => 'Shots', 'on_target' => 'On target', 'corners' => 'Corners',
            'fouls' => 'Fouls', 'yellow_cards' => 'Yellow cards',
            'head_to_head' => 'Head to head', 'last_meetings' => 'Last meeting on record|Last :n meetings on record', 'wins' => ':club wins', 'draws' => 'Draws',
            'form' => 'Form before this match', 'table' => 'League table', 'pts' => 'pts', 'played_short' => 'P',
            'next_fixture' => 'Next up', 'vs' => 'v', 'banter' => 'Banter', 'question' => 'Your call',
            'follow' => 'Follow for more', 'recap' => 'The story', 'scorers' => 'Scorers', 'no_goals' => 'No goals',
            'player_this_match' => 'This match', 'player_season' => 'This season in :league',
            'goals_n' => ':n goal|:n goals', 'assists_n' => ':n assist|:n assists', 'started' => 'Started', 'came_on' => 'Came on',
            'minutes' => ':n minutes', 'rating' => 'Rating :n', 'match_facts' => 'Match facts', 'referee' => 'Referee: :name',
            'attendance' => 'Attendance: :n', 'half_time' => 'Half time :score', 'final_score' => 'Final score',
            'draw' => 'Honours even', 'win_for' => ':club win it', 'photo' => 'Photo: :credit',
            'minute' => 'the :nth minute', 'stoppage' => 'stoppage time (:m)',
            // Hooks
            'hook_late_equaliser' => 'Late drama! :name levels it in :minute', 'hook_late_winner' => ':name wins it in :minute',
            'hook_hat_trick' => 'Hat-trick for :name', 'hook_brace' => 'Brace for :name', 'hook_upset' => 'Upset! :pos-placed :club beat :opp',
            'hook_red' => 'Down to ten: :name sent off', 'hook_scored' => ':name on the scoresheet', 'hook_comeback' => 'Comeback! :club turn it around',
            'hook_thrash' => ':club run riot: :score', 'hook_default' => ':home :score :away: the story',
            // Banter
            'banter_win' => ':winner fans, enjoy tonight. :loser fans, there is always next week.',
            'banter_win_big' => ':loser fans, maybe keep the phone off tonight.',
            'banter_draw' => 'Honours even. Nobody is buying the first round tonight.',
            'banter_goalless' => 'Ninety minutes, zero goals. The nets stayed dry.',
            // Questions
            'q_win' => 'Did :club deserve the win?', 'q_draw' => 'Fair result, or should one side have won it?',
            'q_player' => 'Rate :name\'s game out of 10', 'cta_comment' => 'Tell us in the comments',
            // Captions
            'cap_full_time' => 'Full time: :home :score :away (:competition).', 'cap_watch' => 'The whole story, minute by minute:',
            'cap_scorers' => 'Goals: :list.', 'cap_red' => 'Sent off: :list.', 'cap_photo' => 'Photo: :credit',
            'cap_music' => 'Music: :title (Matchday Africa original)', 'cap_question' => ':question',
            // Radio
            'radio' => 'Matchday Radio', 'radio_listen' => 'Listen to the full edition',
        ],
        'fr' => [
            'full_time' => 'Fin du match', 'goal' => 'But', 'own_goal' => 'CSC', 'penalty' => 'Penalty', 'red_card' => 'Carton rouge',
            'assist' => 'Passe décisive : :name', 'sent_off' => ':name est expulsé', 'scores_for' => ':name marque pour :club',
            'own_goal_for' => 'But contre son camp, :name. Avantage :club', 'what_at_stake' => 'L\'enjeu',
            'table_going_in' => 'Avant le match : :home :hpos, :away :apos', 'table_now' => 'Au classement : :home :hpos, :away :apos',
            'kickoff_at' => 'Coup d\'envoi :time', 'at_venue' => 'À :venue', 'stage' => ':stage',
            'key_stats' => 'Les stats', 'possession' => 'Possession', 'shots' => 'Tirs', 'on_target' => 'Cadrés', 'corners' => 'Corners',
            'fouls' => 'Fautes', 'yellow_cards' => 'Cartons jaunes',
            'head_to_head' => 'Face-à-face', 'last_meetings' => 'Le dernier duel connu|Les :n derniers duels connus', 'wins' => 'Victoires :club', 'draws' => 'Nuls',
            'form' => 'Forme avant le match', 'table' => 'Classement', 'pts' => 'pts', 'played_short' => 'J',
            'next_fixture' => 'Prochain match', 'vs' => 'c.', 'banter' => 'Chambrage', 'question' => 'À vous',
            'follow' => 'Abonnez-vous pour la suite', 'recap' => 'Le récit', 'scorers' => 'Buteurs', 'no_goals' => 'Aucun but',
            'player_this_match' => 'Ce match', 'player_season' => 'Cette saison en :league',
            'goals_n' => ':n but|:n buts', 'assists_n' => ':n passe décisive|:n passes décisives', 'started' => 'Titulaire', 'came_on' => 'Entré en jeu',
            'minutes' => ':n minutes', 'rating' => 'Note :n', 'match_facts' => 'Le match', 'referee' => 'Arbitre : :name',
            'attendance' => 'Affluence : :n', 'half_time' => 'Mi-temps :score', 'final_score' => 'Score final',
            'draw' => 'Match nul', 'win_for' => 'Victoire de :club', 'photo' => 'Photo : :credit',
            'minute' => 'à la :nth minute', 'stoppage' => 'dans le temps additionnel (:m)',
            'hook_late_equaliser' => 'Fin de match folle ! :name égalise :minute', 'hook_late_winner' => ':name offre la victoire :minute',
            'hook_hat_trick' => 'Triplé pour :name', 'hook_brace' => 'Doublé pour :name', 'hook_upset' => 'Surprise ! :club (:pos) bat :opp',
            'hook_red' => 'À dix : :name expulsé', 'hook_scored' => ':name marque', 'hook_comeback' => 'Remontée ! :club renverse le match',
            'hook_thrash' => ':club déroule : :score', 'hook_default' => ':home :score :away : le récit',
            'banter_win' => 'Supporters de :winner, profitez de la soirée. Ceux de :loser, il y a toujours la semaine prochaine.',
            'banter_win_big' => 'Supporters de :loser, mieux vaut éteindre le téléphone ce soir.',
            'banter_draw' => 'Match nul. Personne ne paie la première tournée ce soir.',
            'banter_goalless' => 'Quatre-vingt-dix minutes, zéro but. Les filets sont restés secs.',
            'q_win' => ':club méritait-il la victoire ?', 'q_draw' => 'Résultat juste, ou l\'un des deux méritait-il mieux ?',
            'q_player' => 'Notez le match de :name sur 10', 'cta_comment' => 'Dites-le en commentaire',
            'cap_full_time' => 'Fin du match : :home :score :away (:competition).', 'cap_watch' => 'Tout le récit, minute par minute :',
            'cap_scorers' => 'Buts : :list.', 'cap_red' => 'Expulsés : :list.', 'cap_photo' => 'Photo : :credit',
            'cap_music' => 'Musique : :title (création Matchday Africa)', 'cap_question' => ':question',
            'radio' => 'Matchday Radio', 'radio_listen' => 'Écoutez l\'édition complète',
        ],
    ];

    public function __construct(public readonly string $lang = 'en') {}

    public static function for(?string $lang): self
    {
        return new self(in_array($lang, array_keys(self::T), true) ? $lang : 'en');
    }

    public function t(string $key, array $replace = [], ?int $count = null): string
    {
        $line = self::T[$this->lang][$key] ?? self::T['en'][$key] ?? $key;
        if ($count !== null && str_contains($line, '|')) {
            [$one, $many] = explode('|', $line, 2);
            $line = $count === 1 ? $one : $many;
            $replace += ['n' => $count];
        }
        // Longest keys first, so :name never eats :names.
        uksort($replace, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($replace as $k => $v) {
            $line = str_replace(':'.$k, (string) $v, $line);
        }

        return $line;
    }

    /** "41st minute" / "41e minute"; "stoppage time (90+3)" / "temps additionnel (90+3)". */
    public function minute(?int $minute, ?int $extra = null): string
    {
        if ($minute === null) {
            return '';
        }
        if ($extra) {
            return $this->t('stoppage', ['m' => "{$minute}+{$extra}"]);
        }

        return $this->t('minute', ['nth' => $this->ordinal($minute)]);
    }

    public function ordinal(int $n): string
    {
        if ($this->lang === 'fr') {
            return $n === 1 ? '1re' : "{$n}e";
        }
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    /** Thousands separator for the language: 52,341 / 52 341. */
    public function number(int $n): string
    {
        return number_format($n, 0, '.', $this->lang === 'fr' ? ' ' : ',');
    }
}
