<?php
/**
 * CEI - Comfort Environment Index
 * 环境舒适度指数
 *
 * Version 2.0.0
 * Build Date 2025/11/17
 * 
 * Copyright © Caner HK
 * License: Apache License 2.0
 *
 * This file implements the CEI core algorithm:
 * - Input: weather & air quality data (OpenWeather-style)
 * - Output: a 0–100 comfort index + component scores + level description
 *
 * Design Highlights:
 * - Unifies units to: temperature in °C, wind speed in m/s
 * - Uses climate zone & month to adjust comfort temperature and seasonal factor
 * - Combines Heat Index (hot discomfort) and Wind Chill (cold & wind discomfort)
 * - Integrates weather condition (OpenWeather weather id) into thermal comfort
 * - Air quality scoring based on pollutant-specific thresholds (international style)
 * - Dynamic weights for heat / air / UV / pressure with wind & pollution influence
 */

/**
 * Main entry: compute CEI.
 *
 * @param string $unit      'metric' (°C, m/s), 'imperial' (°F, mph), 'standard' (K, m/s)
 * @param array  $data      Associative array with keys:
 *                          temp, humidity, wind_speed,
 *                          pm2_5, pm10, o3, co, no2, so2,
 *                          uvi, pressure
 * @param float  $latitude  Location latitude (for climate zone)
 * @param int    $month     1–12 (current month)
 * @param int|null $weatherId OpenWeather weather[0].id, e.g. 800, 803, 502...
 *
 * @return array [
 *   'cei' => int 0–100,
 *   'level' => string,       // e.g. "CEI Level 3 – Cool but acceptable"
 *   'components' => [
 *       'heatScore'  => int,
 *       'airScore'   => int,
 *       'uvScore'    => int,
 *       'pressScore' => int,
 *   ]
 * ]
 */
function computeCEI($unit, $data, $latitude, $month, $weatherId = null) {
    // ---- 1. Basic validation ----
    if (!in_array($unit, ['imperial', 'metric', 'standard'])) {
        return ['error' => 'Invalid unit type'];
    }

    $required = [
        'temp', 'humidity', 'wind_speed',
        'pm2_5', 'pm10', 'o3', 'co', 'no2', 'so2',
        'uvi', 'pressure'
    ];

    foreach ($required as $field) {
        if (!isset($data[$field]) || !is_numeric($data[$field])) {
            return ['error' => "Missing or invalid field: $field"];
        }
    }

    // If not explicitly passed, try to read from $data['weather_id']
    if ($weatherId === null && isset($data['weather_id'])) {
        $weatherId = (int)$data['weather_id'];
    }
    // Fallback: assume clear sky if still null
    if ($weatherId === null) {
        $weatherId = 800;
    }

    // ---- 2. Extract raw inputs ----
    $T     = $data['temp'];
    $RH    = $data['humidity'];
    $wind  = $data['wind_speed'];
    $pm25  = $data['pm2_5'];
    $pm10  = $data['pm10'];
    $o3    = $data['o3'];
    $co    = $data['co'];
    $no2   = $data['no2'];
    $so2   = $data['so2'];
    $uvi   = $data['uvi'];
    $press = $data['pressure'];

    // ---- 3. Unit normalization: °C & m/s ----
    if ($unit === 'imperial') {
        // °F -> °C
        $T = ($T - 32) * 5 / 9;
        // mph -> m/s
        $wind = $wind / 2.237;
    } elseif ($unit === 'standard') {
        // K -> °C
        $T = $T - 273.15;
        // wind already in m/s
    }
    // metric: already °C & m/s

    // ---- 4. Climate context: zone + factor + comfort temperature ----
    $climateContext    = getClimateContext($latitude, $month);
    $climateAdjustment = $climateContext['factor'];      // numeric factor
    $comfortTemp       = $climateContext['comfortTemp']; // °C

    // ---- 5. Dynamic weights (heat / air / UV / pressure) ----
    $weights = dynamicWeightAdjustment($T, $pm25, $uvi, $wind);

    // ---- 6. Thermal comfort (with heat index, wind chill & weather penalty) ----
    $heatIndex = calculateHeatIndex($T, $RH);
    $heatScore = calculateThermalComfort($T, $RH, $wind, $heatIndex, $weatherId, $comfortTemp);

    // ---- 7. Air quality, UV, pressure scores ----
    $airScore   = calculateAirQualityScoreInternational($pm25, $pm10, $o3, $co, $no2, $so2);
    $uvScore    = calculateUVScore($uvi);
    $pressScore = calculatePressureScore($press);

    // ---- 8. CEI aggregation ----
    $cei = $weights['heat']  * $heatScore
         + $weights['air']   * $airScore
         + $weights['uv']    * $uvScore
         + $weights['press'] * $pressScore;

    // Apply climate factor
    $cei *= $climateAdjustment;

    // Clamp to [0, 100]
    $cei = max(0, min(100, $cei));

    return [
        'cei'    => round($cei),
        'level'  => getCEILevel($cei),
        'components' => [
            'heatScore'  => round($heatScore),
            'airScore'   => round($airScore),
            'uvScore'    => round($uvScore),
            'pressScore' => round($pressScore)
        ]
    ];
}

