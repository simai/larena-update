<?php

declare(strict_types=1);

// «Лицензия и обновления» → «Обновления» (owner task 2026-11-26).
return [
    'title' => 'Updates',
    'description' => 'A new version installs with a copy of the site and rolls itself back if something is wrong after it.',
    'current' => [
        'heading' => 'On the site now',
        'version' => 'Version',
        'unknown' => 'not known',
        'channel' => 'Channel',
        'sources' => 'Update servers',
        'no_sources' => 'not set',
    ],
    'new' => [
        'heading' => 'New version',
        'never' => 'Not checked yet',
        'never_text' => 'Press «Check now»: the site asks the update server whether a newer version exists.',
        'up_to_date' => 'You have the newest version :version.',
        'available' => 'Version :version is available (you have :installed).',
        'notes' => 'What is in it',
        'incompatible' => 'The database changes of this version cannot be undone by switching code: a rollback restores the database from the copy.',
        'how' => 'Before installing, the site makes a copy. If the site does not answer as it should afterwards, it goes back to the previous version by itself.',
        'failed' => 'The check failed',
        'checked' => 'Checked',
        'check_now' => 'Check now',
        'install' => 'Install :version',
        'queued' => 'Installing :version is queued. Its progress and result appear in the journal below.',
        'no_queue' => 'The site has no job queue: install from the console with php artisan larena:update:apply :version --actor=<your subject_ref>.',
    ],
    'journal' => [
        'heading' => 'Installation journal',
        'none' => 'No installations yet.',
        'unfinished' => 'One installation is not finished. A new one will not start while it runs.',
        'step' => 'step «:step»',
        'rollback' => 'To go back to the previous version by hand: php artisan larena:update:rollback.',
    ],
    'states' => [
        'completed' => 'installed',
        'rolled_back' => 'the previous version is back',
        'recoverable_failed' => 'failed, the site went back to the previous version by itself',
        'manual_recovery_required' => 'failed and the rollback did not finish: the site is in maintenance and needs help',
        'blocked' => 'stopped before anything on the site changed',
        'planned' => 'in progress',
        'execution_ready' => 'in progress',
        'running' => 'in progress',
    ],
    'reasons' => [
        'update_up_to_date' => 'Checked: there is no newer version.',
        'update_available' => 'Checked: a newer version is available.',
        'update_queued' => 'The installation is queued.',
        'update_offer_unknown' => 'The last check did not offer this version: check again.',
        'update_already_running' => 'An installation is already running: wait for it to end.',
        'update_queue_unavailable' => 'The site has no job queue: install from the console (larena:update:apply).',
        'update_actor_required' => 'It is not clear who installs: sign in again.',
        'release_current_missing' => 'The site is not installed from a release (a development copy or a Git checkout): it cannot be updated here.',
        'release_version_unknown' => 'The version of the current site release could not be read.',
        'update_store_unavailable' => 'The update journal table is missing: run php artisan migrate.',
        'other' => 'It did not work. Details are in the journal and in larena:update:status.',
    ],
];
