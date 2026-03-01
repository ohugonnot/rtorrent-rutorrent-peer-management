<?php
// Configuration settings
// refer to https://rtorrent-docs.readthedocs.io/en/latest/cmd-ref.html#term-d-accepting-seeders
// this script must be launched all 5 minutes in cron  */5 * * * * php /path/to/file/cron.php your_login your_password >> /cron.log

if (!defined('TESTING')) {
    $minElapsed = 300; // Minimum elapsed time in seconds before kick
    $minUploadSpeed = 100; // Minimum upload speed in ko/sec (under that value peer was kicked)
    $minDownloadSpeed = 100;  // Minimum download speed in ko/sec (under that value peer was kicked)
    $delta = 10;  // delta between maximum upload connection and actual upload connection for begin to kick peer
    $url = 'http://localhost/rutorrent/plugins/httprpc/action.php';

    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    // Credentials: CLI args > env vars > defaults
    // Prefer env vars over CLI args to avoid exposing credentials in `ps aux`
    // Example: export RTORRENT_LOGIN=mylogin RTORRENT_PASSWORD=mypassword
    $login = $argv[1] ?? getenv('RTORRENT_LOGIN') ?: 'your_login';
    $mdp   = $argv[2] ?? getenv('RTORRENT_PASSWORD') ?: 'your_password';

    $cacheFile = "peers_cache_$login.json";

    // Load peer cache once into memory — written back once at the end
    $peersCache = [];
    if (file_exists($cacheFile)) {
        try {
            $peersCache = json_decode(file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            echo "Warning: could not read cache file, starting fresh: " . $e->getMessage() . "\n";
            $peersCache = [];
        }
    }

    // Récupération des informations système
    // take on action.php in http://localhost/rutorrent/plugins/httprpc/action.php check if is the same for you
    $cmds = array(
        "get_check_hash", "get_bind", "get_dht_port", "get_directory", "get_download_rate",
        "get_hash_interval", "get_hash_max_tries", "get_hash_read_ahead", "get_http_cacert", "get_http_capath",
        "get_http_proxy", "get_ip", "get_max_downloads_div", "get_max_downloads_global", "get_max_file_size",
        "get_max_memory_usage", "get_max_open_files", "get_max_open_http", "get_max_peers", "get_max_peers_seed",
        "get_max_uploads", "get_max_uploads_global", "get_min_peers_seed", "get_min_peers", "get_peer_exchange",
        "get_port_open", "get_upload_rate", "get_port_random", "get_port_range", "get_preload_min_size",
        "get_preload_required_rate", "get_preload_type", "get_proxy_address", "get_receive_buffer_size", "get_safe_sync",
        "get_scgi_dont_route", "get_send_buffer_size", "get_session", "get_session_lock", "get_session_on_completion",
        "get_split_file_size", "get_split_suffix", "get_timeout_safe_sync", "get_timeout_sync", "get_tracker_numwant",
        "get_use_udp_trackers", "get_max_uploads_div", "get_max_open_sockets"
    );

    try {
        $data = ['mode' => 'stg'];
        $system = getCurl($url, $data, $login, $mdp);
    } catch (Exception $e) {
        echo "Fatal: could not reach rTorrent API: " . $e->getMessage() . "\n";
        exit(1);
    }

    $systemNamed = [];
    $ecart = count($system) - count($cmds);
    foreach ($system as $k => $value) {
        $systemNamed[$cmds[$k - $ecart] ?? "unknow$k"] = $value;
    }

    // Récupération de la liste des torrents
    $data = [
        'mode' => 'list',
        'cmd' => [
            'd.complete=',
            'd.accepting_seeders=',
            "d.chunk_size=",
            "d.connection_current=",
            "d.is_private=",
            "d.peers_max=",
            /*
                "d.directory=",
                "d.timestamp.finished=",
                "d.timestamp.started="
                "d.uploads_max=",
                "d.uploads_max.set=",
                "d.up.rate=",
                "d.up.total=",
                "d.downloads_max=",
                "d.downloads_max.set=",
                "d.down.rate=",
                "d.down.total=",
                "d.open=",
                "d.close=",
                "d.pause=",
                "d.resume=",
                "d.close.directly=",
                "d.start=",
                "d.stop=",
                "d.erase=",
                "d.disconnect.seeders=",
            */
        ]
    ];

    try {
        $response = getCurl($url, $data, $login, $mdp);
    } catch (Exception $e) {
        echo "Fatal: could not fetch torrent list: " . $e->getMessage() . "\n";
        saveCache($cacheFile, $peersCache);
        exit(1);
    }

    $torrentsNoNamed = $response['t'] ?? [];
    $torrents = [];

    // take on action.php in http://localhost/rutorrent/plugins/httprpc/action.php check if is the same for you
    $cmds = array(
        "d.get_hash=", "d.is_open=", "d.is_hash_checking=", "d.is_hash_checked=", "d.get_state=",
        "d.get_name=", "d.get_size_bytes=", "d.get_completed_chunks=", "d.get_size_chunks=", "d.get_bytes_done=",
        "d.get_up_total=", "d.get_ratio=", "d.get_up_rate=", "d.get_down_rate=", "d.get_chunk_size=",
        "d.get_custom1=", "d.get_peers_accounted=", "d.get_peers_not_connected=", "d.get_peers_connected=", "d.get_peers_complete=",
        "d.get_left_bytes=", "d.get_priority=", "d.get_state_changed=", "d.get_skip_total=", "d.get_hashing=",
        "d.get_chunks_hashed=", "d.get_base_path=", "d.get_creation_date=", "d.get_tracker_size=", "d.is_active=",
        "d.get_message=", "d.get_custom2=", "d.get_free_diskspace=", "d.is_private=", "d.is_multi_file="
    );
    $cmds = array_merge($cmds, $data['cmd']);
    foreach ($torrentsNoNamed as $hash => $values) {
        $ecart = count($values) - count($cmds);
        foreach ($values as $key => $value) {
            $torrents[$hash][$cmds[$key - $ecart] ?? "unknow$key"] = $value;
        }
    }

    $total_peer_connected = array_reduce($torrents, static fn($sum, $torrent) => $sum + (int)$torrent['d.get_peers_connected='], 0);
    $system_has_max_peer = $total_peer_connected >= ((int)$systemNamed['get_max_peers_seed'] - $delta);
    echo "Total connection used $total_peer_connected/" . $systemNamed['get_max_peers_seed'] . "\n";

    foreach ($torrents as $hash => $torrent) {
        $peers_id_a_bannir = [];
        $peers_a_bannir = [];

        $is_leech_torrent = $torrent['d.complete='] === "0" && $torrent['d.is_active='] === "1" && (int)$torrent['d.get_peers_connected='] > 0;
        $is_seed_torrent  = $torrent['d.complete='] === "1" && $torrent['d.is_active='] === "1" && (int)$torrent['d.get_peers_connected='] > 0;

        if ($is_leech_torrent || $is_seed_torrent) {
            $torrent_has_max_peer = (int)$torrent['d.get_peers_connected='] >= ((int)$torrent['d.peers_max='] - $delta);
            echo "Connexion used for " . $torrent['d.get_name='] . " " . $torrent['d.get_peers_connected='] . "/" . $torrent['d.peers_max='] . "\n";

            try {
                $prs = getCurl($url, ['mode' => 'prs', 'hash' => $hash], $login, $mdp);
            } catch (Exception $e) {
                echo "Warning: could not fetch peers for " . $torrent['d.get_name='] . ": " . $e->getMessage() . "\n";
                continue;
            }

            foreach ($prs as $pr) {
                $peer = [
                    "downloaded" => (int)$pr[7] / 1024,
                    "uploaded"   => (int)$pr[8] / 1024,
                    "dl"         => (int)$pr[9] / 1024,
                    "up"         => (int)$pr[10] / 1024,
                    "ip"         => $pr[1],
                    "id"         => $pr[0],
                ];

                $cachePeer = $peersCache[$peer['id']] ?? [];
                if (!empty($cachePeer)) {
                    $cachePeer['ups'][] = $peer['up'];
                    $cachePeer['dls'][] = $peer['dl'];
                } else {
                    $cachePeer                     = $peer;
                    $cachePeer['first_uploaded']   = $peer['uploaded'] ?? 0;
                    $cachePeer['first_downloaded'] = $peer['downloaded'] ?? 0;
                    $cachePeer['ups']              = [$peer['up']];
                    $cachePeer['dls']              = [$peer['dl']];
                    $cachePeer['date']             = time();
                }
                $peersCache[$peer['id']] = $cachePeer;

                $elapsed = time() - (int)$cachePeer['date'];

                if (shouldKickPeer($peer, $cachePeer, $elapsed, $minElapsed, $torrent_has_max_peer, $system_has_max_peer, $is_seed_torrent, $is_leech_torrent, $minUploadSpeed, $minDownloadSpeed)) {
                    $peers_id_a_bannir[] = $peer['id'];
                    $peers_a_bannir[]    = $peer;
                    unset($peersCache[$peer['id']]);
                }
            }

            if (!empty($peers_id_a_bannir)) {
                try {
                    getCurl($url, ['mode' => 'kick', 'hash' => $hash, 'v' => $peers_id_a_bannir], $login, $mdp);
                    echo "kick torrent " . $torrent['d.get_name='] . " " . implode(", ", $peers_id_a_bannir) . "\n";
                } catch (Exception $e) {
                    echo "Warning: kick failed for " . $torrent['d.get_name='] . ": " . $e->getMessage() . "\n";
                }
            }
        }
    }

    // Clean stale entries (older than 24h) and save cache once
    foreach ($peersCache as $peerId => $peer) {
        if (time() - $peer['date'] >= 86400) {
            unset($peersCache[$peerId]);
        }
    }
    saveCache($cacheFile, $peersCache);
}

// --- Functions ---

function getCurl(string $url, array $data, string $login, string $mdp): array
{
    $posts = buildQuery($data);
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
        CURLOPT_USERPWD        => "$login:$mdp",
        CURLOPT_POSTFIELDS     => $posts,
        CURLOPT_POST           => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException("cURL error: $curlError");
    }
    if ($httpCode !== 200) {
        throw new RuntimeException("HTTP $httpCode from rTorrent API");
    }

    return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
}

