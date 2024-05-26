# Peer Speed Manager Script for rTorrent + ruTorrent

This PHP script interacts with the rTorrent API to manage peers dynamically based on their download and upload speeds. It retrieves system settings, fetches the list of torrents, and analyzes peer performance to ensure optimal seeding and leeching. It's possible to make a Rutorrent plugin with this code. This script automatically disconnects peers with low upload or download rates to reserve available slots for faster peers.

## Features
- **Peer Performance Monitoring**: Continuously monitors peers' upload and download speeds.
- **Dynamic Peer Management**: Automatically kicks slow peers if the system or torrent is reaching its maximum peer capacity.
- **Customizable**: Easily adjustable parameters to suit different rTorrent setups and requirements (minUploadRate, minDownloadRate, period, ect...).
- **Cache Management**: Uses a JSON file to cache peer data for efficient access and management.
- Supports command-line usage with login and password for secured rutorrent connexion
- Utilizes cURL to interact with the rTorrent API

## Requirements
- PHP 7.4+
- cURL extension enabled
- ruTorrent with last HTTPRPC plugin enabled

### Usage

1. Before running the script, make sure you have rTorrent running and accessible via its HTTP RPC plugin in ruTorrent.
   https://github.com/Novik/ruTorrent/blob/master/plugins/httprpc/action.php
3. Edit the script `cron.php` and replace `'yourlogin'` and `'yourpassword'` with your rTorrent login credentials or pass it on GET variable or CLI argument.
4. Run the script from the command line with login and password or directly with a cron on server:

    ```bash
    php cron.php yourlogin yourpassword

    # or add it in a cron => crontab -e
    */5 * * * * php /path/to/file/cron.php your_login your_password >> /cron.log
    ```

### Configuration

- You can adjust the settings in the `cron.php` file to customize the behavior of the script according to your preferences the config options are above file.
