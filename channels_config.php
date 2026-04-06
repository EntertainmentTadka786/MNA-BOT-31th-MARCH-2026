<?php
// channels_config.php - Multi-channel configuration

return [
    // Public channels (with username)
    'public_channels' => [
        [
            'username' => '@EntertainmentTadka786',
            'id' => -1003181705395,
            'name' => 'Main Movies'
        ],
        [
            'username' => '@Entertainment_Tadka_Serial_786',
            'id' => -1003614546520,
            'name' => 'TV Series'
        ],
        [
            'username' => '@threater_print_movies',
            'id' => -1002831605258,
            'name' => 'Theater Print'
        ],
        [
            'username' => '@ETBackup',
            'id' => -1002964109368,
            'name' => 'Backup Channel'
        ]
    ],
    
    // Private channels (only ID, no username)
    'private_channels' => [
        [
            'id' => -1003251791991,
            'name' => 'Private Channel 1'
        ],
        [
            'id' => -1002337293281,
            'name' => 'Private Channel 2'
        ]
    ],
    
    // Request group (where users can request)
    'request_group' => [
        'username' => '@EntertainmentTadka7860',
        'id' => -1003083386043
    ],
    
    // Optional: main channel for backward compatibility (pehle wala)
    'default_channel' => -1003181705395
];
?>
