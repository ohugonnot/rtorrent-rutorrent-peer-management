# Peer Management Script for rTorrent

This PHP script interacts with the rTorrent API to manage peers dynamically based on their download and upload speeds. It retrieves system settings, fetches the list of torrents, and analyzes peer performance to ensure optimal seeding and leeching. It's possible to make a Rtorrent plugin with this code if your a motivate

## Features
- **Peer Performance Monitoring**: Continuously monitors peers' upload and download speeds.
- **Dynamic Peer Management**: Automatically kicks slow peers if the system or torrent is reaching its maximum peer capacity.
- **Customizable**: Easily adjustable parameters to suit different rTorrent setups and requirements.
- **Cache Management**: Uses a JSON file to cache peer data for efficient access and management.
- **Daily Cache Cleanup**: Removes old peer data to ensure the cache remains up-to-date.

## Requirements
- PHP 7.4+
- cURL extension enabled
- rTorrent with HTTPRPC plugin enabled

# Rtorrent Speed Manager

This script is designed to manage torrent download speeds in rTorrent based on the upload speed of peers. It periodically checks the upload speed of peers connected to seeding torrents and kicks slow peers to maintain optimal download speeds.

### Features

- Automatically kicks slow peers to maintain optimal download speeds
- Supports command-line usage with login and password
- Utilizes cURL to interact with the rTorrent API

### Requirements

- PHP 7.0 or higher
- cURL extension enabled

### Usage

1. Before running the script, make sure you have rTorrent running and accessible via its HTTP RPC plugin.
2. Edit the script `cron.php` and replace `'yourlogin'` and `'yourpassword'` with your rTorrent login credentials.
3. Run the script from the command line with login and password or accès to id directly with a cron on server:

    ```bash
    php cron.php yourlogin yourpassword

    # or add it in a cron => crontab -e
    */5 * * * * php /path/to/file/cron.php your_login your_password >> /cron.log
    ```

### Configuration

- You can adjust the settings in the `cron.php` file to customize the behavior of the script according to your preferences.