/**
 * Map CEI score (0–100) to a human-readable level.
 */
function getCEILevel($cei) {
    $cei = (float)$cei;

    if ($cei >= 90) {
        return 'CEI Level 1 – Excellent';
    } elseif ($cei >= 75) {
        return 'CEI Level 2 – Comfortable';
    } elseif ($cei >= 60) {
        return 'CEI Level 3 – Acceptable';
    } elseif ($cei >= 45) {
        return 'CEI Level 4 – Uncomfortable';
    } elseif ($cei >= 30) {
        return 'CEI Level 5 – Poor';
      } else {
        return 'Severe';
    }
}

/**
 * Climate context based on latitude & month.
 * Returns:
 *  - zone: tropical / temperate / polar
 *  - factor: seasonal adjustment factor (from adjustForClimate)
 *  - comfortTemp: climate-specific comfortable temperature (°C)
 */
function getClimateContext($latitude, $month) {
    // Climate zone
    if ($latitude < 23.5) {
        $climateZone = 'tropical';
    } elseif ($latitude > 66.5) {
        $climateZone = 'polar';
    } else {
        $climateZone = 'temperate';
    }

    // Seasonal factor (reuse your previous logic)
    $factor = adjustForClimate($latitude, $month);

    // Baseline comfort temperature (°C)
    switch ($climateZone) {
        case 'tropical':
            $comfortTemp = 25; // people in tropical areas tend to accept warmer temps
            break;
        case 'polar':
            $comfortTemp = 20; // colder climates, more used to lower temperatures
            break;
        case 'temperate':
        default:
            $comfortTemp = 22; // a middle ground
            break;
    }

    // Small seasonal tweak on comfort temperature
    if (in_array($month, [6, 7, 8], true)) {
        $comfortTemp += 1;  // in summer, slightly higher temp still feels OK
    } elseif (in_array($month, [12, 1, 2], true)) {
        $comfortTemp -= 1;  // in winter, slightly cooler temp is acceptable (with clothing)
    }

    return [
        'zone'        => $climateZone,
        'factor'      => $factor,
        'comfortTemp' => $comfortTemp
    ];
}

/**
 * Seasonal/climate factor. This keeps your original simple design.
 */
function adjustForClimate($latitude, $month) {
    $climateZone = 'temperate';
    if ($latitude < 23.5) {
        $climateZone = 'tropical';
    } elseif ($latitude > 66.5) {
        $climateZone = 'polar';
    }

    $seasonFactor = 1.0;

    // Summer: June, July, August (Northern hemisphere assumption)
    if (in_array($month, [6, 7, 8], true)) {
        if ($climateZone === 'tropical') {
            $seasonFactor = 1.1;
        } elseif ($climateZone === 'polar') {
            $seasonFactor = 0.9;
        }
    }
    // Winter: December, January, February
    elseif (in_array($month, [12, 1, 2], true)) {
        if ($climateZone === 'tropical') {
            $seasonFactor = 0.9;
        } elseif ($climateZone === 'polar') {
            $seasonFactor = 1.1;
        }
    }

    return $seasonFactor;
}

/**
 * Dynamic weights for heat / air / UV / pressure:
 * - Considers temperature, PM2.5 pollution, UV index and wind speed.
 */
function dynamicWeightAdjustment($T, $pm25, $uvi, $wind) {
    $weights = [
        'heat'  => 0.4,
        'air'   => 0.4,
        'uv'    => 0.1,
        'press' => 0.1
    ];

    // Temperature influence on heat comfort weight
    if ($T > 30) {
        $weights['heat'] = 0.5;
    } elseif ($T < 15) {
        $weights['heat'] = 0.6;
    }

    // Strong wind makes thermal comfort more critical
    if ($wind > 8) {
        $weights['heat'] += 0.05;
    }
    if ($wind > 12) {
        $weights['heat'] += 0.05;
    }

    // High PM2.5: increase air quality importance
    if ($pm25 > 35) {
        $weights['air'] = 0.5;
    }

    // Strong UV: increase UV importance
    if ($uvi > 8) {
        $weights['uv'] = 0.2;
    }

    // Minimum weight floor
    $minWeight = 0.05;
    foreach ($weights as $key => $value) {
        if ($value < $minWeight) {
            $weights[$key] = $minWeight;
        }
    }

    // Normalize to sum 1
    $sum = array_sum($weights);
    foreach ($weights as $key => &$value) {
        $value = $value / $sum;
    }
    unset($value);

    return $weights;
}

