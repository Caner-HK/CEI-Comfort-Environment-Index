<?php
require '../../src/cei.php';
$data = json_decode(file_get_contents(__DIR__ . '/../sample-weather.json'), true);
$latitude = 34.05;
$month = 11;
$result = computeCEI("metric", $data, $latitude, $month);
echo json_encode($result, JSON_PRETTY_PRINT);
