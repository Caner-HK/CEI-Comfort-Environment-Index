<?php
declare(strict_types=1);

require '../../src/cei.php';

// Get weather data json file
$data = json_decode(
    file_get_contents(__DIR__ . '/../sample-weather.json'),
    true
);

if (!is_array($data)) {
    throw new RuntimeException('Invalid sample-weather.json');
}

// Sample location
$latitude = 34.05;
$month    = 11;

$weatherId = null;
if (isset($data['weather'][0]['id']) && is_numeric($data['weather'][0]['id'])) {
    $weatherId = (int)$data['weather'][0]['id'];
}

$ceiInput = [
    // Required
    'temp'       => $data['temp']       ?? null,
    'humidity'   => $data['humidity']   ?? null,
    'wind_speed' => $data['wind_speed'] ?? null,
    'pm2_5'      => $data['pm2_5']      ?? null,
    'pm10'       => $data['pm10']       ?? null,
    'o3'         => $data['o3']         ?? null,
    'co'         => $data['co']         ?? null,
    'no2'        => $data['no2']        ?? null,
    'so2'        => $data['so2']        ?? null,
    'uvi'        => $data['uvi']        ?? 0.0,
    'pressure'   => $data['pressure']   ?? null,

    // Optional
    'wind_gust'  => $data['wind_gust']  ?? null,
    'dew_point'  => $data['dew_point']  ?? null,
    'feels_like' => $data['feels_like'] ?? null,
    'alerts'     => $data['alerts']     ?? [],

    'weather_id' => $weatherId,
];

// Calling algorithm
$result = computeCEI('metric', $ceiInput, $latitude, $month, $weatherId);

// Output
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