/**
 * Heat Index (Steadman-like formula, adapted to °C & %RH).
 * For T < 20°C, Heat Index is not meaningful; returns T directly.
 */
function calculateHeatIndex($T, $RH) {
    if ($T < 20) {
        return $T;
    }

    $c1 = -8.78469475556;
    $c2 = 1.61139411;
    $c3 = 2.33854883889;
    $c4 = -0.14611605;
    $c5 = -0.012308094;
    $c6 = -0.0164248277778;
    $c7 = 0.002211732;
    $c8 = 0.00072546;
    $c9 = -0.000003582;

    return $c1
         + $c2 * $T
         + $c3 * $RH
         + $c4 * $T * $RH
         + $c5 * $T * $T
         + $c6 * $RH * $RH
         + $c7 * $T * $T * $RH
         + $c8 * $T * $RH * $RH
         + $c9 * $T * $T * $RH * $RH;
}

/**
 * Wind Chill (Canada/US style formula).
 * Input: T in °C, wind in m/s
 * Used only when T < 10°C and wind > 1.3 m/s.
 */
function calculateWindChill($T, $wind) {
    if ($T >= 10 || $wind <= 1.3) {
        return $T;
    }

    // Convert m/s to km/h
    $wind_kmh = $wind * 3.6;

    return 13.12
         + 0.6215 * $T
         - 11.37 * pow($wind_kmh, 0.16)
         + 0.3965 * $T * pow($wind_kmh, 0.16);
}

/**
 * Weather-based discomfort penalty using OpenWeather weather id.
 * Returns penalty in [0, 25], where higher means more uncomfortable.
 */
function getWeatherDiscomfortPenalty($weatherId) {
    $penalty = 0;

    if ($weatherId >= 200 && $weatherId < 300) {
        // Thunderstorm
        if (in_array($weatherId, [212, 221, 232], true)) {
            $penalty = 20;
        } else {
            $penalty = 15;
        }
    } elseif ($weatherId >= 300 && $weatherId < 400) {
        // Drizzle
        $penalty = 6;
    } elseif ($weatherId >= 500 && $weatherId < 600) {
        // Rain
        if (in_array($weatherId, [500, 520], true)) {
            $penalty = 8;   // light rain
        } elseif (in_array($weatherId, [501, 521, 531], true)) {
            $penalty = 12;  // moderate rain / shower rain
        } elseif (in_array($weatherId, [502, 503, 504, 522], true)) {
            $penalty = 16;  // heavy rain
        } elseif ($weatherId === 511) {
            $penalty = 20;  // freezing rain
        } else {
            $penalty = 12;
        }
    } elseif ($weatherId >= 600 && $weatherId < 700) {
        // Snow
        if (in_array($weatherId, [600, 615, 620], true)) {
            $penalty = 12;  // light snow
        } elseif (in_array($weatherId, [601, 612, 621], true)) {
            $penalty = 16;  // moderate snow
        } else {
            $penalty = 20;  // heavy snow / sleet mix etc.
        }
    } elseif ($weatherId >= 700 && $weatherId < 800) {
        // Atmosphere: mist, smoke, haze, fog, dust, sand, ash, squalls, tornado
        if (in_array($weatherId, [701, 711, 721, 741], true)) {
            $penalty = 10;  // mist, smoke, haze, fog
        } elseif (in_array($weatherId, [731, 751, 761, 762, 771], true)) {
            $penalty = 18;  // sand, dust, ash, squalls
        } elseif ($weatherId === 781) {
            $penalty = 25;  // tornado
        } else {
            $penalty = 12;
        }
    } elseif ($weatherId === 800) {
        // Clear sky
        $penalty = 0;
    } elseif ($weatherId >= 801 && $weatherId <= 804) {
        // Clouds
        if ($weatherId === 801) {
            $penalty = 1;  // few clouds
        } elseif ($weatherId === 802) {
            $penalty = 2;  // scattered clouds
        } elseif ($weatherId === 803) {
            $penalty = 4;  // broken clouds
        } elseif ($weatherId === 804) {
            $penalty = 6;  // overcast
        }
    }

    return $penalty;
}

