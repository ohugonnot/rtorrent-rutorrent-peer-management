<?php
// Configuration settings
// refer to https://rtorrent-docs.readthedocs.io/en/latest/cmd-ref.html#term-d-accepting-seeders
// this script must be launched all 5 minutes in cron  */5 * * * * php /path/to/file/cron.php your_login your_password >> /cron.log
$minElapsed = 300; // Minimum elapsed time in seconds before kick
$minUploadSpeed = 100; // Minimum upload speed in ko/sec (under that value peer was kicked)
$delta = 10;  // delta between maximum upload connection and actual upload connectin for begin to kick peer
$url = 'http://localhost/rutorrent/plugins/httprpc/action.php';

//ini_set('display_errors', 1);
//error_reporting(E_ALL);

$login = $argv[1] ?? $_GET['login'] ?? 'your_login';
$mdp = $argv[2] ?? $_GET['mdp'] ?? 'your_password';

global $cacheFile;
$cacheFile = "peers_cache_$login.json";

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
$data = ['mode' => 'stg'];
$system = getCurl($url, $data, $login, $mdp);
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

$response = getCurl($url, $data, $login, $mdp);
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
$cmds = array_merge($cmds,$data['cmd']);
foreach ($torrentsNoNamed as $hash => $values) {
    $ecart = count($values) - count($cmds);
    foreach($values as $key => $value) {
        $torrents[$hash][$cmds[$key - $ecart] ?? "unknow$key"] = $value;
    }
}

$total_peer_conntected = array_reduce($torrents, static fn($sum, $torrent) => $sum + (int)$torrent['d.get_peers_connected='], 0);
echo "Total connection used $total_peer_conntected/" . $systemNamed['get_max_peers_seed'] . "\n";

foreach ($torrents as $hash => $torrent) {
    $peers_id_a_bannir = [];
    $peers_a_bannir = [];

    if ($torrent['d.complete='] === "1" && $torrent['d.is_active='] === "1" && (int)$torrent['d.get_peers_connected='] > 0) {
        $data = [
            'mode' => 'prs',
            'hash' => $hash,
        ];
        $prs = getCurl($url, $data, $login, $mdp);
        foreach ($prs as $pr) {
            $peer = [
                "downloaded" => (int)$pr[7] / 1024,
                "uploaded" => (int)$pr[8] / 1024,
                "dl" => (int)$pr[9] / 1024,
                "up" => (int)$pr[10] / 1024,
                "ip" => $pr[1],
                "id" => $pr[0],
            ];
            $cachePeer = getDataForPeer($peer);
            if (!empty($cachePeer)) {
                $cachePeer['ups'][] = $peer['up'];
                $cachePeer['dls'][] = $peer['dl'];
            } else {
                $cachePeer = $peer;
                $cachePeer['first_uploaded'] = $peer['uploaded'] ?? 0;
                $cachePeer['ups'] = [$peer['up']];
                $cachePeer['dls'] = [$peer['dl']];
                $cachePeer['date'] = time();
            }
            setDataForPeer($cachePeer);

            $now = time();
            $elapsed = $now - $cachePeer['date'];
            $torrent_has_max_peer = (int)$torrent['d.get_peers_connected='] >= ((int)$torrent['d.peers_max='] - $delta);
            $system_has_max_peer = $total_peer_conntected >= ((int)$systemNamed['get_max_peers_seed'] - $delta);

            if ($elapsed > $minElapsed && ($torrent_has_max_peer || $system_has_max_peer)) {
                $vitesseMoyenne = ((float)$peer['uploaded'] - (float)$cachePeer['first_uploaded']) / $elapsed;
                if ($vitesseMoyenne < $minUploadSpeed) {
                    $peers_id_a_bannir[] = $peer['id'];
                    $peers_a_bannir[] = $peer;
                    removeCache($peer);
                }
            }

            if ($elapsed > $minElapsed && $system_has_max_peer) {
                $averageUps = calculateAverage($peer['ups']);
                if ($averageUps < $minUploadSpeed*1024) {
                    $peers_id_a_bannir[] = $peer['id'];
                    $peers_a_bannir[] = $peer;
                    removeCache($peer);
                }
            }
        }

        if (!empty($peers_id_a_bannir)) {
            $data = [
                'mode' => 'kick',
                'hash' => $hash,
                'v' => $peers_id_a_bannir,
            ];
            $test = getCurl($url, $data, $login, $mdp);
            var_dump("kick torrent" . $torrent['d.name='], $peers_a_bannir);
        }
    }
}
cleanCache();

/**
 * @throws JsonException
 */
function getCurl($url, $data, $login, $mdp): array
{
    $posts = buildQuery($data);
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
        CURLOPT_USERPWD => "$login:$mdp",
        CURLOPT_POSTFIELDS => $posts,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ];

    $curl = curl_init();
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    curl_close($curl);

    if ($response === false) {
        echo 'Erreur Curl : ' . curl_error($curl);
        return ['success' => false];
    }

    return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @throws JsonException
 */
function getDataForPeer($peer): array
{
    global $cacheFile;
    if (file_exists($cacheFile)) {
        $peersData = json_decode(file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
    } else {
        $peersData = [];
    }

    return $peersData[$peer['id']] ?? [];
}

/**
 * @throws JsonException
 */
function setDataForPeer($peer): void
{
    global $cacheFile;
    if (file_exists($cacheFile)) {
        $peersData = json_decode(file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
    } else {
        $peersData = [];
    }

    $peersData[$peer['id']] = $peer;
    if (!isset($peersData[$peer['id']]["date"])) {
        $peersData[$peer['id']]["date"] = time();
    }

    file_put_contents($cacheFile, json_encode($peersData, JSON_THROW_ON_ERROR));
}

/**
 * @throws JsonException
 */
function cleanCache(): void
{
    global $cacheFile;
    if (file_exists($cacheFile)) {
        $peersData = json_decode(file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
        foreach ($peersData as $peerId => $peer) {
            if (time() - $peer['date'] >= 86400) {
                unset($peersData[$peerId]);
            }
        }
        file_put_contents($cacheFile, json_encode($peersData, JSON_THROW_ON_ERROR));
    }
}

/**
 * @throws JsonException
 */
function removeCache($peer): void
{
    global $cacheFile;
    if (file_exists($cacheFile)) {
        $peersData = json_decode(file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
        if (isset($peersData[$peer["id"]])) {
            unset($peersData[$peer["id"]]);
        }
        file_put_contents($cacheFile, json_encode($peersData, JSON_THROW_ON_ERROR));
    }
}

function buildQuery($data): string
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

// Fonction pour calculer la moyenne d'un tableau de nombres flottants
function calculateAverage(array $numbers): float
{
    if (empty($numbers)) {
        return 0.0;
    }
    return array_sum($numbers) / count($numbers);
}
