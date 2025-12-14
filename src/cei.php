<?php
/**
 * CEI 3.2.0 - Comfort Environment Index
 * -------------------------------------------------------
 * "Correlation-aware hazard fusion", "Alert-body synergy", "forecast-time evaluation ts support"
 *
 * This version explicitly splits CEI into two layers:
 *   - Comfort layer (thermal comfort, air quality, UV, pressure)
 *   - Safety risk cap layer (synergy of sensations/phenomena/alerts, output RiskCap as the capping upper bound)
 *
 * Key design points for the risk layer (critical fixes):
 *   1) Correlation-aware fusion (Hazard Bucket Fusion):
 *      - Bucket risks by hazard (e.g., extreme_cold, snow_ice, wind, heavy_rain, thunderstorm, etc.),
 *      - For each hazard, compute simultaneously:
 *          P: Physical risk (derived from temperature/wind chill/heat index, weather_id, gust/wind, etc.)
 *          A: Alert signal strength (derived from alerts' hazard + severity + time phase)
 *          Q: Alert credibility/hit-rate (composited from certainty/urgency/area/time phase labels)
 *      - Final fused intensity R: alerts only "fill the gap", avoiding double penalty from the same source:
 *          R = P + Q * max(0, A - P)
 *        When the physical data already shows extreme low temperature, a "cold wave" alert will not forcibly max out the risk,
 *        but it will increase the hazard's Focus (used for hints/suggestions to be more cautious).
 *
 *   2) Alert time phases and forecast evaluation moment:
 *      - Added data['ts'] as the evaluation time (UTC seconds), used for calculating CEI at some future time in forecast scenes.
 *      - The risk layer uses ts to determine alert phases:
 *          lead (not started) / active (in effect) / past (expired), and attenuates or zeros alert strength accordingly.
 *      - If ts is not provided, defaults to time().
 *
 *   3) Risk outputs separation (for UI and explanations):
 *      - risk (overall): overall risk intensity (0=no risk, 100=extreme risk)
 *      - risk_cap: the actual capping upper bound (0–100)
 *      - risk_hint: more sensitive "hint score", leaning more toward max(P, A) (good for reminders, not necessarily capping)
 *      - risk_focus: focus score (higher when P and A are aligned with higher Q, for "key attention")
 *
 * Final CEI formula:
 *   CEI = min(Comfort-layer CEI, Safety risk cap RiskCap)
 *
 * All scores are normalized to [0, 100].
 * Input fields are compatible with OpenWeather / QWeather styles, but this file itself has no dependency on a specific data source;
 * callers only need to construct $data as agreed.
 */

/**
 * Example of output structure:
 *
 * {
 *   "cei": 62,
 *   "level": "CEI Level 3 – Acceptable",
 *   "components": { "heat": 58, "air": 80, "uv": 100, "pressure": 70, "risk": 48 },
 *   "weights": { "heat": 0.50, "air": 0.33, "uv": 0.08, "pressure": 0.09 },
 *   "detail": {
 *     "comfort_cei": 71.3,
 *     "risk_cap": 62.0,
 *     "risk_hint": 74,
 *     "risk_focus": 68,
 *     "main_effect": "risk",
 *     "climate": { "zone": "temperate", "factor": 1.0, "comfortTemp": 22 },
 *     "thermal": { "effective_temp": -3.2, "heat_index": 0.0, "wind_chill": -6.8 },
 *     "risk": {
 *       "overall": 48,
 *       "cap": 62.0,
 *       "hint": 74,
 *       "focus": 68,
 *       "from_temp": 10,
 *       "from_weather": 42,
 *       "from_alerts": 38,
 *       "factors": ["snow_ice","wind"],
 *       "debug_flags": ["alerts_present","phase_active"],
 *       "hazards": { "...": { "P":0.4,"A":0.7,"Q":0.8,"R":0.56,"Focus":0.68 } }
 *     }
 *   }
 * }
 */

/**
 * Main function to compute CEI for a single moment.
 *
 * @param string    $unit      Unit system:
 *                             - 'metric'   : °C, m/s
 *                             - 'imperial' : °F, mph
 *                             - 'standard' : K, m/s
 * @param array     $data      Input data, required fields:
 *                             - temp       : float, air temperature
 *                             - humidity   : float, relative humidity (%)
 *                             - wind_speed : float, wind speed
 *                             - pm2_5, pm10, o3, co, no2, so2 : float, pollutant concentrations
 *                             - uvi        : float, UV index
 *                             - pressure   : float, air pressure (hPa)
 *                             Optional fields:
 *                             - ts         : int|null, evaluation moment (UTC seconds; recommended for forecast scenes)
 *                             - wind_gust  : float|null, gust wind speed
 *                             - dew_point  : float|null, dew point temperature
 *                             - feels_like : float|null, apparent temperature (for debugging/extension only)
 *                             - weather_id : int|null, OpenWeather weather code
 *                             - alerts     : array|null, standardized alerts after an adaptation layer
 * @param float     $latitude  Latitude (used to determine climate zone)
 * @param int       $month     Current month (1–12)
 * @param int|null  $weatherId Optional weather code; when null, prefers data['weather_id'], otherwise falls back to 800 (clear).
 *
 * @return array {
 *   cei: int 0–100,
 *   level: string level description,
 *   components: {
 *      heat: int thermal comfort score,
 *      air: int air comfort score,
 *      uv: int UV comfort score,
 *      pressure: int pressure comfort score,
 *      risk: int risk intensity (0=no risk, 100=extreme risk)
 *   },
 *   weights: {
 *      heat: float,
 *      air: float,
 *      uv: float,
 *      pressure: float
 *   },
 *   detail: {
 *      comfort_cei: float CEI of the comfort layer only,
 *      risk_cap: float    risk cap (0–100, the actual capping upper bound),
 *      risk_hint: int     risk hint score (0–100, more sensitive, for reminders),
 *      risk_focus: int    risk focus score (0–100, for "key attention"),
 *      main_effect: string primary limiting source of current environment,
 *                          'heat'|'air'|'uv'|'pressure'|'risk',
 *      climate: {
 *          zone: string climate zone name,
 *          factor: float climate factor,
 *          comfortTemp: float baseline comfort temperature (°C)
 *      },
 *      thermal: {
 *         effective_temp: float composite perceived temperature,
 *         heat_index: float heat index,
 *         wind_chill: float wind chill temperature
 *      },
 *      risk: {
 *         overall: int risk intensity (0–100),
 *         cap: float risk cap,
 *         hint: int risk hint score,
 *         focus: int risk focus score,
 *         from_temp: int risk contribution from temperature extremes,
 *         from_weather: int risk contribution from weather phenomena,
 *         from_alerts: int risk contribution from official alerts,
 *         factors: string[] user-readable risk factors (hazard list; recommend front-end display),
 *         debug_flags: string[] internal debug flags (do not present as "risk factors"),
 *         hazards: array hazard bucket details (P/A/Q/R/Focus, for explanations and visualization)
 *
 *      }
 *   }
 * }
 */