/**
 * Thermal comfort combining:
 * - effective temperature (Heat Index / Wind Chill)
 * - humidity comfort
 * - wind discomfort
 * - additional hot-discomfort from Heat Index
 * - weather-based penalty (rain, snow, storms, etc.)
 *
 * @return float 10–100
 */
function calculateThermalComfort($T, $RH, $wind, $heatIndex, $weatherId, $comfortTemp) {
    // Effective temperature: Heat Index in warm conditions, Wind Chill in cold
    if ($T >= 20) {
        $effectiveTemp = $heatIndex;
    } else {
        $effectiveTemp = calculateWindChill($T, $wind);
    }

    // Temperature comfort around climate-specific comfortTemp
    $tempComfort = 100 - min(90, abs($effectiveTemp - $comfortTemp) * 4);

    // Humidity comfort around 50%
    $humidityComfort = 100 - min(80, abs($RH - 50) * 1.6);

    // Wind comfort: strong wind is penalized heavily
    if ($wind <= 3) {
        $windComfort = 100;
    } else {
        $windComfort = max(20, 100 - ($wind - 3) * 10);
    }

    // Heat Index comfort: high HI gives additional hot discomfort
    if ($heatIndex <= 27) {
        $heatComfort = 100;
    } else {
        $heatComfort = max(20, 100 - ($heatIndex - 27) * 10);
    }

    // Base thermal comfort score (weights can be fine-tuned later)
    $comfortScore = 0.3 * $tempComfort
                  + 0.3 * $humidityComfort
                  + 0.2 * $windComfort
                  + 0.2 * $heatComfort;

    // Weather condition penalty (rain, snow, storms, fog, dust, etc.)
    $weatherPenalty = getWeatherDiscomfortPenalty($weatherId);
    $comfortScore  -= $weatherPenalty;

    // Clamp to [10, 100] to avoid extreme distortion
    return max(10, min(100, $comfortScore));
}

/**
 * Air quality comfort score based on multiple pollutants.
 * Returns the minimum (worst) score among all pollutants.
 */
function calculateAirQualityScoreInternational($pm25, $pm10, $o3, $co, $no2, $so2) {
    $scores = [];

    // PM2.5
    $scores['pm25'] = calculatePollutantScore($pm25, [
        [5, 100],
        [15, 80],
        [25, 60],
        [35, 40],
        [50, 20]
    ]);

    // PM10
    $scores['pm10'] = calculatePollutantScore($pm10, [
        [15, 100],
        [45, 80],
        [60, 60],
        [90, 40],
        [120, 20]
    ]);

    // O3 (µg/m³)
    $scores['o3'] = calculatePollutantScore($o3, [
        [60, 100],
        [100, 80],
        [130, 60],
        [160, 40],
        [200, 20]
    ]);

    // CO: convert µg/m³ to mg/m³
    $co_mg = $co / 1000.0;
    $scores['co'] = calculatePollutantScore($co_mg, [
        [1, 100],
        [4, 80],
        [7, 60],
        [10, 40],
        [15, 20]
    ]);

    // NO2
    $scores['no2'] = calculatePollutantScore($no2, [
        [10, 100],
        [25, 80],
        [40, 60],
        [60, 40],
        [80, 20]
    ]);

    // SO2
    $scores['so2'] = calculatePollutantScore($so2, [
        [20, 100],
        [40, 80],
        [60, 60],
        [80, 40],
        [100, 20]
    ]);

    // Overall air comfort is limited by the worst pollutant
    return min($scores);
}

/**
 * Generic pollutant scoring helper:
 * $thresholds = [
 *   [concentration_limit, score],
 *   ...
 * ]
 * If above highest threshold, returns 10 by default.
 */
function calculatePollutantScore($concentration, $thresholds) {
    foreach ($thresholds as $threshold) {
        if ($concentration <= $threshold[0]) {
            return $threshold[1];
        }
    }
    return 10;
}

/**
 * UV comfort score based on UV index.
 */
function calculateUVScore($uvi) {
    if ($uvi <= 2) {
        return 100;
    }
    if ($uvi <= 5) {
        return 85;
    }
    if ($uvi <= 7) {
        return 70;
    }
    if ($uvi <= 10) {
        return 55;
    }
    return 40;
}

/**
 * Pressure comfort score around standard pressure (1013.25 hPa).
 */
function calculatePressureScore($pressure) {
    $standard  = 1013.25;
    $deviation = abs($pressure - $standard);

    if ($deviation <= 5)  return 100;
    if ($deviation <= 10) return 90;
    if ($deviation <= 15) return 80;
    if ($deviation <= 20) return 70;
    if ($deviation <= 25) return 60;

    // Beyond 25 hPa difference, decay quickly but keep a floor at 40
    return max(40, 100 - $deviation * 2);
}
