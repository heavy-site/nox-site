<?php
require_once __DIR__ . '/_venue.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$split = nox_split_events();

echo json_encode([
    'venue'    => nox_venue(),
    'headline' => nox_headline(),
    'rent'     => nox_included(),
    'media'    => nox_media(),
    'upcoming' => $split['upcoming'],
    'past'     => $split['past'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
