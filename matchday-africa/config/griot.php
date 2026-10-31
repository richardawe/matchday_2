<?php

/*
|--------------------------------------------------------------------------
| The live Griot timeline
|--------------------------------------------------------------------------
|
| ESPN commentary (and, for training, StatsBomb events) becomes standard plays
| (match_plays); a picker chooses the moments worth telling (griot_posts); the Griot
| captions them in four voices and Kokoro speaks them in the Griot's voice.
|
| Standard play types, shared with griot-ml/ (training) — keep both lists in step:
|   goal own_goal penalty_goal penalty_miss shot_saved shot_missed shot_blocked woodwork
|   var_no_goal var_decision yellow_card red_card substitution foul handball corner offside
|   delay period_start period_end added_time other
| x/y: 0–100 along the attacking side's direction (100 = the goal it attacks), y across.
| detail: zone (six_yard|box|outside), body (left|right|head), free_kick, penalty, close, injury, cross.
|
*/

return [
    'enabled' => (bool) env('GRIOT_TIMELINE', false),

    // The learned picker (griot-ml/train_picker.py → JSON). Missing file: the rules decide alone.
    'picker_model' => env('GRIOT_PICKER_MODEL', storage_path('app/griot/picker.json')),
    // Rule candidates the model scores below this are dropped (goals, cards and whistles always stay).
    'picker_threshold' => (float) env('GRIOT_PICKER_THRESHOLD', 0.5),

    // Pressure spell: this many shots by one side inside this many minutes, without scoring.
    'pressure_shots' => 3,
    'pressure_minutes' => 6,
    // A side gets at most one big-chance or pressure post per this many minutes.
    'cooldown_minutes' => 10,

    // The small writer (griot-ml/train_writer.py → transformers.js folder). Off: the Griot templates speak.
    'writer' => [
        'enabled' => (bool) env('GRIOT_WRITER', false),
        'model' => env('GRIOT_WRITER_MODEL', storage_path('app/griot/writer')),
        'per_run' => 6,
    ],

    // Voice clips for each post (Kokoro through radio/griot-voice.mjs). Needs the radio runtime.
    'voice' => [
        'enabled' => (bool) env('GRIOT_VOICE', false),
        'node' => env('GRIOT_NODE', env('RADIO_NODE', 'node')),
        'ffmpeg' => env('GRIOT_FFMPEG', env('RADIO_FFMPEG', 'ffmpeg')),
        // Kokoro voices blended into the Griot: "voice:weight,…".
        'blend' => env('GRIOT_VOICE_BLEND', 'bm_george:0.6,am_onyx:0.4'),
        'speed' => (float) env('GRIOT_VOICE_SPEED', 0.92),
        'language' => env('GRIOT_VOICE_LANGUAGE', 'en'),
        'per_run' => 6,
    ],
];