function saveCache(string $cacheFile, array $peersCache): void
{
    try {
        file_put_contents($cacheFile, json_encode($peersCache, JSON_THROW_ON_ERROR));
    } catch (JsonException $e) {
        echo "Warning: could not save cache: " . $e->getMessage() . "\n";
    }
}

function buildQuery(array $data): string
{
    $queryParts = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $subValue) {
                $queryParts[] = urlencode($key) . '=' . urlencode($subValue);
            }
        } else {
            $queryParts[] = urlencode($key) . '=' . urlencode($value);
        }
    }
    return implode('&', $queryParts);
}

function calculateAverage(array $numbers): float
{
    if (empty($numbers)) {
        return 0.0;
    }
    return array_sum($numbers) / count($numbers);
}

function shouldKickPeer(
    array $peer,
    array $cachePeer,
    int $elapsed,
    int $minElapsed,
    bool $torrent_has_max_peer,
    bool $system_has_max_peer,
    bool $is_seed_torrent,
    bool $is_leech_torrent,
    int $minUploadSpeed,
    int $minDownloadSpeed
): bool {
    if ($elapsed <= $minElapsed) {
        return false;
    }

    // kick slow peers when reaching max connection capacity
    if ($torrent_has_max_peer || $system_has_max_peer) {
        if ($is_seed_torrent) {
            $vitesseMoyenne = ((float)$peer['uploaded'] - (float)$cachePeer['first_uploaded']) / $elapsed;
            if ($vitesseMoyenne < $minUploadSpeed || calculateAverage($cachePeer['ups']) < $minUploadSpeed) {
                return true;
            }
        }
        if ($is_leech_torrent) {
            $vitesseMoyenne = ((float)$peer['downloaded'] - (float)$cachePeer['downloaded']) / $elapsed;
            if ($vitesseMoyenne < $minDownloadSpeed || calculateAverage($cachePeer['dls']) < $minDownloadSpeed) {
                return true;
            }
        }
    }

    // kick peers with zero transfer after observation window
    if ($is_seed_torrent) {
        $uploaded = (float)$peer['uploaded'] - (float)$cachePeer['first_uploaded'];
        if ($uploaded === 0.0) {
            return true;
        }
    }
    if ($is_leech_torrent) {
        $downloaded = (float)$peer['downloaded'] - (float)$cachePeer['first_downloaded'];
        if ($downloaded === 0.0) {
            return true;
        }
    }

    return false;
}
