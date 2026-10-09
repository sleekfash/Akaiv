<?php

use Spatie\Tags\Tag;

return [
    'models' => [
        'tag' => Tag::class,
    ],
    'tables' => [
        'tags' => 'tags',
        'taggables' => 'taggables',
    ],
    'column_names' => [
        'model_morph_key' => 'model_id',
    ],
    'translatable' => false,
    'enable_inertia_teams' => false,
];
