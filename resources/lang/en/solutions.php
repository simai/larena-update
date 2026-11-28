<?php

declare(strict_types=1);

// larena:solution:check (§14 stage 4): the words of the plan and of the refusals; reason codes are in integrity.php.
return [
    'planned' => 'The solution :solution :version can be installed on this site:',
    'refused' => 'The solution cannot be installed on this site; nothing was changed:',
    'present' => 'already installed',
    'install' => 'will be installed',
    'sitepack' => 'content: :ref',
    'settings' => 'settings: :keys',
    'rights_missing' => 'The solution needs addons this site does not have:',
    'try' => 'Try free for :days day|Try free for :days days',
    'buy' => 'Buy',
    'site_not_empty' => 'The site is not empty, a solution is installed only on a clean site. In the way:',
    'records' => ':count own record|:count own records',
];