function computeCEI($unit, $data, $latitude, $month, $weatherId = null)
{
    // 1) Unit validation
    if (!in_array($unit, ['imperial', 'metric', 'standard'], true)) {
        return ['error' => 'Invalid unit type'];
    }

    // 2) Required fields validation
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

    // 3) Evaluation moment: for forecast scenes, pass a future ts (UTC seconds) to judge alert time phases
    $evalTs = (isset($data['ts']) && is_numeric($data['ts'])) ? (int)$data['ts'] : time();

    // 4) Extract core meteorological and pollutant inputs
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

    // 5) Optional fields
    $windGust  = (isset($data['wind_gust'])  && is_numeric($data['wind_gust']))  ? (float)$data['wind_gust']  : null;
    $dewPoint  = (isset($data['dew_point'])  && is_numeric($data['dew_point']))  ? (float)$data['dew_point']  : null;
    $feelsLike = (isset($data['feels_like']) && is_numeric($data['feels_like'])) ? (float)$data['feels_like'] : null;

    // 6) Weather phenomenon: prefer input weatherId; else fall back to data['weather_id']; finally default to clear (800)
    if ($weatherId === null && isset($data['weather_id']) && is_numeric($data['weather_id'])) {
        $weatherId = (int)$data['weather_id'];
    }
    $weatherId = ($weatherId === null) ? 800 : (int)$weatherId;

    // 7) Alerts: upstream alert adaptation layer should have standardized different data sources into alerts structure
    $alerts = [];
    if (isset($data['alerts']) && is_array($data['alerts'])) {
        $alerts = $data['alerts'];
    }

    // 8) Unit conversions: internally use °C and m/s (pollutants, uvi, pressure assumed in agreed units)
    if ($unit === 'imperial') {
        $T = ($T - 32) * 5 / 9;
        if ($dewPoint !== null)  $dewPoint  = ($dewPoint  - 32) * 5 / 9;
        if ($feelsLike !== null) $feelsLike = ($feelsLike - 32) * 5 / 9;

        $wind = $wind / 2.237;
        if ($windGust !== null)  $windGust = $windGust / 2.237;
    } elseif ($unit === 'standard') {
        $T = $T - 273.15;
        if ($dewPoint !== null)  $dewPoint  = $dewPoint - 273.15;
        if ($feelsLike !== null) $feelsLike = $feelsLike - 273.15;
    }

    // 9) Climate context: same temperature feels different by latitude/season; use comfortTemp as the "comfort anchor"
    $climateContext    = getClimateContext($latitude, $month);
    $climateAdjustment = $climateContext['factor'];
    $comfortTemp       = $climateContext['comfortTemp'];

    // 10) Dynamic weights: slightly raise the weight of the dimension most affecting the current perception
    $weights = dynamicWeightAdjustment($T, $pm25, $uvi, $wind);

    // 11) Thermal comfort: heat index / wind chill + humidity/wind effects + weather phenomenon penalties
    $heatIndex = calculateHeatIndex($T, $RH);
    $heatScore = calculateThermalComfort($T, $RH, $wind, $heatIndex, $weatherId, $comfortTemp, $dewPoint);

    // 12) Perception diagnostic (effective_temp) for output: use heatIndex in warm seasons; windChill in cold seasons
    $windChill     = calculateWindChill($T, max($wind, $windGust ?? $wind));
    $effectiveTemp = ($T >= 20) ? $heatIndex : $windChill;

    // 13) Other comfort components: air/UV/pressure
    $airScore   = calculateAirQualityScoreInternational($pm25, $pm10, $o3, $co, $no2, $so2);
    $uvScore    = calculateUVScore($uvi);
    $pressScore = calculatePressureScore($press);

    // 14) Comfort-layer aggregation: weighted sum + climate factor scaling
    $ceiComfort = $weights['heat']  * $heatScore
                + $weights['air']   * $airScore
                + $weights['uv']    * $uvScore
                + $weights['press'] * $pressScore;

    $ceiComfort *= $climateAdjustment;
    $ceiComfort  = max(0, min(100, $ceiComfort));

    // 15) Risk layer: fuse "physical data" and "alert signals" by hazard buckets, produce risk_cap / hint / focus etc.
    $riskLayer = computeRiskLayer([
        'T' => $T, 'RH' => $RH, 'wind' => $wind, 'windGust' => $windGust,
        'dewPoint' => $dewPoint, 'heatIndex' => $heatIndex,
        'weatherId' => $weatherId,
        'pm25' => $pm25, 'pm10' => $pm10, 'o3' => $o3, 'co' => $co, 'no2' => $no2, 'so2' => $so2,
        'alerts' => $alerts,
        'evalTs' => $evalTs,
    ]);

    // 16) Risk outputs: risk_score is intensity (0-100); risk_cap is capping upper bound (0-100, lower means more "danger")
    $riskScore = (int)round($riskLayer['risk_score']);
    $riskCap   = (float)$riskLayer['risk_cap'];
    $riskHint  = (int)round($riskLayer['risk_hint_score']);
    $riskFocus = (int)round($riskLayer['risk_focus_score']);

    // 17) Final CEI: take the minimum of comfort layer and risk cap
    $ceiFinal = min($ceiComfort, $riskCap);
    $ceiFinal = max(0, min(100, $ceiFinal));

    // 18) main_effect: if capped by risk, main effect is risk; otherwise the lowest comfort component
    $componentScores = [
        'heat'     => $heatScore,
        'air'      => $airScore,
        'uv'       => $uvScore,
        'pressure' => $pressScore
    ];
    $minComponent = array_keys($componentScores, min($componentScores))[0];
    $mainEffect   = ($riskCap < $ceiComfort) ? 'risk' : $minComponent;

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
            'risk_hint'   => $riskHint,
            'risk_focus'  => $riskFocus,
            'main_effect' => $mainEffect,

            'climate' => $climateContext,

            'thermal' => [
                'effective_temp' => round($effectiveTemp, 1),
                'heat_index'     => round($heatIndex, 1),
                'wind_chill'     => round($windChill, 1)
            ],

            'risk' => [
                'overall'      => round($riskScore),
                'cap'          => round($riskCap, 1),
                'hint'         => $riskHint,
                'focus'        => $riskFocus,

                'from_temp'    => (int)round($riskLayer['from_temp']),
                'from_weather' => (int)round($riskLayer['from_weather']),
                'from_alerts'  => (int)round($riskLayer['from_alerts']),

                // User-readable "risk factors" (recommend front-end display; list of hazard names)
                'factors'      => $riskLayer['factors'],

                // Internal debug flags (recommend to show only in debug panels; not as "risk factors")
                'debug_flags'  => $riskLayer['debug_flags'],

                // Hazard bucket details: P/A/Q/R/Focus for each hazard (for explanation and visualization)
                'hazards'      => $riskLayer['hazards'],
            ]
        ]
    ];
}

/**
 * Map CEI numeric value to a level label.
 * The thresholds are heuristic.
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
 * Estimate climate context:
 * - zone: banding by |latitude| (symmetric N/S)
 * - comfortTemp: "baseline comfort temperature" for the climate band (comfort anchor)
 * - factor: mild scaling by season/climate (avoid overfitting the comfort layer to a single temperature zone)
 *
 * Note: months in the southern hemisphere are shifted by 6 months to avoid seasonal misjudgment.
 */
