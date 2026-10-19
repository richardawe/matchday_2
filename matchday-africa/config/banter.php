<?php

/*
|--------------------------------------------------------------------------
| Reel banter: the written line bank
|--------------------------------------------------------------------------
|
| Speech bubbles for the 20-second reel, said by the armies to each other. BanterService picks
| from here and may swap up to two lines for AI-written ones that pass the same rules.
|
| Rules for every line (they apply to AI lines too):
|   - The armies talk to each other. Never real players, managers, referees or fans.
|   - Facts only from the match: {army}, {foe} and {score} are filled in from the data.
|   - No tribe, religion, politics, nationality, body, gender or sexuality jokes; no slurs, no swearing.
|   - Playful about the game, never cruel about losses or injuries.
|   - Short: a bubble is read (and spoken) in about two seconds, so 60 characters at most.
|
| {army} is the speaker's army, {foe} the other army, {score} the speaker's score first ("2–1").
| Pidgin (pcm) lines need a native speaker's review before new ones are added.
|
*/

return [
    // Before kick-off, on the title card.
    'title' => [
        'en' => [
            'Today the {foe} learn our name.',
            '{army}, hold the line. This is our ground.',
            'Sharpen everything. We march at the whistle.',
            'They came a long way to lose.',
            'Ninety minutes. One army walks home proud.',
            'Shields up. The {foe} are here.',
            'I hope the {foe} brought a plan.',
            'Same field, same story. We win.',
        ],
        'pcm' => [
            'Oya {foe}, today na today.',
            'We dey ready. Make una sef ready.',
            'Today, una go hear am.',
            '{foe}, una sure say una wan play this one?',
            'Abeg make dem blow whistle, we dey wait.',
            'This field na our house. Welcome o.',
            'No shaking. We get this one.',
            'Wetin una carry come today?',
        ],
    ],

    // The scoring army, on its own goal.
    'goal' => [
        'en' => [
            'Did you see that? Neither did you.',
            'First blood is ours.',
            'Keep up, {foe}. Keep up.',
            'Mark that one down. {score}.',
            'Write it in the history books.',
            "That's how the {army} do it.",
            'Again? Again.',
            'Somebody wake the {foe} up.',
        ],
        'pcm' => [
            'E don enter! Shey you see am?',
            'Ehen! Na so we dey do am.',
            '{foe}, una dey sleep?',
            'Gbas! E don land.',
            'Na {score} o. Count am well.',
            'Who dey breet? Na {army}!',
            'Una never see anything yet.',
            'Sharp sharp, e don enter.',
        ],
    ],

    // The army that just conceded.
    'conceded' => [
        'en' => [
            'Lucky. Pure luck.',
            "That doesn't count. Does it?",
            "It's fine. We like a challenge.",
            'Regroup! This is not over.',
            "We'll be back. Don't celebrate yet.",
            'Shields up, everyone. Properly this time.',
        ],
        'pcm' => [
            'Na wa o. Who leave that gap?',
            'Calm down, e never finish.',
            'E pain me, but we go collect am back.',
            'Na small thing. We dey come.',
            'Abeg, make we focus.',
            'Wahala dey o. Oya, regroup!',
        ],
    ],

    // A goal at 85 minutes or later.
    'late_goal' => [
        'en' => [
            'Last minute. Best minute.',
            'You thought it was over?',
            'Never leave early against the {army}.',
            'Right on the final whistle!',
        ],
        'pcm' => [
            'Last minute! E sweet pass!',
            'Una don dey go house? Come back!',
            'Na at the end e dey sweet pass.',
            'E don happen for last minute!',
        ],
    ],

    // The other army, when the speaker's side gets a red card... and vice versa.
    'red_card_taunt' => [
        'en' => [
            'One down. The rest of you next.',
            'Send him off. We have room.',
            'Fewer of them, more of us.',
        ],
        'pcm' => [
            'One don comot. Oya, who next?',
            'Calm down o. Red card no be play.',
            'Una don reduce o.',
        ],
    ],
    'red_card_lament' => [
        'en' => [
            'Fine. We win with fewer.',
            "That's harsh. We fight on.",
            'Ten of us is still plenty.',
        ],
        'pcm' => [
            'Na wa. We go manage am.',
            'E hard, but we still dey here.',
            'Red card? No wahala, we still go fight.',
        ],
    ],

    // Full time, the winners.
    'win' => [
        'en' => [
            '{score}. Tell your friends.',
            'Same time next season, {foe}?',
            'The {army} march home happy.',
            "Good game. Well, ours was.",
            'Three points, packed and carried home.',
            'You fought well. We fought better.',
        ],
        'pcm' => [
            'E don finish! {score}, count am.',
            'Thank you for coming, {foe}.',
            'Na so we dey do am every time.',
            'Soft life. Three points don enter.',
            'Una try, but e no reach.',
            'Make una come back next time.',
        ],
    ],
    // Full time, a margin of three or more.
    'big_win' => [
        'en' => [
            '{score}. We stopped counting.',
            'Was that a match or a lesson?',
            "Someone tell the {foe} it's over.",
        ],
        'pcm' => [
            '{score}! Una go hear am tire.',
            'Na lesson we teach una today.',
            'Gbas gbos! E don do, abeg.',
        ],
    ],
    // Full time, the beaten army.
    'loss' => [
        'en' => [
            "Next time. We'll be ready.",
            'Today was theirs. Tomorrow is ours.',
            "We'll remember this.",
        ],
        'pcm' => [
            'E pain me, but next time na our own.',
            'We go come back stronger.',
            'Na so football be. Next time.',
        ],
    ],
    // Full time, level.
    'draw' => [
        'en' => [
            'Nobody wins. Nobody sleeps tonight.',
            'Shake hands. Rematch soon.',
            'A point each. For now.',
            "Same again next time? I'll bring more.",
        ],
        'pcm' => [
            'Draw? Hmm. We go settle am next time.',
            'Nobody win, nobody lose. Na so.',
            'Shake hand. Next time e go loud.',
            'One point. E no bad sha.',
        ],
    ],

    // Whole words no bubble may contain, AI or written (case-insensitive). Army names are removed
    // before checking, so "Zulu Impi" is fine while "zulu" on its own is not.
    'blocklist' => [
        'god', 'allah', 'jesus', 'church', 'mosque', 'pastor', 'imam', 'religion', 'muslim', 'christian', 'pagan',
        'tribe', 'tribal', 'yoruba', 'igbo', 'hausa', 'fulani', 'ijaw', 'zulu', 'xhosa', 'kikuyu', 'luo',
        'president', 'government', 'election', 'politics', 'political', 'tinubu', 'buhari',
        'fat', 'ugly', 'gay', 'girl', 'woman', 'women', 'wife', 'gender', 'sexy', 'sex',
        'die', 'death', 'dead', 'kill', 'killed', 'injury', 'injured', 'injuries', 'hospital', 'cripple',
        'stupid', 'idiot', 'fool', 'mumu', 'ode', 'olodo', 'yeye', 'werey', 'craze', 'mad',
        'damn', 'hell', 'shit', 'fuck', 'bitch', 'ass', 'bastard', 'crap',
        'referee', 'ref', 'var', 'coach', 'manager', 'fan', 'fans', 'supporter',
        'black', 'white', 'african', 'english', 'british', 'turk', 'arab', 'country', 'nation',
    ],
];
