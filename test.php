<?php
// Standalone test script — no Composer, no PHPUnit required.
// Run: php test.php

define('TESTING', true);
require __DIR__ . '/cron.php';

$passed = 0;
$failed = 0;

function assert_equal(mixed $actual, mixed $expected, string $label): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        $actualStr   = var_export($actual, true);
        $expectedStr = var_export($expected, true);
        echo "[FAIL] $label\n";
        echo "       expected: $expectedStr\n";
        echo "       actual:   $actualStr\n";
        $failed++;
    }
}

// ---------------------------------------------------------------------------
// calculateAverage
// ---------------------------------------------------------------------------

assert_equal(calculateAverage([]), 0.0, 'calculateAverage — tableau vide → 0.0');
assert_equal(calculateAverage([100.0]), 100.0, 'calculateAverage — [100.0] → 100.0');
assert_equal(calculateAverage([50.0, 150.0]), 100.0, 'calculateAverage — [50.0, 150.0] → 100.0');

// ---------------------------------------------------------------------------
// buildQuery
// ---------------------------------------------------------------------------

assert_equal(
    buildQuery(['mode' => 'stg']),
    'mode=stg',
    "buildQuery — ['mode' => 'stg'] → \"mode=stg\""
);

assert_equal(
    buildQuery(['mode' => 'list', 'cmd' => ['d.complete=', 'd.is_active=']]),
    'mode=list&cmd=d.complete%3D&cmd=d.is_active%3D',
    "buildQuery — mode=list avec tableau cmd"
);

// ---------------------------------------------------------------------------
// shouldKickPeer — fixtures de base
// ---------------------------------------------------------------------------

$minUploadSpeed   = 100;
$minDownloadSpeed = 100;
$minElapsed       = 300;

$peer = [
    'id'         => 'abc',
    'ip'         => '1.2.3.4',
    'uploaded'   => 1000.0,
    'downloaded' => 500.0,
    'up'         => 50.0,
    'dl'         => 30.0,
];

$cachePeer = [
    'id'               => 'abc',
    'ip'               => '1.2.3.4',
    'uploaded'         => 0.0,
    'downloaded'       => 0.0,
    'first_uploaded'   => 0.0,
    'first_downloaded' => 0.0,
    'ups'              => [50.0, 60.0, 70.0],
    'dls'              => [30.0, 40.0, 50.0],
    'date'             => time() - 400,
];

// Cas 1 : elapsed trop court → false
assert_equal(
    shouldKickPeer($peer, $cachePeer, 200, $minElapsed, false, false, true, false, $minUploadSpeed, $minDownloadSpeed),
    false,
    'shouldKickPeer — elapsed trop court → false'
);

// Cas 2 : seed OK, pas au max → false
$peer2              = $peer;
$peer2['uploaded']  = 5000.0;
$cachePeer2         = $cachePeer;
$cachePeer2['ups']  = [200.0, 300.0];
assert_equal(
    shouldKickPeer($peer2, $cachePeer2, 400, $minElapsed, false, false, true, false, $minUploadSpeed, $minDownloadSpeed),
    false,
    'shouldKickPeer — seed OK, pas au max → false'
);

// Cas 3 : seed au max, vitesse moyenne OK → false
// vitesseMoyenne = (5000 - 0) / 400 = 12.5 ko/s... attend, minUploadSpeed=100
// Pour que ce soit OK il faut vitesseMoyenne >= 100 ET avg(ups) >= 100
// On ajuste : uploaded=50000, elapsed=400 → vMoy=125, ups=[200,300] avg=250 → false
$peer3                      = $peer;
$peer3['uploaded']          = 50000.0;
$cachePeer3                 = $cachePeer;
$cachePeer3['first_uploaded'] = 0.0;
$cachePeer3['ups']          = [200.0, 300.0];
assert_equal(
    shouldKickPeer($peer3, $cachePeer3, 400, $minElapsed, true, false, true, false, $minUploadSpeed, $minDownloadSpeed),
    false,
    'shouldKickPeer — seed au max, vitesse moyenne OK → false'
);

// Cas 4 : seed au max, vitesse moyenne trop lente → true
$peer4                        = $peer;
$peer4['uploaded']            = 10.0;
$cachePeer4                   = $cachePeer;
$cachePeer4['first_uploaded'] = 0.0;
$cachePeer4['ups']            = [5.0, 3.0];
assert_equal(
    shouldKickPeer($peer4, $cachePeer4, 400, $minElapsed, true, false, true, false, $minUploadSpeed, $minDownloadSpeed),
    true,
    'shouldKickPeer — seed au max, vitesse moyenne trop lente → true'
);

// Cas 5 : seed pas au max, zéro upload sur la période → true
$peer5                        = $peer;
$peer5['uploaded']            = 0.0;
$cachePeer5                   = $cachePeer;
$cachePeer5['first_uploaded'] = 0.0;
assert_equal(
    shouldKickPeer($peer5, $cachePeer5, 400, $minElapsed, false, false, true, false, $minUploadSpeed, $minDownloadSpeed),
    true,
    'shouldKickPeer — seed pas au max, zéro upload → true'
);

// Cas 6 : seed pas au max, bon upload → false
$peer6                        = $peer;
$peer6['uploaded']            = 50000.0;
$cachePeer6                   = $cachePeer;
$cachePeer6['first_uploaded'] = 0.0;
assert_equal(
    shouldKickPeer($peer6, $cachePeer6, 400, $minElapsed, false, false, true, false, $minUploadSpeed, $minDownloadSpeed),
    false,
    'shouldKickPeer — seed pas au max, bon upload → false'
);

// Cas 7 : leech au max, vitesse dl trop lente → true
$peer7                          = $peer;
$peer7['downloaded']            = 10.0;
$cachePeer7                     = $cachePeer;
$cachePeer7['downloaded']       = 0.0;
$cachePeer7['first_downloaded'] = 0.0;
$cachePeer7['dls']              = [5.0, 3.0];
assert_equal(
    shouldKickPeer($peer7, $cachePeer7, 400, $minElapsed, true, false, false, true, $minUploadSpeed, $minDownloadSpeed),
    true,
    'shouldKickPeer — leech au max, vitesse dl trop lente → true'
);

// Cas 8 : leech pas au max, zéro download sur la période → true
$peer8                          = $peer;
$peer8['downloaded']            = 0.0;
$cachePeer8                     = $cachePeer;
$cachePeer8['first_downloaded'] = 0.0;
assert_equal(
    shouldKickPeer($peer8, $cachePeer8, 400, $minElapsed, false, false, false, true, $minUploadSpeed, $minDownloadSpeed),
    true,
    'shouldKickPeer — leech pas au max, zéro download → true'
);

// Cas 9 : leech pas au max, bon download → false
$peer9                          = $peer;
$peer9['downloaded']            = 50000.0;
$cachePeer9                     = $cachePeer;
$cachePeer9['first_downloaded'] = 0.0;
assert_equal(
    shouldKickPeer($peer9, $cachePeer9, 400, $minElapsed, false, false, false, true, $minUploadSpeed, $minDownloadSpeed),
    false,
    'shouldKickPeer — leech pas au max, bon download → false'
);

// ---------------------------------------------------------------------------
// Bilan
// ---------------------------------------------------------------------------

$total = $passed + $failed;
echo "\n$passed/$total tests passed\n";

if ($failed > 0) {
    exit(1);
}