function getClimateContext($latitude, $month)
{
    $absLat = abs($latitude);

    if ($absLat < 10) {
        $climateZone = 'equatorial'; $comfortTemp = 26;
    } elseif ($absLat < 23.5) {
        $climateZone = 'tropical'; $comfortTemp = 25;
    } elseif ($absLat < 35) {
        $climateZone = 'subtropical'; $comfortTemp = 24;
    } elseif ($absLat < 55) {
        $climateZone = 'temperate'; $comfortTemp = 22;
    } elseif ($absLat < 66.5) {
        $climateZone = 'cold_temperate'; $comfortTemp = 20;
    } else {
        $climateZone = 'polar'; $comfortTemp = 18;
    }

    $monthNorm = ($month >= 1 && $month <= 12) ? (int)$month : 1;
    if ($latitude < 0) {
        $monthNorm = (($monthNorm + 5) % 12) + 1;
    }

    if (in_array($monthNorm, [6, 7, 8], true)) {
        $comfortTemp += 1;
    } elseif (in_array($monthNorm, [12, 1, 2], true)) {
        $comfortTemp -= 1;
    }

    $factor = adjustForClimate($latitude, $month);

    return [
        'zone'        => $climateZone,
        'factor'      => $factor,
        'comfortTemp' => $comfortTemp,
    ];
}

/**
 * Seasonal/climate factor (mild scaling):
 * - Tropical regions feel more "muggy discomfort" in peak summer (factor slightly raised)
 * - High-latitude cold regions feel more "harsh" in severe winter (factor slightly raised), slightly lowered in summer
 * Goal is "light correction" without stealing the expression from the main models (comfort layer + risk layer).
 */
function adjustForClimate($latitude, $month)
{
    $absLat = abs($latitude);

    if ($absLat < 10) $climateZone = 'equatorial';
    elseif ($absLat < 23.5) $climateZone = 'tropical';
    elseif ($absLat < 35) $climateZone = 'subtropical';
    elseif ($absLat < 55) $climateZone = 'temperate';
    elseif ($absLat < 66.5) $climateZone = 'cold_temperate';
    else $climateZone = 'polar';

    $monthNorm = ($month >= 1 && $month <= 12) ? (int)$month : 1;
    if ($latitude < 0) {
        $monthNorm = (($monthNorm + 5) % 12) + 1;
    }

    $seasonFactor = 1.0;

    if (in_array($monthNorm, [6, 7, 8], true)) {
        if ($climateZone === 'tropical') $seasonFactor = 1.1;
        elseif (in_array($climateZone, ['polar', 'cold_temperate'], true)) $seasonFactor = 0.9;
    }
    elseif (in_array($monthNorm, [12, 1, 2], true)) {
        if ($climateZone === 'tropical') $seasonFactor = 0.9;
        elseif (in_array($climateZone, ['polar', 'cold_temperate'], true)) $seasonFactor = 1.1;
    }

    return $seasonFactor;
}

/**
 * Dynamic weights: slightly raise the weight of the more salient perception dimension now, then normalize.
 * - Cold/Hot: raise thermal comfort weight (stronger perception dominance)
 * - Strong wind: raise thermal comfort weight (wind chill / wind resistance)
 * - High PM2.5: raise air weight (health/respiratory discomfort)
 * - High UV: raise UV weight (sunburn risk/discomfort)
 */
function dynamicWeightAdjustment($T, $pm25, $uvi, $wind) {
    $weights = [
        'heat'  => 0.4,
        'air'   => 0.4,
        'uv'    => 0.1,
        'press' => 0.1
    ];

    if ($T > 30) $weights['heat'] = 0.5;
    elseif ($T < 15) $weights['heat'] = 0.6;

    if ($wind > 8)  $weights['heat'] += 0.05;
    if ($wind > 12) $weights['heat'] += 0.05;

    if ($pm25 > 35) $weights['air'] = 0.5;

    if ($uvi > 8) $weights['uv'] = 0.2;

    $minWeight = 0.05;
    foreach ($weights as $k => $v) {
        if ($v < $minWeight) $weights[$k] = $minWeight;
    }

    $sum = array_sum($weights);
    foreach ($weights as $k => &$v) {
        $v = $v / $sum;
    }
    unset($v);

    return $weights;
}

/**
 * Heat Index (°C):
 * - Primarily applicable to warm conditions (T >= 20°C)
 * - Returns T at low temperatures to avoid unnecessary "heat-index noise"
 */
