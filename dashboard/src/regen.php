<?php
require '/var/www/html/config/database.php';
require '/var/www/html/endpoints/sites.php';

echo "Regenerating site 2..." . PHP_EOL;
generateNginxConfig(2);
echo "Done!" . PHP_EOL;
