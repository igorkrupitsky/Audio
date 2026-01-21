# Audiobook Player

I have bunch of mp3 audiobooks that I wanted to play wherever I am away from my desk.  So I wrote this...

![](img/folders.png)

![](img/props.png)

![](img/play.png)

## Features
- PHP or ASP.NET
- Desktop and Mobile friendly
- Auto-play next track
- Go back 30 seconds
- Breadcrumb  trail to go up the folders tree
- Ability to bookmark any audiobook
- MySQL to store metadata (each folder name with mp3 files has to have unique name)
- Assumes each folder holds one audiobook
- Offline mode lets you download files and listen to them when not connected to the internet
- VB Script that Selenium to scrape the web and update Book info (Link, Title, Author, Rating, Pub date)
- Ability to set my own rating

## How to use (PHP):
- Buy storage from interserver ($3 per month for 1 TB) https://www.interserver.net/storage/
- Upload your mp3 audiobooks
- Copy files from the project to the root folder.
- Create MySQL database in interserver and run CreateTables.sql
- Create config.php, update connection info and copy it to the server next to Player.php

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

### Google Sign-In (PHP)

The PHP version uses Google Identity Services. The server-side validator lives in `Auth/GoogleSignIn.php` and stores users in the `AppUser` table.

1. Create a Google OAuth Client ID (Web) in Google Cloud Console.
2. Configure **Authorized JavaScript origins** to include your site (e.g. `https://yourdomain.com`).
3. On the server, set an environment variable named `GOOGLE_CLIENT_ID` to your `*.apps.googleusercontent.com` value.

When `GOOGLE_CLIENT_ID` is present, `Player.php` will show a Google sign-in button and will POST the returned `credential` JWT to `Auth/GoogleSignIn.php`.

## How to use (ASP.NET):
- Buy purchase from godaddy ($6 per month for 25 GB) https://www.godaddy.com/hosting-solutions
- Upload your mp3 audiobooks
- Copy files from the project to the root folder.
- Create MySQL database in godaddy and run CreateTables.sql
- Create web.config, update connection info and copy it to the server next to Player.php

```XML
<?xml version="1.0"?>
<configuration>
  <appSettings>
    <add key="AudioDb" value="Server=123.123.123.123;Database=Audio;Uid=Audio;Pwd=pass1;" />
  </appSettings>  
</configuration>
```

Point your browser to Player.aspx

## How to use VBS:
- Create VBS\db_connection.txt and put connection string like

```
Server=123.123.123.123;Database=Audio;User=Audio;Password=pass123;Option=3;
```

- VBS\MakeIndex.vbs 
1. will add a record in the Folder table
2. will remove old folders
3. will update Book info for folders that were moved to another location

- VBS\UpdateIndex.vbs
1. will use Selenium Basic to Google book info and use Amazon to update the data to update the database