function calculateHeatIndex($T, $RH) {
    if ($T < 20) return $T;

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
 * Wind Chill (°C):
 * - Effective only when T < 10°C and wind > 1.3 m/s
 * - Otherwise returns original T
 */
function calculateWindChill($T, $wind) {
    if ($T >= 10 || $wind <= 1.3) return $T;

    $wind_kmh = $wind * 3.6;

    return 13.12
         + 0.6215 * $T
         - 11.37 * pow($wind_kmh, 0.16)
         + 0.3965 * $T * pow($wind_kmh, 0.16);
}

/**
 * Temperature comfort curve (0-100):
 * - Centered at comfortTemp; the larger the deviation, the more penalty
 * - Piecewise for "interpretable, stable, easy-to-tune" behavior
 */
function thermalComfortCurve($effectiveTemp, $comfortTemp)
{
    $delta = $effectiveTemp - $comfortTemp;
    $absDelta = abs($delta);

    if ($absDelta <= 2) return 100;
    if ($absDelta <= 5) return max(90, 100 - $absDelta * 2);
    if ($absDelta <= 15) return max(60, 90 - ($absDelta - 5) * 3);
    if ($absDelta <= 25) return max(30, 60 - ($absDelta - 15) * 3);
    return max(5, 30 - ($absDelta - 25) * 2);
}

/**
 * Thermal comfort main (0-100):
 * - Effective temperature: HeatIndex in warm seasons; WindChill in cold seasons
 * - Humidity: most comfortable around 50%; extra muggy penalty when dew point is high
 * - Wind: light breeze is best; strong wind reduces comfort
 * - Weather phenomena: use weatherId to apply extra discomfort penalties (affect comfort only; not risk layer)
 */
function calculateThermalComfort($T, $RH, $wind, $heatIndex, $weatherId, $comfortTemp, $dewPoint = null) {
    $effectiveTemp = ($T >= 20) ? $heatIndex : calculateWindChill($T, $wind);

    $tempComfort = thermalComfortCurve($effectiveTemp, $comfortTemp);

    $humidityComfort = 100 - min(60, abs($RH - 50) * 1.2);

    if ($dewPoint !== null && $T >= 20 && $dewPoint >= 24) {
        $extra = min(15, ($dewPoint - 23) * 1.5);
        $humidityComfort = max(20, $humidityComfort - $extra);
    }

    $windComfort = ($wind <= 3) ? 100 : max(20, 100 - ($wind - 3) * 10);

    $heatComfort = ($heatIndex <= 27) ? 100 : max(20, 100 - ($heatIndex - 27) * 8);

    $comfortScore = 0.5 * $tempComfort
                  + 0.25 * $humidityComfort
                  + 0.15 * $windComfort
                  + 0.10 * $heatComfort;

    $comfortScore -= getWeatherDiscomfortPenalty($weatherId);

    return max(0, min(100, $comfortScore));
}

/**
 * Weather discomfort penalty (0-25):
 * - Affects the "comfort layer" only, expressing discomfort from rain/snow/thunder/fog/smog
 * - Not used for risk capping (risk capping is handled by computeRiskLayer, focusing more on safety/health)
 */
function getWeatherDiscomfortPenalty($weatherId) {
    $penalty = 0;

    if ($weatherId >= 200 && $weatherId < 300) {
        $penalty = in_array($weatherId, [212, 221, 232], true) ? 20 : 15;
    } elseif ($weatherId >= 300 && $weatherId < 400) {
        $penalty = 6;
    } elseif ($weatherId >= 500 && $weatherId < 600) {
        if (in_array($weatherId, [500, 520], true)) $penalty = 8;
        elseif (in_array($weatherId, [501, 521, 531], true)) $penalty = 12;
        elseif (in_array($weatherId, [502, 503, 504, 522], true)) $penalty = 16;
        elseif ($weatherId === 511) $penalty = 20;
        else $penalty = 12;
    } elseif ($weatherId >= 600 && $weatherId < 700) {
        if (in_array($weatherId, [600, 615, 620], true)) $penalty = 12;
        elseif (in_array($weatherId, [601, 612, 621], true)) $penalty = 16;
        else $penalty = 20;
    } elseif ($weatherId >= 700 && $weatherId < 800) {
        if (in_array($weatherId, [701, 711, 721, 741], true)) $penalty = 10;
        elseif (in_array($weatherId, [731, 751, 761, 762, 771], true)) $penalty = 18;
        elseif ($weatherId === 781) $penalty = 25;
        else $penalty = 12;
    } elseif ($weatherId === 800) {
        $penalty = 0;
    } elseif ($weatherId >= 801 && $weatherId <= 804) {
        if ($weatherId === 801) $penalty = 1;
        elseif ($weatherId === 802) $penalty = 2;
        elseif ($weatherId === 803) $penalty = 4;
        elseif ($weatherId === 804) $penalty = 6;
    }

    return $penalty;
}

/**
 * Air quality comfort score (0-100):
 * - Map each pollutant to a score
 * - Take the worst item as the overall score (bottleneck effect)
 */
function calculateAirQualityScoreInternational($pm25, $pm10, $o3, $co, $no2, $so2) {
    $scores = [];

    $scores['pm25'] = calculatePollutantScore($pm25, [
        [15, 100],
        [25, 90],
        [35, 80],
        [50, 65],
        [75, 50]
    ]);

    $scores['pm10'] = calculatePollutantScore($pm10, [
        [15, 100],
        [45, 80],
        [60, 60],
        [90, 40],
        [120, 20]
    ]);

    $scores['o3'] = calculatePollutantScore($o3, [
        [60, 100],
        [100, 80],
        [130, 60],
        [160, 40],
        [200, 20]
    ]);

    // CO: input is µg/m³; convert to mg/m³ for threshold scoring
    $co_mg = $co / 1000.0;
    $scores['co'] = calculatePollutantScore($co_mg, [
        [1, 100],
        [4, 80],
        [7, 60],
        [10, 40],
        [15, 20]
    ]);

    $scores['no2'] = calculatePollutantScore($no2, [
        [10, 100],
        [25, 80],
        [40, 60],
        [60, 40],
        [80, 20]
    ]);

    $scores['so2'] = calculatePollutantScore($so2, [
        [20, 100],
        [40, 80],
        [60, 60],
        [80, 40],
        [100, 20]
    ]);

    return min($scores);
}

/**
 * Generic pollutant score mapping (piecewise thresholds).
 */
function calculatePollutantScore($concentration, $thresholds) {
    foreach ($thresholds as $threshold) {
        if ($concentration <= $threshold[0]) return $threshold[1];
    }
    return 10;
}

/**
 * UV comfort score (0-100):
 * - Higher UVI means less comfort (and sunburn risk exists)
 */
function calculateUVScore($uvi) {
    if ($uvi <= 2) return 100;
    if ($uvi <= 5) return 85;
    if ($uvi <= 7) return 70;
    if ($uvi <= 10) return 55;
    return 40;
}

/**
 * Pressure comfort score (0-100):
 * - Base at 1013.25 hPa
 * - Larger deviation means less comfort, but keep a lower bound
 */
function calculatePressureScore($pressure) {
    $standard  = 1013.25;
    $deviation = abs($pressure - $standard);

    if ($deviation <= 5)  return 100;
    if ($deviation <= 10) return 90;
    if ($deviation <= 15) return 80;
    if ($deviation <= 20) return 70;
    if ($deviation <= 25) return 60;

    return max(40, 100 - $deviation * 2);
}

/**
 * Risk-layer main (Hazard Bucket Fusion):
 * Inputs ctx:
 * - Physical perception data: T/RH/wind/windGust/dewPoint/heatIndex/weatherId
 * - Health data: pm25/pm10/o3/co/no2/so2
 * - Official alerts: alerts (already standardized)
 * - Evaluation moment: evalTs (for forecast)
 *
 * Outputs:
 * - risk_score: overall risk intensity (0-100)
 * - risk_cap: risk capping upper bound (0-100, lower means more dangerous)
 * - risk_hint_score: risk hint score (more sensitive)
 * - risk_focus_score: focus score (for "key attention")
 * - hazards: each hazard's P/A/Q/R/Focus
 * - factors: user-readable risk factors (recommend front-end display)
 * - debug_flags: internal debug flags
 */
function computeRiskLayer(array $ctx): array
{
    $T         = (float)$ctx['T'];
    $RH        = (float)$ctx['RH'];
    $wind      = (float)$ctx['wind'];
    $windGust  = $ctx['windGust'];
    $dewPoint  = $ctx['dewPoint'];
    $heatIndex = (float)$ctx['heatIndex'];
    $weatherId = (int)$ctx['weatherId'];
    $evalTs    = (int)$ctx['evalTs'];

    $pm25 = (float)$ctx['pm25'];
    $pm10 = (float)$ctx['pm10'];
    $o3   = (float)$ctx['o3'];
    $co   = (float)$ctx['co'];
    $no2  = (float)$ctx['no2'];
    $so2  = (float)$ctx['so2'];

    $alerts = $ctx['alerts'];

    $debug = [];
    $debug[] = 'risk_hazard_bucket';

    // 1) Physical-side risk (P): temperature extremes, weather phenomena, air health risks
    $tempRisk    = computeTemperatureRiskScore($T, $RH, $wind, $windGust, $dewPoint, $heatIndex);
    $weatherRisk = computeWeatherRiskScore($weatherId, $windGust, $T, $wind);
    $airHealth   = computeAirHealthRisk($pm25, $pm10, $o3, $co, $no2, $so2);

    // 2) Construct P (0-1): bucketed by hazard
    $P = [];
    $P['extreme_cold'] = clamp01($tempRisk['cold_score'] / 100.0);
    $P['extreme_heat'] = clamp01($tempRisk['heat_score'] / 100.0);

    foreach ($weatherRisk['hazards'] as $hz => $r01) {
        $P[$hz] = max($P[$hz] ?? 0.0, clamp01($r01));
    }

    $P['air_quality'] = max($P['air_quality'] ?? 0.0, clamp01($airHealth['risk_01']));

    // 3) Alert-side signals (A/Q): A=strength(0-1), Q=credibility/hit-rate(0-1)
    $alertSignals = computeAlertHazardSignals($alerts, $evalTs);
    $A = $alertSignals['A'];
    $Q = $alertSignals['Q'];

    // 4) Fusion: R = P + Q * max(0, A - P)
    //    Explanation: alerts only "fill the gap" when stronger than physical signals, avoiding double penalty
    $hazards = [];
    $betaFocus = 0.35;

    $hzKeys = array_values(array_unique(array_merge(array_keys($P), array_keys($A))));
    sort($hzKeys);

    foreach ($hzKeys as $hz) {
        $p = (float)($P[$hz] ?? 0.0);
        $a = (float)($A[$hz] ?? 0.0);
        $q = (float)($Q[$hz] ?? 0.0);

        $r = $p + $q * max(0.0, ($a - $p));

        // 5) Borderline correction: when physical is "borderline" and alerts are strong and credible, allow a slight increase of R
        //    Purpose: enhance credible vigilance for low-temperature borderline scenarios like icy roads, snow, wind (but still not hard maxing)
        if ($p >= 0.35 && $p <= 0.65 && $a >= 0.75 && $q >= 0.60) {
            $r += 0.05 * ($a - $p);
            $debug[] = 'borderline_boost_' . $hz;
        }

        $r = clamp01($r);

        // 6) Focus: more for reminders, emphasizing "attention-worthy if either side is high"
        $focus = max($p, $a) + $betaFocus * min($p, $a) * $q;
        $focus = clamp01($focus);

        $hazards[$hz] = [
            'P'     => round($p, 3),
            'A'     => round($a, 3),
            'Q'     => round($q, 3),
            'R'     => round($r, 3),
            'Focus' => round($focus, 3),
            'source' => [
                'physical' => ($p > 0.001),
                'alert'    => ($a > 0.001),
            ]
        ];
    }

    // 7) Overall aggregation: Noisy-OR (won't "linearly explode" from a single item, better for coexisting risks)
    $impactW = getHazardImpactWeights();
    $R_overall     = combineHazardsNoisyOR($hazards, $impactW, 'R');
    $Focus_overall = combineHazardsNoisyOR($hazards, $impactW, 'Focus');

    // 8) risk_score: risk intensity (higher is more dangerous); risk_cap: capping upper bound (lower is more dangerous)
    $risk_score = 100.0 * $R_overall;
    $risk_cap   = mapOverallRiskToCap($R_overall);

    // 9) risk_hint: hint score (more sensitive), aggregated using max(P, A) (more like a "reminder system")
    $H_overall = computeHintOverall($hazards, $impactW);
    $risk_hint_score = 100.0 * $H_overall;

    // 10) factors: user-readable risk factors (hazard list); recommend front-end to display these as "risk factors"
    $factors = pickUserFactors($hazards);

    // 11) Compatibility fields: useful for existing front-end, or explaining "where the risk mainly comes from"
    $fromTemp = 100.0 * max($hazards['extreme_cold']['R'] ?? 0.0, $hazards['extreme_heat']['R'] ?? 0.0);

    $fromWeather = 100.0 * max(
        $hazards['wind']['R'] ?? 0.0,
        $hazards['snow_ice']['R'] ?? 0.0,
        $hazards['heavy_rain']['R'] ?? 0.0,
        $hazards['thunderstorm']['R'] ?? 0.0,
        $hazards['fog']['R'] ?? 0.0,
        $hazards['dust_sand']['R'] ?? 0.0,
        $hazards['tornado']['R'] ?? 0.0,
        0.0
    );

    $fromAlerts = 100.0 * maxAlertContribution($hazards);

    // 12) debug_flags: internal debug flag set (do not present as "risk factors")
    $debug = array_values(array_unique(array_merge(
        $debug,
        $tempRisk['debug_flags'],
        $weatherRisk['debug_flags'],
        $alertSignals['debug_flags']
    )));

    return [
        'risk_score'       => round($risk_score, 1),
        'risk_cap'         => round($risk_cap, 1),
        'risk_hint_score'  => round($risk_hint_score, 1),
        'risk_focus_score' => round(100.0 * $Focus_overall, 1),

        'from_temp'        => round($fromTemp, 1),
        'from_weather'     => round($fromWeather, 1),
        'from_alerts'      => round($fromAlerts, 1),

        'factors'          => $factors,
        'debug_flags'      => $debug,
        'hazards'          => $hazards,
    ];
}

/**
 * Compute per-hazard signals from alerts:
 * - A: alert strength (0-1): hazard_base * severity * timeFactor
 * - Q: alert credibility/hit-rate (0-1): timeFactor * certainty * urgency * area
 *
 * Explanation:
 * - A expresses "how strong the alert itself is"
 * - Q expresses "whether this alert is credible and applicable at the current evaluation time"
 * - Whether it affects capping is determined by the fusion formula R (only fills gaps, no repeated penalties)
 */
function computeAlertHazardSignals(array $alerts, int $evalTs): array
{
    $A = [];
    $Q = [];
    $debug = [];

    if (empty($alerts)) {
        return ['A' => [], 'Q' => [], 'debug_flags' => ['alerts_empty']];
    }

    $debug[] = 'alerts_present';

    foreach ($alerts as $alert) {
        if (!is_array($alert)) continue;

        // tags: should be unified by the adaptation layer (hazard:*, severity:*, certainty:*, urgency:*, area:* etc.)
        $tags = (isset($alert['tags']) && is_array($alert['tags'])) ? $alert['tags'] : [];
        $hazards = extractHazardsFromTags($tags);
        if (empty($hazards)) $hazards = ['other'];

        $severity      = isset($alert['severity']) ? (string)$alert['severity'] : null;
        $severityScore = (isset($alert['severity_score']) && is_numeric($alert['severity_score']))
            ? (float)$alert['severity_score'] : null;

        // start/end: UTC seconds; use evalTs to judge phase for forecast
        $startTs = (isset($alert['start_ts']) && is_numeric($alert['start_ts'])) ? (int)$alert['start_ts'] : null;
        $endTs   = (isset($alert['end_ts'])   && is_numeric($alert['end_ts']))   ? (int)$alert['end_ts']   : null;

        $timeInfo = mapAlertTimeFactor($startTs, $endTs, $evalTs);
        $tFactor  = $timeInfo['factor'];
        $phase    = $timeInfo['phase'];

        // Expired: skip directly
        if ($tFactor <= 0.0) {
            $debug[] = 'phase_past';
            continue;
        }

        // Map severity to 0-1 (for better fusion with P/Q)
        $sev01 = mapSeverityTo01($severity, $severityScore);

        // Credibility/hit-rate factors: come from tags (use default when missing)
        $certainty01 = extractTagFactor($tags, 'certainty', [
            'observed' => 1.00, 'likely' => 0.85, 'possible' => 0.70, 'unknown' => 0.80,
        ], 0.80);

        $urgency01 = extractTagFactor($tags, 'urgency', [
            'immediate' => 1.00, 'expected' => 0.85, 'future' => 0.70, 'past' => 0.00, 'unknown' => 0.80,
        ], 0.80);

        $area01 = extractTagFactor($tags, 'area', [
            'point' => 1.00, 'local' => 0.90, 'city' => 0.80, 'regional' => 0.70,
            'province' => 0.60, 'national' => 0.50, 'marine' => 0.65, 'unknown' => 0.75,
        ], 0.75);

        // Q: composite credibility (closer to 1 is more credible)
        $q = clamp01($tFactor * $certainty01 * $urgency01 * $area01);

        // Baseline strength (0-1) for each hazard, roughly corresponding to "moderate severity + active phase"
        $hazardBase = getAlertHazardBase01();

        foreach ($hazards as $hz) {
            $base = $hazardBase[$hz] ?? $hazardBase['other'];

            // A: alert strength (use tFactor lightly to avoid too strong signals during lead phase)
            $a = $base * $sev01 * (0.65 + 0.35 * $tFactor);
            $a = clamp01($a);

            // Aggregation: for multiple alerts of the same hazard, take the max (strongest one dominates)
            $A[$hz] = max($A[$hz] ?? 0.0, $a);
            $Q[$hz] = max($Q[$hz] ?? 0.0, $q);

            $debug[] = 'alert_' . $hz;
            if ($severity !== null) $debug[] = 'severity_' . cei_strlower_safe($severity);
            $debug[] = 'phase_' . $phase;
        }
    }

    $debug = array_values(array_unique($debug));
    return ['A' => $A, 'Q' => $Q, 'debug_flags' => $debug];
}

/**
 * Alert time-phase mapping:
 * - past: ended over 1 hour ago, factor=0
 * - lead: not started yet, decay by lead time (further means weaker)
 * - active: in effect, factor=1
 * - unknown_time: no time info, give a moderately conservative factor
 */
function mapAlertTimeFactor(?int $startTs, ?int $endTs, int $evalTs): array
{
    if ($startTs === null && $endTs === null) {
        return ['factor' => 0.85, 'phase' => 'unknown_time'];
    }

    if ($endTs !== null && $evalTs > $endTs + 3600) {
        return ['factor' => 0.0, 'phase' => 'past'];
    }

    if ($startTs !== null && $evalTs < $startTs) {
        $leadHours = ($startTs - $evalTs) / 3600.0;

        if ($leadHours <= 3)   return ['factor' => 0.85, 'phase' => 'lead_0_3h'];
        if ($leadHours <= 12)  return ['factor' => 0.65, 'phase' => 'lead_3_12h'];
        if ($leadHours <= 24)  return ['factor' => 0.50, 'phase' => 'lead_12_24h'];
        if ($leadHours <= 48)  return ['factor' => 0.35, 'phase' => 'lead_24_48h'];
        return ['factor' => 0.25, 'phase' => 'lead_gt_48h'];
    }

    return ['factor' => 1.0, 'phase' => 'active'];
}

/**
 * Map severity to [0,1]:
 * - If severity_score ∈ [0,1]: finer granularity, linearly map to [0.45, 1.0]
 * - Else use discrete severity: minor/moderate/severe/extreme
 */
function mapSeverityTo01(?string $severity, ?float $severityScore = null): float
{
    if ($severityScore !== null) {
        $x = clamp01((float)$severityScore);
        return 0.45 + 0.55 * $x;
    }

    if ($severity === null) return 0.60;

    switch (cei_strlower_safe(trim($severity))) {
        case 'minor':    return 0.45;
        case 'moderate': return 0.60;
        case 'severe':   return 0.80;
        case 'extreme':  return 0.95;
        default:         return 0.60;
    }
}

/**
 * Extract a factor of the form "prefix:value" from tags.
 * - If the mapping table matches, return the corresponding value
 * - If an unknown value appears, return default
 * - If no tag with the prefix exists, return default
 */
function extractTagFactor(array $tags, string $prefix, array $map, float $default): float
{
    foreach ($tags as $tag) {
        if (!is_string($tag)) continue;
        $needle = $prefix . ':';
        if (strpos($tag, $needle) === 0) {
            $val = cei_strlower_safe(substr($tag, strlen($needle)));
            if (isset($map[$val])) return (float)$map[$val];
            return $default;
        }
    }
    return $default;
}

/**
 * Baseline alert hazard strength (0-1):
 * - Represents how strong an alert of this hazard typically is under "moderate severity + active phase"
 * - Values here are intentionally conservative to avoid treating alerts as hard penalizers
 */
function getAlertHazardBase01(): array
{
    return [
        'extreme_cold'     => 0.75,
        'extreme_heat'     => 0.72,
        'wind'             => 0.70,
        'snow_ice'         => 0.80,
        'heavy_rain'       => 0.72,
        'thunderstorm'     => 0.80,
        'flood'            => 0.90,
        'coastal'          => 0.80,
        'tropical_cyclone' => 0.95,
        'tornado'          => 0.98,
        'fog'              => 0.65,
        'dust_sand'        => 0.70,
        'fire'             => 0.85,
        'air_quality'      => 0.75,
        'avalanche'        => 0.95,
        'geohazard'        => 0.92,
        'other'            => 0.55,
    ];
}

/**
 * Temperature extreme risk (0-100):
 * - cold_score: based on wind chill
 * - heat_score: based on heat index (amplify when dew point is high)
 * - score: max of the two
 *
 * Note: this is "safety/health risk", not "comfort".
 */
function computeTemperatureRiskScore($T, $RH, $wind, $windGust = null, $dewPoint = null, $heatIndex = null)
{
    $flags = [];
    $debug = [];

    $vEff = $wind;
    if ($windGust !== null && is_numeric($windGust) && $windGust > $vEff) {
        $vEff = (float)$windGust;
        $debug[] = 'temp_use_gust';
    }

    $windChill = calculateWindChill((float)$T, (float)$vEff);

    $coldRisk = 0;
    if ($windChill <= -45) { $coldRisk = 95; $flags[] = 'temp_extreme_cold_45'; }
    elseif ($windChill <= -40) { $coldRisk = 90; $flags[] = 'temp_extreme_cold_40'; }
    elseif ($windChill <= -35) { $coldRisk = 80; $flags[] = 'temp_extreme_cold_35'; }
    elseif ($windChill <= -30) { $coldRisk = 65; $flags[] = 'temp_very_cold_30'; }
    elseif ($windChill <= -25) { $coldRisk = 50; $flags[] = 'temp_cold_25'; }
    elseif ($windChill <= -20) { $coldRisk = 35; $flags[] = 'temp_cold_20'; }

    if ($heatIndex === null) {
        $heatIndex = calculateHeatIndex((float)$T, (float)$RH);
    }

    if ($dewPoint !== null && is_numeric($dewPoint) && (float)$dewPoint >= 26) {
        $heatIndex += ((float)$dewPoint >= 29) ? 4 : 2;
        $debug[] = 'heat_dp_boost';
    }

    $heatRisk = 0;
    if ($heatIndex >= 52) { $heatRisk = 90; $flags[] = 'temp_extreme_heat_52'; }
    elseif ($heatIndex >= 41) { $heatRisk = 80; $flags[] = 'temp_extreme_heat_41'; }
    elseif ($heatIndex >= 35) { $heatRisk = 60; $flags[] = 'temp_heat_35'; }
    elseif ($heatIndex >= 32) { $heatRisk = 40; $flags[] = 'temp_heat_32'; }
    elseif ($heatIndex >= 30) { $heatRisk = 28; $flags[] = 'temp_heat_30'; }

    $score = max($coldRisk, $heatRisk);

    return [
        'score'       => max(0, min(100, (int)$score)),
        'cold_score'  => max(0, min(100, (int)$coldRisk)),
        'heat_score'  => max(0, min(100, (int)$heatRisk)),
        'flags'       => $flags,
        'debug_flags' => $debug
    ];
}

/**
 * Weather phenomenon risk (0-100 + hazards 0-1):
 * - Identify snow, freezing rain, thunderstorm, heavy rain, fog, dust/sand, tornado, etc. from weatherId
 * - Introduce wind gust/speed mapping into wind hazard
 * - Apply borderline enhancement for snow/freezing rain around 0°C (more typical road-ice risks)
 *
 * Returns hazards: hazard => risk01 (for fusion as P)
 */
function computeWeatherRiskScore($weatherId, $windGust = null, $T = null, $wind = null)
{
    $score = 0;
    $flags = [];
    $debug = [];
    $haz = [];

    if ($weatherId >= 200 && $weatherId < 300) {
        $haz['thunderstorm'] = 0.75;
        $score = max($score, 70);
        $flags[] = 'wx_thunderstorm';
        if (in_array($weatherId, [212, 221, 232], true)) {
            $haz['thunderstorm'] = 0.85;
            $score = max($score, 75);
            $flags[] = 'wx_thunderstorm_heavy';
        }
    }
    elseif ($weatherId >= 300 && $weatherId < 400) {
        $haz['heavy_rain'] = 0.25;
        $score = max($score, 20);
        $flags[] = 'wx_drizzle';
    }
    elseif ($weatherId >= 500 && $weatherId < 600) {
        if (in_array($weatherId, [500, 520], true)) {
            $haz['heavy_rain'] = 0.35; $score = max($score, 30); $flags[] = 'wx_rain_light';
        } elseif (in_array($weatherId, [501, 521, 531], true)) {
            $haz['heavy_rain'] = 0.55; $score = max($score, 50); $flags[] = 'wx_rain_moderate';
        } elseif (in_array($weatherId, [502, 503, 504, 522], true)) {
            $haz['heavy_rain'] = 0.70; $score = max($score, 60); $flags[] = 'wx_rain_heavy';
        } elseif ($weatherId === 511) {
            $haz['snow_ice'] = 0.80; $score = max($score, 70); $flags[] = 'wx_freezing_rain';
        } else {
            $haz['heavy_rain'] = 0.50; $score = max($score, 45); $flags[] = 'wx_rain';
        }
    }
    elseif ($weatherId >= 600 && $weatherId < 700) {
        if (in_array($weatherId, [600, 615, 620], true)) {
            $haz['snow_ice'] = 0.55; $score = max($score, 40); $flags[] = 'wx_snow_light';
        } elseif (in_array($weatherId, [601, 612, 621], true)) {
            $haz['snow_ice'] = 0.70; $score = max($score, 55); $flags[] = 'wx_snow_moderate';
        } else {
            $haz['snow_ice'] = 0.78; $score = max($score, 65); $flags[] = 'wx_snow_heavy';
        }
    }
    elseif ($weatherId >= 700 && $weatherId < 800) {
        if (in_array($weatherId, [701, 721, 741], true)) {
            $haz['fog'] = 0.55; $score = max($score, 35); $flags[] = 'wx_fog_mist';
        } elseif (in_array($weatherId, [711], true)) {
            $haz['air_quality'] = 0.45; $score = max($score, 35); $flags[] = 'wx_smoke';
        } elseif (in_array($weatherId, [731, 751, 761, 762, 771], true)) {
            $haz['dust_sand'] = 0.70; $score = max($score, 55); $flags[] = 'wx_dust_sand_squall';
        } elseif ($weatherId === 781) {
            $haz['tornado'] = 0.98; $score = max($score, 95); $flags[] = 'wx_tornado';
        } else {
            $haz['fog'] = max($haz['fog'] ?? 0.0, 0.45);
            $score = max($score, 30);
            $flags[] = 'wx_atmosphere';
        }
    }

    // Wind: prefer wind_gust, otherwise wind_speed (if upstream does not provide gust)
    $gust = null;
    if ($windGust !== null && is_numeric($windGust)) $gust = (float)$windGust;
    if ($gust === null && $wind !== null && is_numeric($wind)) $gust = (float)$wind;

    if ($gust !== null) {
        $windRisk01 = mapGustToRisk01($gust);
        if ($windRisk01 > 0.01) {
            $haz['wind'] = max($haz['wind'] ?? 0.0, $windRisk01);
            $debug[] = 'wind_from_gust_or_wind';
        }
    }

    // Snow/freezing rain around ~0°C: more typical icy road risk (borderline enhancement)
    if ($T !== null && is_numeric($T) && isset($haz['snow_ice'])) {
        $t = (float)$T;
        if ($t >= -3.0 && $t <= 1.0) {
            $haz['snow_ice'] = clamp01($haz['snow_ice'] + 0.08);
            $debug[] = 'snow_ice_temp_band';
        }
    }

    // score: also map hazards' risk01 to 0-100, for "compatibility field/coarse overview"
    foreach ($haz as $k => $v) {
        $score = max($score, (int)round(100.0 * clamp01($v)));
    }

    return [
        'score'       => max(0, min(100, (int)$score)),
        'hazards'     => $haz,
        'flags'       => $flags,
        'debug_flags' => $debug
    ];
}

/**
 * Map wind speed/gust to risk (0-1):
 * - Piecewise linear: consider noticeable risk starting from 12 m/s
 * - Tuned more to intuition for walking/driving/high-altitude object risk
 */
function mapGustToRisk01(float $gust_ms): float
{
    if ($gust_ms < 12) return 0.0;
    if ($gust_ms < 15) return lerp01(($gust_ms - 12) / 3.0, 0.15, 0.35);
    if ($gust_ms < 20) return lerp01(($gust_ms - 15) / 5.0, 0.35, 0.55);
    if ($gust_ms < 25) return lerp01(($gust_ms - 20) / 5.0, 0.55, 0.75);
    if ($gust_ms < 32) return lerp01(($gust_ms - 25) / 7.0, 0.75, 0.92);
    return 0.95;
}

/**
 * Air health risk (0-1):
 * - This is "health risk", different from air comfort (comfort score)
 * - Current implementation mainly considers PM2.5 and O3 (others can be extended later)
 *
 * The output risk_01 will be merged into P['air_quality'] to participate in risk fusion and capping.
 */
function computeAirHealthRisk(float $pm25, float $pm10, float $o3, float $co, float $no2, float $so2): array
{
    $r_pm25 = 0.0;
    if     ($pm25 <= 35)  $r_pm25 = 0.0;
    elseif ($pm25 <= 55)  $r_pm25 = lerp01(($pm25 - 35) / 20.0, 0.15, 0.35);
    elseif ($pm25 <= 75)  $r_pm25 = lerp01(($pm25 - 55) / 20.0, 0.35, 0.55);
    elseif ($pm25 <= 150) $r_pm25 = lerp01(($pm25 - 75) / 75.0, 0.55, 0.80);
    elseif ($pm25 <= 250) $r_pm25 = lerp01(($pm25 - 150) / 100.0, 0.80, 0.95);
    else                  $r_pm25 = 0.98;

    $r_o3 = 0.0;
    if     ($o3 <= 100) $r_o3 = 0.0;
    elseif ($o3 <= 160) $r_o3 = lerp01(($o3 - 100) / 60.0, 0.15, 0.35);
    elseif ($o3 <= 200) $r_o3 = lerp01(($o3 - 160) / 40.0, 0.35, 0.55);
    elseif ($o3 <= 300) $r_o3 = lerp01(($o3 - 200) / 100.0, 0.55, 0.80);
    else                $r_o3 = 0.90;

    $r = max($r_pm25, $r_o3);

    return [
        'risk_01' => clamp01($r),
        'debug'   => [
            'pm25_r' => round($r_pm25, 3),
            'o3_r'   => round($r_o3, 3),
        ]
    ];
}

/**
 * Hazard impact weights:
 * - Affect Noisy-OR aggregation: higher weights make the hazard more "sensitive" to the overall risk
 * - Higher for extreme temperature / tropical cyclones / tornadoes; lower for fog, etc.
 */
function getHazardImpactWeights(): array
{
    return [
        'extreme_cold'     => 1.00,
        'extreme_heat'     => 1.00,
        'wind'             => 0.90,
        'snow_ice'         => 0.95,
        'heavy_rain'       => 0.80,
        'thunderstorm'     => 0.85,
        'flood'            => 0.95,
        'coastal'          => 0.85,
        'tropical_cyclone' => 1.00,
        'tornado'          => 1.00,
        'fog'              => 0.60,
        'dust_sand'        => 0.70,
        'fire'             => 0.90,
        'air_quality'      => 0.85,
        'avalanche'        => 1.00,
        'geohazard'        => 0.95,
        'other'            => 0.55,
    ];
}

/**
 * Noisy-OR aggregation:
 * - Each hazard contributes x (0-1), multiplied by weight w
 * - Overall risk = 1 - Π(1 - x*w)
 * Feature: multiple moderate risks add up but will not simply linearly add to "blow up".
 */
function combineHazardsNoisyOR(array $hazards, array $impactW, string $field): float
{
    $prod = 1.0;
    foreach ($hazards as $hz => $info) {
        if (!isset($info[$field])) continue;
        $x = (float)$info[$field];
        $w = (float)($impactW[$hz] ?? 0.6);

        $xw = clamp01($x * $w);
        $prod *= (1.0 - $xw);
    }
    return clamp01(1.0 - $prod);
}

/**
 * Map overall risk r01 (0-1) to risk cap (0-100):
 * - Lower cap means more dangerous (stronger capping)
 * - Use a power function to make the "low-risk area more relaxed, high-risk area more sensitive"
 */
function mapOverallRiskToCap(float $r01): float
{
    $r01 = clamp01($r01);
    $gamma = 1.35;
    $cap = 100.0 * (1.0 - pow($r01, $gamma));
    return max(0.0, min(100.0, $cap));
}

/**
 * Risk hint aggregation (0-1):
 * - For each hazard use h = max(P, A)
 * - Aggregate with Noisy-OR to get H_overall
 * Explanation: hint scores act more like a "reminder system"; may not trigger capping but will warn the user.
 */
function computeHintOverall(array $hazards, array $impactW): float
{
    $prod = 1.0;
    foreach ($hazards as $hz => $info) {
        $p = (float)($info['P'] ?? 0.0);
        $a = (float)($info['A'] ?? 0.0);
        $h = max($p, $a);

        $w = (float)($impactW[$hz] ?? 0.6);
        $hw = clamp01($h * $w);
        $prod *= (1.0 - $hw);
    }
    return clamp01(1.0 - $prod);
}

/**
 * Pick "user-readable risk factors" (hazard list):
 * - Condition: R high enough or Focus high enough
 * - Sort: prioritize Focus, then R
 * - Take up to 8 to avoid UI overload
 */
function pickUserFactors(array $hazards): array
{
    $picked = [];

    foreach ($hazards as $hz => $info) {
        $r = (float)($info['R'] ?? 0.0);
        $f = (float)($info['Focus'] ?? 0.0);

        if ($r >= 0.35 || $f >= 0.45) {
            $picked[] = $hz;
        }
    }

    usort($picked, function($a, $b) use ($hazards) {
        $fa = (float)($hazards[$a]['Focus'] ?? 0.0);
        $fb = (float)($hazards[$b]['Focus'] ?? 0.0);
        if ($fa === $fb) {
            $ra = (float)($hazards[$a]['R'] ?? 0.0);
            $rb = (float)($hazards[$b]['R'] ?? 0.0);
            return ($rb <=> $ra);
        }
        return ($fb <=> $fa);
    });

    $picked = array_values(array_unique($picked));
    return array_slice($picked, 0, 8);
}

/**
 * Max alert contribution (for compatibility field from_alerts):
 * - Estimate the maximum possible influence from the alert side using a*q (strength × credibility)
 * - Note: this is not the direct source of capping; just for explanation/statistics
 */
function maxAlertContribution(array $hazards): float
{
    $m = 0.0;
    foreach ($hazards as $hz => $info) {
        $a = (float)($info['A'] ?? 0.0);
        $q = (float)($info['Q'] ?? 0.0);
        $m = max($m, $a * $q);
    }
    return $m;
}

/**
 * Extract hazard list from tags:
 * - Recognize "hazard:xxx"
 * - Return a de-duplicated array of hazard names
 */
function extractHazardsFromTags(array $tags): array
{
    $hazards = [];
    foreach ($tags as $tag) {
        if (!is_string($tag)) continue;
        if (strpos($tag, 'hazard:') === 0) {
            $h = substr($tag, 7);
            if ($h !== '') $hazards[] = $h;
        }
    }
    return array_values(array_unique($hazards));
}

/**
 * Safe lowercase conversion (UTF-8 friendly).
 */
function cei_strlower_safe(string $str): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($str, 'UTF-8');
    }
    return strtolower($str);
}

/**
 * Clamp float to [0,1].
 */
function clamp01(float $x): float
{
    if ($x < 0.0) return 0.0;
    if ($x > 1.0) return 1.0;
    return $x;
}

/**
 * Linear interpolation (t∈[0,1], returns [a,b]).
 */
function lerp01(float $t, float $a, float $b): float
{
    $t = clamp01($t);
    return $a + ($b - $a) * $t;
}
