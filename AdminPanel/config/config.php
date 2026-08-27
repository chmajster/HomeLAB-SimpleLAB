<?php

declare(strict_types=1);

return [
    'app_name' => 'HomeLAB SimpleLAB',
    'db_path' => getenv('SIMPLELAB_DB_PATH') ?: '/var/lib/homelab-simplelab/simplelab.db',
    'log_path' => getenv('SIMPLELAB_LOG_PATH') ?: '/var/log/homelab-simplelab/app.log',
    'session_name' => 'simplelab_session',
];
