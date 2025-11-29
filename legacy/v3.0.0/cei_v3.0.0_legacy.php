<?php

/**
 * CEI v3.0.0 - Comfort Environment Index (legacy)
 * --------------------------------------
 * "Risk-aware / Extreme Conditions Update"
 * 
 * !!! This version has been superseded by CEI v3.1.0 !!!
 *
 * This version separates:
 *   - a comfort layer (thermal, air quality, UV, pressure)
 *   - a safety/risk cap layer (extreme temperature, severe weather, official alerts)
 *
 * Final CEI is:
 *   CEI = min(ComfortCEI, RiskCap)
 *
 * All scores are normalized to [0, 100].
 *
 * Input data is designed to be compatible with OpenWeather / QWeather style fields,
 * but this file itself is provider-agnostic as long as the caller prepares $data.
 */

/**
 * Compute CEI for a given point in time.
 *
 * @param string $unit      'metric' (°C, m/s), 'imperial' (°F, mph), 'standard' (K, m/s)
 * @param array  $data      Associative array with keys (raw input):
 *                          - temp       : float, air temperature
 *                          - humidity   : float, relative humidity (%)
 *                          - wind_speed : float, wind speed
 *                          - pm2_5, pm10, o3, co, no2, so2 : float, pollutant concentrations
 *                          - uvi        : float, UV index
 *                          - pressure   : float, surface pressure (hPa)
 *                          Optional extras:
 *                          - wind_gust  : float|null, gust speed
 *                          - dew_point  : float|null, dew point temperature
 *                          - feels_like : float|null, "feels like" temperature
 *                          - weather_id : int|null, OpenWeather condition id
 *                          - alerts     : array|null, pre-normalized alert objects
 * @param float $latitude   Location latitude (for climate zone adjustments)
 * @param int   $month      1–12 (UTC month of the current time)
 * @param int|null $weatherId Optional OpenWeather weather id; if null, read from data['weather_id'] or fallback 800.
 *
 * @return array {
 *   cei: int 0–100,
 *   level: string,
 *   components: {
 *      heat: int,
 *      air: int,
 *      uv: int,
 *      pressure: int,
 *      risk: int
 *   },
 *   weights: {
 *      heat: float,
 *      air: float,
 *      uv: float,
 *      pressure: float
 *   },
 *   detail: {
 *      comfort_cei: float,
 *      risk_cap: float,
 *      main_effect: string 'heat'|'air'|'uv'|'pressure'|'risk',
 *      climate: array{zone:string,factor:float,comfortTemp:float},
 *      thermal: {
 *         effective_temp: float,
 *         heat_index: float,
 *         wind_chill: float
 *      },
 *      risk: {
 *         overall: int,
 *         from_temp: int,
 *         from_weather: int,
 *         from_alerts: int,
 *         flags: string[]
 *      }
 *   }
 * }
 */
