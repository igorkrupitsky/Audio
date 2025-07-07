# Audiobook Player

## Features
- PHP or ASP.NET
- Desktop and Mobile friendly
- MySQL to store metadata
- Assumes each folder holds one audiobook
- Offline mode let you download and folders

## How to use:
- Buy purchase from interserver ($3 per month ofr 1 TB) [https://www.interserver.net/]
- Upload your mp3 audiobooks
- Copy files from the project to the root folder.
- Create MySQL database in interserver
- Create config.php and copy it to the server next to Player.php

```PHP
<?php
// Database configuration. DO NOT commit this file to version control!
return [
    'DB_HOST' => '123.123.123.123',
    'DB_NAME' => 'Audio',
    'DB_USER' => 'Audio',
    'DB_PASS' => 'password1'
];
```

Point your browser to Player.php