function computeCEI($unit, $data, $latitude, $month, $weatherId = null)
{
    // --- 1. Basic validation of unit and required fields --------------------
    if (!in_array($unit, ['imperial', 'metric', 'standard'], true)) {
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

    // --- 2. Extract raw inputs ---------------------------------------------
    $T       = (float)$data['temp'];
    $RH      = (float)$data['humidity'];
    $wind    = (float)$data['wind_speed'];
    $pm25    = (float)$data['pm2_5'];
    $pm10    = (float)$data['pm10'];
    $o3      = (float)$data['o3'];
    $co      = (float)$data['co'];
    $no2     = (float)$data['no2'];
    $so2     = (float)$data['so2'];
    $uvi     = (float)$data['uvi'];
    $press   = (float)$data['pressure'];

    // Optional extra fields
    $windGust  = (isset($data['wind_gust'])  && is_numeric($data['wind_gust']))  ? (float)$data['wind_gust']  : null;
    $dewPoint  = (isset($data['dew_point'])  && is_numeric($data['dew_point']))  ? (float)$data['dew_point']  : null;
    $feelsLike = (isset($data['feels_like']) && is_numeric($data['feels_like'])) ? (float)$data['feels_like'] : null;

    // --- 3. Weather id & alerts --------------------------------------------
    if ($weatherId === null && isset($data['weather_id']) && is_numeric($data['weather_id'])) {
        $weatherId = (int)$data['weather_id'];
    }
    // Fallback: clear sky
    if ($weatherId === null) {
        $weatherId = 800;
    } else {
        $weatherId = (int)$weatherId;
    }

    $alerts = [];
    if (isset($data['alerts']) && is_array($data['alerts'])) {
        $alerts = $data['alerts']; // already normalized by caller
    }

    // --- 4. Unit normalization: convert to °C & m/s ------------------------
    if ($unit === 'imperial') {
        // °F -> °C
        $T = ($T - 32) * 5 / 9;
        if ($dewPoint !== null)  $dewPoint  = ($dewPoint  - 32) * 5 / 9;
        if ($feelsLike !== null) $feelsLike = ($feelsLike - 32) * 5 / 9;

        // mph -> m/s
        $wind = $wind / 2.237;
        if ($windGust !== null)  $windGust  = $windGust / 2.237;
    } elseif ($unit === 'standard') {
        // K -> °C
        $T = $T - 273.15;
        if ($dewPoint !== null)  $dewPoint  = $dewPoint - 273.15;
        if ($feelsLike !== null) $feelsLike = $feelsLike - 273.15;
        // wind already in m/s
    }
    // metric: already in °C & m/s

    // --- 5. Climate context (zone, factor, comfort temperature) ------------
    $climateContext    = getClimateContext($latitude, $month);
    $climateAdjustment = $climateContext['factor'];
    $comfortTemp       = $climateContext['comfortTemp'];

    // --- 6. Dynamic weights for comfort components -------------------------
    $weights = dynamicWeightAdjustment($T, $pm25, $uvi, $wind);

    // --- 7. Thermal comfort scores -----------------------------------------
    $heatIndex = calculateHeatIndex($T, $RH);
    $heatScore = calculateThermalComfort($T, $RH, $wind, $heatIndex, $weatherId, $comfortTemp, $dewPoint);

    // Effective temperature for detail output
    $windChill     = calculateWindChill($T, max($wind, $windGust ?? $wind));
    $effectiveTemp = ($T >= 20) ? $heatIndex : $windChill;

    // --- 8. Other comfort components ---------------------------------------
    $airScore   = calculateAirQualityScoreInternational($pm25, $pm10, $o3, $co, $no2, $so2);
    $uvScore    = calculateUVScore($uvi);
    $pressScore = calculatePressureScore($press);

    // Combine into comfort-only CEI
    $ceiComfort = $weights['heat']  * $heatScore
                + $weights['air']   * $airScore
                + $weights['uv']    * $uvScore
                + $weights['press'] * $pressScore;

    // Apply seasonal/climate factor
    $ceiComfort *= $climateAdjustment;
    $ceiComfort  = max(0, min(100, $ceiComfort));

    // --- 9. Risk layer (temperature, weather phenomena, alerts) ------------
    $tempRisk    = computeTemperatureRiskScore($T, $RH, $wind, $windGust, $dewPoint, $heatIndex);
    $weatherRisk = computeWeatherRiskScore($weatherId, $windGust);
    $alertsRisk  = computeAlertsRiskScore($alerts);

    // Overall risk: currently max of three dimensions
    $riskScore = max($tempRisk['score'], $weatherRisk['score'], $alertsRisk['score']);
    $riskScore = max(0, min(100, $riskScore));

    // Risk cap: upper bound of CEI allowed by safety
    $riskCap = 100 - $riskScore;
    $riskCap = max(0, min(100, $riskCap));

    // --- 10. Final CEI and diagnostics -------------------------------------
    $ceiFinal = min($ceiComfort, $riskCap);
    $ceiFinal = max(0, min(100, $ceiFinal));

    $componentScores = [
        'heat'     => $heatScore,
        'air'      => $airScore,
        'uv'       => $uvScore,
        'pressure' => $pressScore
    ];

    // Main driver: if riskCap is binding, main_effect = 'risk',
    // otherwise the lowest comfort component is considered the "short board".
    $minComponent = array_keys($componentScores, min($componentScores))[0];
    $mainEffect   = ($riskCap < $ceiComfort) ? 'risk' : $minComponent;

    // Merge risk flags from all sub-modules
    $detailFlags = array_values(array_unique(array_merge(
        $tempRisk['flags'],
        $weatherRisk['flags'],
        $alertsRisk['flags']
    )));

    return [
        'cei'   => round($ceiFinal),
        'level' => getCEILevel($ceiFinal),

        'components' => [
            'heat'     => round($heatScore),
            'air'      => round($airScore),
            'uv'       => round($uvScore),
            'pressure' => round($pressScore),
            'risk'     => round($riskScore)
        ],

        'weights' => [
            'heat'     => $weights['heat'],
            'air'      => $weights['air'],
            'uv'       => $weights['uv'],
            'pressure' => $weights['press']
        ],

        'detail' => [
            'comfort_cei' => round($ceiComfort, 1),
            'risk_cap'    => round($riskCap, 1),
            'main_effect' => $mainEffect,

            'climate' => $climateContext,

            'thermal' => [
                'effective_temp' => round($effectiveTemp, 1),
                'heat_index'     => round($heatIndex, 1),
                'wind_chill'     => round($windChill, 1)
            ],

            'risk' => [
                'overall'      => round($riskScore),
                'from_temp'    => round($tempRisk['score']),
                'from_weather' => round($weatherRisk['score']),
                'from_alerts'  => round($alertsRisk['score']),
                'flags'        => $detailFlags
            ]
        ]
    ];
}

/**
 * Map CEI numeric value to a qualitative level string.
 *
 * NOTE: these boundaries are heuristic and can be calibrated in future versions.
 *
 * @param float|int $cei
 * @return string
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
 * Derive climate context from latitude and month:
 *  - climate zone (equatorial / tropical / subtropical / temperate / cold_temperate / polar)
 *  - seasonal factor
 *  - baseline comfort temperature in °C
 *
 * @param float $latitude
 * @param int   $month
 * @return array
 */
function getClimateContext($latitude, $month) {
    $absLat = abs($latitude);

    if ($absLat < 10) {
        $climateZone = 'equatorial';
        $comfortTemp = 26;
    } elseif ($absLat < 23.5) {
        $climateZone = 'tropical';
        $comfortTemp = 25;
    } elseif ($absLat < 35) {
        $climateZone = 'subtropical';
        $comfortTemp = 24;
    } elseif ($absLat < 55) {
        $climateZone = 'temperate';
        $comfortTemp = 22;
    } elseif ($absLat < 66.5) {
        $climateZone = 'cold_temperate';
        $comfortTemp = 20;
    } else {
        $climateZone = 'polar';
        $comfortTemp = 18;
    }

    // Simple seasonal tweak: slightly warmer preference in summer, cooler in winter
    if (in_array($month, [6, 7, 8], true)) {
        $comfortTemp += 1;
    } elseif (in_array($month, [12, 1, 2], true)) {
        $comfortTemp -= 1;
    }

    $factor = adjustForClimate($latitude, $month);

    return [
        'zone'        => $climateZone,
        'factor'      => $factor,
        'comfortTemp' => $comfortTemp
    ];
}

/**
 * Seasonal/climate factor that lightly scales the comfort CEI.
 *
 * This is intentionally simple: it should be calibrated with data in future versions.
 *
 * @param float $latitude
 * @param int   $month
 * @return float
 */
function adjustForClimate($latitude, $month) {
    $climateZone = 'temperate';
    if ($latitude < 23.5) {
        $climateZone = 'tropical';
    } elseif ($latitude > 66.5) {
        $climateZone = 'polar';
    }

    $seasonFactor = 1.0;

    // Northern hemisphere summer
    if (in_array($month, [6, 7, 8], true)) {
        if ($climateZone === 'tropical') {
            $seasonFactor = 1.1;
        } elseif ($climateZone === 'polar') {
            $seasonFactor = 0.9;
        }
    }
    // Northern hemisphere winter
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
 * Dynamic weights for comfort components (heat / air / UV / pressure).
 *
 * The weights are adjusted based on:
 *  - temperature (cold or hot conditions -> heat matters more)
 *  - PM2.5 (polluted -> air quality matters more)
 *  - UV (strong UV -> UV matters more)
 *  - wind (strong wind -> thermal comfort more important)
 *
 * @param float $T
 * @param float $pm25
 * @param float $uvi
 * @param float $wind
 * @return array
 */
function dynamicWeightAdjustment($T, $pm25, $uvi, $wind) {
    $weights = [
        'heat'  => 0.4,
        'air'   => 0.4,
        'uv'    => 0.1,
        'press' => 0.1
    ];

    // Cold or hot conditions: increase heat importance
    if ($T > 30) {
        $weights['heat'] = 0.5;
    } elseif ($T < 15) {
        $weights['heat'] = 0.6;
    }

    // Strong wind: thermal comfort becomes more critical
    if ($wind > 8) {
        $weights['heat'] += 0.05;
    }
    if ($wind > 12) {
        $weights['heat'] += 0.05;
    }

    // High PM2.5: air quality becomes more important
    if ($pm25 > 35) {
        $weights['air'] = 0.5;
    }

    // Strong UV
    if ($uvi > 8) {
        $weights['uv'] = 0.2;
    }

    // Floor weights and normalize to sum 1.0
    $minWeight = 0.05;
    foreach ($weights as $key => $value) {
        if ($value < $minWeight) {
            $weights[$key] = $minWeight;
        }
    }

    $sum = array_sum($weights);
    foreach ($weights as $key => &$value) {
        $value = $value / $sum;
    }
    unset($value);

    return $weights;
}

/**
 * Heat Index (Steadman-like) in °C, using T(°C) and RH(%).
 * For T < 20°C the heat index is not meaningful; returns T.
 *
 * @param float $T
 * @param float $RH
 * @return float
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
 * Wind Chill calculation using the Canadian/US standard formula.
 *
 * Input:
 *  - T in °C
 *  - wind in m/s
 * Only applies when T < 10°C and wind > 1.3 m/s.
 *
 * @param float $T
 * @param float $wind
 * @return float
 */
function calculateWindChill($T, $wind) {
    if ($T >= 10 || $wind <= 1.3) {
        return $T;
    }

    $wind_kmh = $wind * 3.6;

    return 13.12
         + 0.6215 * $T
         - 11.37 * pow($wind_kmh, 0.16)
         + 0.3965 * $T * pow($wind_kmh, 0.16);
}

/**
 * Temperature comfort curve as a function of deviation from comfort temperature.
 *
 * The curve is piecewise:
 *  - within ±2°C: full score
 *  - moderate deviations: gradually reduced
 *  - large deviations: floor at low values
 *
 * @param float $effectiveTemp
 * @param float $comfortTemp
 * @return float
 */
function thermalComfortCurve($effectiveTemp, $comfortTemp)
{
    $delta    = $effectiveTemp - $comfortTemp;
    $absDelta = abs($delta);

    if ($absDelta <= 2) {
        return 100;
    } elseif ($absDelta <= 5) {
        return max(90, 100 - $absDelta * 2);
    } elseif ($absDelta <= 15) {
        return max(60, 90 - ($absDelta - 5) * 3);
    } elseif ($absDelta <= 25) {
        return max(30, 60 - ($absDelta - 15) * 3);
    } else {
        return max(5, 30 - ($absDelta - 25) * 2);
    }
}

/**
 * Thermal comfort score combining:
 *  - temperature comfort around climate-specific comfortTemp
 *  - humidity comfort (around RH=50%)
 *  - wind comfort (strong wind is uncomfortable)
 *  - extra hot discomfort from high Heat Index and high dew point
 *  - weather-based penalty (rain, snow, storms, etc.)
 *
 * Returns 0–100.
 *
 * @param float      $T
 * @param float      $RH
 * @param float      $wind
 * @param float      $heatIndex
 * @param int        $weatherId
 * @param float      $comfortTemp
 * @param float|null $dewPoint
 * @return float
 */
function calculateThermalComfort($T, $RH, $wind, $heatIndex, $weatherId, $comfortTemp, $dewPoint = null) {
    // Effective temperature: Heat Index in warm conditions, Wind Chill in cold conditions
    if ($T >= 20) {
        $effectiveTemp = $heatIndex;
    } else {
        $effectiveTemp = calculateWindChill($T, $wind);
    }

    $tempComfort = thermalComfortCurve($effectiveTemp, $comfortTemp);

    // Humidity comfort centered near 50% RH
    $humidityComfort = 100 - min(60, abs($RH - 50) * 1.2);

    // Extra penalty for hot and humid conditions (high dew point)
    if ($dewPoint !== null && $T >= 20 && $dewPoint >= 24) {
        $extra = min(15, ($dewPoint - 23) * 1.5);
        $humidityComfort = max(20, $humidityComfort - $extra);
    }

    // Wind comfort: calm to light wind is fine; strong wind reduces comfort
    if ($wind <= 3) {
        $windComfort = 100;
    } else {
        $windComfort = max(20, 100 - ($wind - 3) * 10);
    }

    // Additional hot discomfort when Heat Index is high
    if ($heatIndex <= 27) {
        $heatComfort = 100;
    } else {
        $heatComfort = max(20, 100 - ($heatIndex - 27) * 8);
    }

    // Aggregate thermal comfort score
    $comfortScore = 0.5 * $tempComfort
                  + 0.25 * $humidityComfort
                  + 0.15 * $windComfort
                  + 0.10 * $heatComfort;

    // Weather condition penalty (rain, snow, storms, fog, dust, etc.)
    $weatherPenalty = getWeatherDiscomfortPenalty($weatherId);
    $comfortScore  -= $weatherPenalty;

    return max(0, min(100, $comfortScore));
}

/**
 * Weather-based discomfort penalty using OpenWeather weather id.
 *
 * Returns penalty in [0, 25], where higher means more uncomfortable.
 * This affects only the comfort layer, not the risk layer.
 *
 * @param int $weatherId
 * @return int
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
            $penalty = 8;
        } elseif (in_array($weatherId, [501, 521, 531], true)) {
            $penalty = 12;
        } elseif (in_array($weatherId, [502, 503, 504, 522], true)) {
            $penalty = 16;
        } elseif ($weatherId === 511) {
            $penalty = 20;
        } else {
            $penalty = 12;
        }
    } elseif ($weatherId >= 600 && $weatherId < 700) {
        // Snow
        if (in_array($weatherId, [600, 615, 620], true)) {
            $penalty = 12;
        } elseif (in_array($weatherId, [601, 612, 621], true)) {
            $penalty = 16;
        } else {
            $penalty = 20;
        }
    } elseif ($weatherId >= 700 && $weatherId < 800) {
        // Atmosphere (mist, smoke, haze, fog, dust, sand, etc.)
        if (in_array($weatherId, [701, 711, 721, 741], true)) {
            $penalty = 10;
        } elseif (in_array($weatherId, [731, 751, 761, 762, 771], true)) {
            $penalty = 18;
        } elseif ($weatherId === 781) {
            $penalty = 25;
        } else {
            $penalty = 12;
        }
    } elseif ($weatherId === 800) {
        // Clear sky
        $penalty = 0;
    } elseif ($weatherId >= 801 && $weatherId <= 804) {
        // Clouds
        if ($weatherId === 801) {
            $penalty = 1;
        } elseif ($weatherId === 802) {
            $penalty = 2;
        } elseif ($weatherId === 803) {
            $penalty = 4;
        } elseif ($weatherId === 804) {
            $penalty = 6;
        }
    }

    return $penalty;
}

/**
 * Air quality comfort score based on multiple pollutants.
 *
 * Returns the minimum (worst) score among all pollutants.
 *
 * @param float $pm25
 * @param float $pm10
 * @param float $o3
 * @param float $co
 * @param float $no2
 * @param float $so2
 * @return float
 */
function calculateAirQualityScoreInternational($pm25, $pm10, $o3, $co, $no2, $so2) {
    $scores = [];

    // PM2.5
    $scores['pm25'] = calculatePollutantScore($pm25, [
        [15, 100],
        [25, 90],
        [35, 80],
        [50, 65],
        [75, 50]
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
 * Generic pollutant scoring helper.
 *
 * @param float $concentration
 * @param array $thresholds Each item: [limit, score]
 * @return float
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
 *
 * @param float $uvi
 * @return float
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
 * Pressure comfort score around standard sea level pressure (1013.25 hPa).
 *
 * @param float $pressure
 * @return float
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

/**
 * Compute risk score from temperature, humidity, wind, gusts and dew point.
 *
 * This represents potential health/survival risk in extreme cold/heat,
 * not just discomfort.
 *
 * @param float      $T
 * @param float      $RH
 * @param float      $wind
 * @param float|null $windGust
 * @param float|null $dewPoint
 * @param float|null $heatIndex
 * @return array{score:int,flags:string[]}
 */
function computeTemperatureRiskScore($T, $RH, $wind, $windGust = null, $dewPoint = null, $heatIndex = null)
{
    $flags = [];

    // Use gust if stronger than mean wind speed
    $vEff  = $wind;
    if ($windGust !== null && is_numeric($windGust) && $windGust > $vEff) {
        $vEff = $windGust;
    }

    $windChill = calculateWindChill($T, $vEff);

    // Cold risk thresholds (wind chill based)
    $coldRisk = 0;
    if ($windChill <= -45) {
        $coldRisk = 95;
        $flags[]  = 'temp_extreme_cold_45';
    } elseif ($windChill <= -40) {
        $coldRisk = 90;
        $flags[]  = 'temp_extreme_cold_40';
    } elseif ($windChill <= -35) {
        $coldRisk = 80;
        $flags[]  = 'temp_extreme_cold_35';
    } elseif ($windChill <= -30) {
        $coldRisk = 65;
        $flags[]  = 'temp_very_cold_30';
    } elseif ($windChill <= -25) {
        $coldRisk = 50;
        $flags[]  = 'temp_cold_25';
    }

    // Heat risk thresholds (heat index based)
    if ($heatIndex === null) {
        $heatIndex = calculateHeatIndex($T, $RH);
    }

    // High dew point aggravates heat risk
    if ($dewPoint !== null && $dewPoint >= 26) {
        $heatIndex += ($dewPoint >= 29) ? 4 : 2;
    }

    $heatRisk = 0;
    if ($heatIndex >= 52) {
        $heatRisk = 90;
        $flags[]  = 'temp_extreme_heat_52';
    } elseif ($heatIndex >= 41) {
        $heatRisk = 80;
        $flags[]  = 'temp_extreme_heat_41';
    } elseif ($heatIndex >= 35) {
        $heatRisk = 60;
        $flags[]  = 'temp_heat_35';
    } elseif ($heatIndex >= 32) {
        $heatRisk = 40;
        $flags[]  = 'temp_heat_32';
    }

    $score = max($coldRisk, $heatRisk);

    return [
        'score' => max(0, min(100, $score)),
        'flags' => $flags
    ];
}

/**
 * Compute risk score from weather phenomena and strong wind gusts.
 *
 * Uses OpenWeather weather id and gust thresholds to estimate
 * potential hazard (storms, heavy rain, snow, fog, dust, tornado, etc).
 *
 * @param int        $weatherId
 * @param float|null $windGust
 * @return array{score:int,flags:string[]}
 */
function computeWeatherRiskScore($weatherId, $windGust = null)
{
    $score = 0;
    $flags = [];

    if ($weatherId >= 200 && $weatherId < 300) {
        // Thunderstorm
        if (in_array($weatherId, [212, 221, 232], true)) {
            $score = 70;
            $flags[] = 'wx_thunderstorm_heavy';
        } else {
            $score = 60;
            $flags[] = 'wx_thunderstorm';
        }
    }
    elseif ($weatherId >= 300 && $weatherId < 400) {
        // Drizzle
        $score = 20;
        $flags[] = 'wx_drizzle';
    }
    elseif ($weatherId >= 500 && $weatherId < 600) {
        // Rain
        if (in_array($weatherId, [500, 520], true)) {
            $score = 30;
            $flags[] = 'wx_rain_light';
        } elseif (in_array($weatherId, [501, 521, 531], true)) {
            $score = 50;
            $flags[] = 'wx_rain_moderate';
        } elseif (in_array($weatherId, [502, 503, 504, 522], true)) {
            $score = 60;
            $flags[] = 'wx_rain_heavy';
        } elseif ($weatherId === 511) {
            $score = 70;
            $flags[] = 'wx_freezing_rain';
        }
    }
    elseif ($weatherId >= 600 && $weatherId < 700) {
        // Snow
        if (in_array($weatherId, [600, 615, 620], true)) {
            $score = 40;
            $flags[] = 'wx_snow_light';
        } elseif (in_array($weatherId, [601, 612, 621], true)) {
            $score = 55;
            $flags[] = 'wx_snow_moderate';
        } else {
            $score = 65;
            $flags[] = 'wx_snow_heavy';
        }
    }
    elseif ($weatherId >= 700 && $weatherId < 800) {
        // Atmosphere group
        if (in_array($weatherId, [701, 711, 721, 741], true)) {
            $score = 35;
            $flags[] = 'wx_fog_mist';
        } elseif (in_array($weatherId, [731, 751, 761, 762, 771], true)) {
            $score = 55;
            $flags[] = 'wx_dust_sand_squall';
        } elseif ($weatherId === 781) {
            $score = 95;
            $flags[] = 'wx_tornado';
        }
    }

    // Additional risk from very strong gusts (Beaufort ~7+)
    if ($windGust !== null && is_numeric($windGust)) {
        if ($windGust >= 25) {
            $score = max($score, 70);
            $flags[] = 'wind_gust_25';
        } elseif ($windGust >= 20) {
            $score = max($score, 60);
            $flags[] = 'wind_gust_20';
        } elseif ($windGust >= 15) {
            $score = max($score, 45);
            $flags[] = 'wind_gust_15';
        }
    }

    return [
        'score' => max(0, min(100, $score)),
        'flags' => $flags
    ];
}

/**
 * Compute risk score from official weather alerts.
 *
 * Input alerts are normalized by an adapter layer and typically look like:
 *  [
 *    'event'          => string|null,
 *    'description'    => string|null,
 *    'tags'           => string[],  // contains 'hazard:*', 'severity:*', 'color:*', 'provider:*'
 *    'severity'       => string|null, // minor/moderate/severe/extreme/unknown
 *    'severity_score' => float|null,  // optional ML-based [0,1]
 *    'code'           => int|null     // provider-specific code (e.g. QWeather)
 *  ]
 *
 * @param array $alerts
 * @return array{score:int,flags:string[]}
 */
function computeAlertsRiskScore(array $alerts)
{
    if (empty($alerts)) {
        return ['score' => 0, 'flags' => []];
    }

    // Base risk by hazard type, assuming "moderate" severity (~factor=1.0).
    $hazardBaseRisk = [
        'extreme_cold'     => 85,
        'extreme_heat'     => 80,
        'thunderstorm'     => 65,
        'heavy_rain'       => 60,
        'snow_ice'         => 70,
        'wind'             => 60,
        'tropical_cyclone' => 90,
        'fog'              => 40,
        'dust_sand'        => 50,
        'fire'             => 70,
        'flood'            => 80,
        'coastal'          => 65,
        'air_quality'      => 60,
        'avalanche'        => 90,
        'geohazard'        => 80,
        'other'            => 40,
    ];

    $overallScore = 0;
    $allFlags     = [];

    foreach ($alerts as $alert) {
        if (!is_array($alert)) {
            continue;
        }

        $tags           = isset($alert['tags']) && is_array($alert['tags']) ? $alert['tags'] : [];
        $hazards        = extractHazardsFromTags($tags);
        $severity       = isset($alert['severity']) ? (string)$alert['severity'] : null;
        $severityScore  = isset($alert['severity_score']) && is_numeric($alert['severity_score'])
                        ? (float)$alert['severity_score'] : null;

        if (empty($hazards)) {
            $hazards = ['other'];
        }

        // Severity factor scales base risk (blue/yellow/orange/red etc.)
        $sevFactor = mapSeverityToFactor($severity, $severityScore);

        foreach ($hazards as $h) {
            if (!isset($hazardBaseRisk[$h])) {
                $h = 'other';
            }

            $base = $hazardBaseRisk[$h];

            $risk = (int)round($base * $sevFactor);
            $risk = max(0, min(100, $risk));

            $overallScore = max($overallScore, $risk);
            $allFlags[]   = 'alert_' . $h;

            if ($severity !== null) {
                $allFlags[] = 'severity_' . strtolower($severity);
            }
        }
    }

    $allFlags = array_values(array_unique($allFlags));

    return [
        'score' => $overallScore,
        'flags' => $allFlags
    ];
}

/**
 * Extract hazard types from alert tags.
 *
 * Example tags:
 *   ['hazard:wind', 'hazard:flood', 'severity:severe', 'provider:qweather']
 * -> returns ['wind', 'flood']
 *
 * @param array $tags
 * @return array
 */
function extractHazardsFromTags(array $tags): array
{
    $hazards = [];

    foreach ($tags as $tag) {
        if (!is_string($tag)) {
            continue;
        }
        if (strpos($tag, 'hazard:') === 0) {
            $h = substr($tag, 7);
            if ($h !== '') {
                $hazards[] = $h;
            }
        }
    }

    return array_values(array_unique($hazards));
}

/**
 * Map severity (and optional ML severity_score) to a numeric factor.
 *
 * If severity_score is provided, it must be in [0,1] and is mapped to [0.7, 1.4].
 * Otherwise we map string labels (minor/moderate/severe/extreme) to fixed factors.
 *
 * @param string|null $severity
 * @param float|null  $severityScore
 * @return float
 */
function mapSeverityToFactor(?string $severity, ?float $severityScore = null): float
{
    if ($severityScore !== null) {
        $x = max(0.0, min(1.0, $severityScore));
        return 0.7 + 0.7 * $x; // linear interpolation between 0.7 and 1.4
    }

    if ($severity === null) {
        return 1.0;
    }

    switch (strtolower(trim($severity))) {
        case 'minor':
            return 0.7;
        case 'moderate':
            return 1.0;
        case 'severe':
            return 1.2;
        case 'extreme':
            return 1.4;
        case 'unknown':
        default:
            return 1.0;
    }
}

/**
 * Safe lowercase helper with UTF-8 support.
 *
 * @param string $str
 * @return string
 */
function cei_strlower_safe(string $str): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($str, 'UTF-8');
    }
    return strtolower($str);
}
