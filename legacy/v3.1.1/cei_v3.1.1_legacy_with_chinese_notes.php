<?php

/**
 * CEI v3.1.1 - 舒适环境指数（Comfort Environment Index） (已弃用)
 * -------------------------------------------------------
 * 「Alerts 时效更新」「南半球气候区修正」
 * 
 * !!! 此版本已被 CEI v3.2.0 取代 !!!
 *
 * 本版本将 CEI 明确拆分为两层：
 *   - 环境舒适层（热舒适、空气质量、紫外线、气压）
 *   - 安全风险上限层（极端冷暖、危险天气现象、官方气象预警）
 * 
 * 官方气象预警更新：
 *   - 添加 start_ts 和 end_ts 支持在风险评分中区分未开始、进行中和已过期预警，优化提前预警场景下的风险约束。
 *
 * 最终 CEI 计算公式：
 *   CEI = min(舒适度层 CEI, 安全风险上限 RiskCap)
 *
 * 分数统一规范化为 [0, 100]。
 * 输入字段兼容 OpenWeather / QWeather 风格，但本文件本身不依赖具体数据源，
 * 只要求调用方按约定构造 $data 即可。
 */

/**
 * 计算单一时刻的 CEI 主函数。
 *
 * @param string    $unit      单位制：
 *                             - 'metric'   : °C, m/s
 *                             - 'imperial' : °F, mph
 *                             - 'standard' : K, m/s
 * @param array     $data      输入数据（关联数组），必需字段：
 *                             - temp       : float，气温
 *                             - humidity   : float，相对湿度 (%)
 *                             - wind_speed : float，风速
 *                             - pm2_5, pm10, o3, co, no2, so2 : float，各类污染物浓度
 *                             - uvi        : float，紫外线指数
 *                             - pressure   : float，气压 (hPa)
 *                             可选字段：
 *                             - wind_gust  : float|null，阵风风速
 *                             - dew_point  : float|null，露点温度
 *                             - feels_like : float|null，体感温度（仅用于调试/扩展）
 *                             - weather_id : int|null，OpenWeather 天气代码
 *                             - alerts     : array|null，经适配层标准化后的预警数组
 * @param float     $latitude  纬度（用于判断气候带）
 * @param int       $month     当前月份（1–12，使用 UTC 月份）
 * @param int|null  $weatherId 可选天气代码；为 null 时优先使用 data['weather_id']，否则回退为 800（晴）。
 *
 * @return array {
 *   cei: int 0–100,
 *   level: string 等级描述,
 *   components: {
 *      heat: int 热舒适得分,
 *      air: int 空气舒适得分,
 *      uv: int 紫外线舒适得分,
 *      pressure: int 气压舒适得分,
 *      risk: int 风险得分（0=无风险，100=极端风险）
 *   },
 *   weights: {
 *      heat: float,
 *      air: float,
 *      uv: float,
 *      pressure: float
 *   },
 *   detail: {
 *      comfort_cei: float 仅舒适度层的 CEI,
 *      risk_cap: float    风险上限（100-风险分）,
 *      main_effect: string 当前环境的主要“短板”或限制来源，
 *                          'heat'|'air'|'uv'|'pressure'|'risk',
 *      climate: {
 *          zone: string 气候带名称,
 *          factor: float 气候因子,
 *          comfortTemp: float 基准舒适温度 (°C)
 *      },
 *      thermal: {
 *         effective_temp: float 综合体感温度,
 *         heat_index: float 热指数,
 *         wind_chill: float 风寒温度
 *      },
 *      risk: {
 *         overall: int 总风险分,
 *         from_temp: int 来自温度极端的风险分,
 *         from_weather: int 来自天气现象的风险分,
 *         from_alerts: int 来自官方预警的风险分,
 *         flags: string[] 风险标记（可用于前端展示文案）
 *      }
 *   }
 * }
 */
function computeCEI($unit, $data, $latitude, $month, $weatherId = null)
{
    // --- 1. 基本校验：单位制与必需字段 ------------------------------
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

    // --- 2. 提取原始输入值 --------------------------------------------
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

    // 可选扩展字段
    $windGust  = (isset($data['wind_gust'])  && is_numeric($data['wind_gust']))  ? (float)$data['wind_gust']  : null;
    $dewPoint  = (isset($data['dew_point'])  && is_numeric($data['dew_point']))  ? (float)$data['dew_point']  : null;
    $feelsLike = (isset($data['feels_like']) && is_numeric($data['feels_like'])) ? (float)$data['feels_like'] : null;

    // --- 3. 天气代码与预警数组 -----------------------------------------
    if ($weatherId === null && isset($data['weather_id']) && is_numeric($data['weather_id'])) {
        $weatherId = (int)$data['weather_id'];
    }
    // 默认视为 800（晴）
    if ($weatherId === null) {
        $weatherId = 800;
    } else {
        $weatherId = (int)$weatherId;
    }

    $alerts = [];
    if (isset($data['alerts']) && is_array($data['alerts'])) {
        // 预警需由上层适配器转换成统一结构
        $alerts = $data['alerts'];
    }

    // --- 4. 单位统一：内部统一使用 °C 和 m/s ---------------------------
    if ($unit === 'imperial') {
        // °F → °C
        $T = ($T - 32) * 5 / 9;
        if ($dewPoint !== null)  $dewPoint  = ($dewPoint  - 32) * 5 / 9;
        if ($feelsLike !== null) $feelsLike = ($feelsLike - 32) * 5 / 9;

        // mph → m/s
        $wind = $wind / 2.237;
        if ($windGust !== null)  $windGust  = $windGust / 2.237;
    } elseif ($unit === 'standard') {
        // K → °C
        $T = $T - 273.15;
        if ($dewPoint !== null)  $dewPoint  = $dewPoint - 273.15;
        if ($feelsLike !== null) $feelsLike = $feelsLike - 273.15;
        // 风速本身为 m/s，无需转换
    }
    // metric: 已经是 °C 与 m/s

    // --- 5. 气候上下文（气候带 + 季节因子 + 舒适温度） -----------------
    $climateContext    = getClimateContext($latitude, $month);
    $climateAdjustment = $climateContext['factor'];
    $comfortTemp       = $climateContext['comfortTemp'];

    // --- 6. 动态权重：根据情景调整热/空气/UV/气压权重 ------------------
    $weights = dynamicWeightAdjustment($T, $pm25, $uvi, $wind);

    // --- 7. 热舒适计算 -------------------------------------------------
    $heatIndex = calculateHeatIndex($T, $RH);
    $heatScore = calculateThermalComfort($T, $RH, $wind, $heatIndex, $weatherId, $comfortTemp, $dewPoint);

    // 记录综合体感温度详情
    $windChill     = calculateWindChill($T, max($wind, $windGust ?? $wind));
    $effectiveTemp = ($T >= 20) ? $heatIndex : $windChill;

    // --- 8. 其他舒适组件 ------------------------------------------------
    $airScore   = calculateAirQualityScoreInternational($pm25, $pm10, $o3, $co, $no2, $so2);
    $uvScore    = calculateUVScore($uvi);
    $pressScore = calculatePressureScore($press);

    // 舒适层 CEI：按权重聚合
    $ceiComfort = $weights['heat']  * $heatScore
                + $weights['air']   * $airScore
                + $weights['uv']    * $uvScore
                + $weights['press'] * $pressScore;

    // 乘以气候因子，再做 [0,100] 限幅
    $ceiComfort *= $climateAdjustment;
    $ceiComfort  = max(0, min(100, $ceiComfort));

    // --- 9. 风险层：极端温度 / 天气现象 / 官方预警 ----------------------
    $tempRisk    = computeTemperatureRiskScore($T, $RH, $wind, $windGust, $dewPoint, $heatIndex);
    $weatherRisk = computeWeatherRiskScore($weatherId, $windGust);
    $alertsRisk  = computeAlertsRiskScore($alerts,); // 此处支持传入 $nowTs ，如果不传入，则使用当前 Unix 时间

    // 当前 v3 策略：三者取最大值作为总风险
    $riskScore = max($tempRisk['score'], $weatherRisk['score'], $alertsRisk['score']);
    $riskScore = max(0, min(100, $riskScore));

    // 风险上限（RiskCap）：100 - 风险分，再做 [0,100] 限幅
    $riskCap = 100 - $riskScore;
    $riskCap = max(0, min(100, $riskCap));

    // --- 10. 最终 CEI 与诊断信息 ---------------------------------------
    $ceiFinal = min($ceiComfort, $riskCap);
    $ceiFinal = max(0, min(100, $ceiFinal));

    $componentScores = [
        'heat'     => $heatScore,
        'air'      => $airScore,
        'uv'       => $uvScore,
        'pressure' => $pressScore
    ];

    // main_effect：
    // - 如果风险上限在“压帽”，则认为主要由风险主导
    // - 否则认为“得分最低的舒适组件”为当前环境的“主要短板”
    $minComponent = array_keys($componentScores, min($componentScores))[0];
    $mainEffect   = ($riskCap < $ceiComfort) ? 'risk' : $minComponent;

    // 合并各子模块的风险标记
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
 * 将 CEI 数值映射为分级文案。
 * 该分级为经验设定，后续可根据用户数据校准。
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
 * 根据纬度与月份估算气候上下文信息：
 *  - 气候带（equatorial / tropical / subtropical / temperate / cold_temperate / polar）
 *  - 季节因子 factor（用于整体轻度缩放）
 *  - 基础舒适温度 comfortTemp (°C)
 *
 * 注意：
 *  - 气候带基于 |纬度|，南北半球对称；
 *  - 季节判断使用“本地月份”（南半球做 6 个月平移），避免布里斯班这类城市被误判季节。
 *
 * @param float $latitude 实际纬度
 * @param int   $month    自然月 1–12
 * @return array{zone:string,factor:float,comfortTemp:float}
 */
function getClimateContext($latitude, $month)
{
    $absLat = abs($latitude);

    // 1. 基于绝对纬度划分气候带，并给出基础舒适温度
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

    // 2. 将自然月映射为“本地月份”（南半球做 6 个月平移，模拟反季节）
    $monthNorm = ($month >= 1 && $month <= 12) ? (int)$month : 1;
    if ($latitude < 0) {
        $monthNorm = (($monthNorm + 5) % 12) + 1;
    }

    // 3. 本地夏季 / 冬季对舒适温度的轻微修正
    if (in_array($monthNorm, [6, 7, 8], true)) {
        $comfortTemp += 1;
    } elseif (in_array($monthNorm, [12, 1, 2], true)) {
        $comfortTemp -= 1;
    }

    // 4. 季节因子（轻度缩放），内部也会使用同样的南北半球逻辑
    $factor = adjustForClimate($latitude, $month);

    return [
        'zone'        => $climateZone,
        'factor'      => $factor,
        'comfortTemp' => $comfortTemp,
    ];
}

/**
 * 季节/气候因子，用于对舒适度 CEI 做轻度缩放。
 *
 * 设计原则：
 *  - 使用绝对纬度划分气候带（南北对称）；
 *  - 使用“本地月份”（monthNorm），保证南半球季节与北半球镜像；
 *  - 只对极端/边缘气候带做轻量放大/缩小，避免过度干预 CEI 主体分数。
 *
 * @param float $latitude 实际纬度
 * @param int   $month    自然月 1–12
 * @return float          季节缩放系数
 */
function adjustForClimate($latitude, $month)
{
    $absLat = abs($latitude);

    if ($absLat < 10) {
        $climateZone = 'equatorial';
    } elseif ($absLat < 23.5) {
        $climateZone = 'tropical';
    } elseif ($absLat < 35) {
        $climateZone = 'subtropical';
    } elseif ($absLat < 55) {
        $climateZone = 'temperate';
    } elseif ($absLat < 66.5) {
        $climateZone = 'cold_temperate';
    } else {
        $climateZone = 'polar';
    }

    // 将自然月转换为“本地月份”
    $monthNorm = ($month >= 1 && $month <= 12) ? (int)$month : 1;
    if ($latitude < 0) {
        $monthNorm = (($monthNorm + 5) % 12) + 1;
    }

    $seasonFactor = 1.0;

    // 本地夏季（6–8 月）
    if (in_array($monthNorm, [6, 7, 8], true)) {
        if ($climateZone === 'tropical') {
            $seasonFactor = 1.1;
        }
        elseif (in_array($climateZone, ['polar', 'cold_temperate'], true)) {
            $seasonFactor = 0.9;
        }
    }
    // 本地冬季（12–2 月）
    elseif (in_array($monthNorm, [12, 1, 2], true)) {
        if ($climateZone === 'tropical') {
            $seasonFactor = 0.9;
        }
        elseif (in_array($climateZone, ['polar', 'cold_temperate'], true)) {
            $seasonFactor = 1.1;
        }
    }

    return $seasonFactor;
}

/**
 * 动态权重调整：
 * 根据温度、PM2.5、紫外线、风速，对热/空气/UV/气压四类权重进行轻量调节，
 * 再归一化为和为 1。
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

    // 冷热环境：热舒适权重升高
    if ($T > 30) {
        $weights['heat'] = 0.5;
    } elseif ($T < 15) {
        $weights['heat'] = 0.6;
    }

    // 大风：热舒适更关键
    if ($wind > 8) {
        $weights['heat'] += 0.05;
    }
    if ($wind > 12) {
        $weights['heat'] += 0.05;
    }

    // 雾霾环境：空气质量权重升高
    if ($pm25 > 35) {
        $weights['air'] = 0.5;
    }

    // 强紫外线
    if ($uvi > 8) {
        $weights['uv'] = 0.2;
    }

    // 设置最小权重并归一化
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
 * 热指数 (Heat Index) 计算，单位为 °C。
 * 当 T < 20°C 时，热指数不具有意义，直接返回 T。
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
 * 风寒温度计算，使用加拿大/美国标准公式。
 *
 * 输入：
 *  - T: °C
 *  - wind: m/s
 * 仅在 T < 10°C 且 wind > 1.3 m/s 时生效，否则返回原始 T。
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
 * 温度舒适曲线：根据“综合体感温度相对于舒适温度的偏差”给出得分。
 * 将偏差分为多个区间，近似为分段线性/平台下降。
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
 * 热舒适主函数：
 *  综合考虑：
 *   - 温度与气候带基准舒适温度的偏差
 *   - 湿度偏离 50% 的程度
 *   - 风速（大风带来的不适）
 *   - 热指数与露点带来的闷热感
 *   - OpenWeather weatherId 带来的天气现象不适惩罚（仅限舒适层）
 *
 * 返回 0–100 之间的热舒适得分。
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
    // 综合体感温度：暖季用热指数，冷季用风寒
    if ($T >= 20) {
        $effectiveTemp = $heatIndex;
    } else {
        $effectiveTemp = calculateWindChill($T, $wind);
    }

    $tempComfort = thermalComfortCurve($effectiveTemp, $comfortTemp);

    // 湿度舒适：以 50% 为中心
    $humidityComfort = 100 - min(60, abs($RH - 50) * 1.2);

    // 高露点 + 高温：额外闷热惩罚
    if ($dewPoint !== null && $T >= 20 && $dewPoint >= 24) {
        $extra = min(15, ($dewPoint - 23) * 1.5);
        $humidityComfort = max(20, $humidityComfort - $extra);
    }

    // 风舒适：微风最舒适，大风显著降低舒适度
    if ($wind <= 3) {
        $windComfort = 100;
    } else {
        $windComfort = max(20, 100 - ($wind - 3) * 10);
    }

    // 热指数额外惩罚：明显偏高时按斜率扣分
    if ($heatIndex <= 27) {
        $heatComfort = 100;
    } else {
        $heatComfort = max(20, 100 - ($heatIndex - 27) * 8);
    }

    // 汇总热舒适得分
    $comfortScore = 0.5 * $tempComfort
                  + 0.25 * $humidityComfort
                  + 0.15 * $windComfort
                  + 0.10 * $heatComfort;

    // 基于天气现象的额外不适惩罚（雨雪雷暴、雾霾等）
    $weatherPenalty = getWeatherDiscomfortPenalty($weatherId);
    $comfortScore  -= $weatherPenalty;

    return max(0, min(100, $comfortScore));
}

/**
 * 基于 OpenWeather weatherId 的天气现象不适惩罚（仅作用于舒适层）。
 * 返回 [0,25] 范围的 penalty，数值越大表示越不舒适。
 *
 * @param int $weatherId
 * @return int
 */
function getWeatherDiscomfortPenalty($weatherId) {
    $penalty = 0;

    if ($weatherId >= 200 && $weatherId < 300) {
        // 雷暴
        if (in_array($weatherId, [212, 221, 232], true)) {
            $penalty = 20;
        } else {
            $penalty = 15;
        }
    } elseif ($weatherId >= 300 && $weatherId < 400) {
        // 毛毛雨
        $penalty = 6;
    } elseif ($weatherId >= 500 && $weatherId < 600) {
        // 雨
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
        // 雪
        if (in_array($weatherId, [600, 615, 620], true)) {
            $penalty = 12;
        } elseif (in_array($weatherId, [601, 612, 621], true)) {
            $penalty = 16;
        } else {
            $penalty = 20;
        }
    } elseif ($weatherId >= 700 && $weatherId < 800) {
        // 大气现象（雾、霾、沙尘等）
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
        // 晴
        $penalty = 0;
    } elseif ($weatherId >= 801 && $weatherId <= 804) {
        // 多云
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
 * 多污染物空气质量舒适得分。
 * 每种污染物分别打分后取最差值作为整体空气舒适分。
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

    // CO: µg/m³ → mg/m³
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

    // 取最差项作为综合空气舒适得分
    return min($scores);
}

/**
 * 通用污染物打分函数。
 * $thresholds 形如：
 *   [
 *     [上限浓度, 对应得分],
 *     ...
 *   ]
 *
 * @param float $concentration
 * @param array $thresholds
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
 * 紫外线舒适得分。
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
 * 气压舒适得分，以 1013.25 hPa 为参考。
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

    // 偏差超过 25 hPa 后快速下降，但保留 40 分下限
    return max(40, 100 - $deviation * 2);
}

/**
 * 温度相关风险评分（极端冷/热），代表生命/健康层面的风险，而不是单纯不适。
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

    // 有阵风时使用更大的有效风速
    $vEff  = $wind;
    if ($windGust !== null && is_numeric($windGust) && $windGust > $vEff) {
        $vEff = $windGust;
    }

    $windChill = calculateWindChill($T, $vEff);

    // 基于风寒的低温风险区间
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

    // 基于热指数的高温风险区间
    if ($heatIndex === null) {
        $heatIndex = calculateHeatIndex($T, $RH);
    }

    // 高露点对高温风险的放大
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
 * 基于天气现象（weatherId）及强阵风的风险评分。
 * 用于反映暴雨/暴雪/沙尘/龙卷风等天气对应的安全风险。
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
        // 雷暴
        if (in_array($weatherId, [212, 221, 232], true)) {
            $score = 70;
            $flags[] = 'wx_thunderstorm_heavy';
        } else {
            $score = 60;
            $flags[] = 'wx_thunderstorm';
        }
    }
    elseif ($weatherId >= 300 && $weatherId < 400) {
        // 毛毛雨
        $score = 20;
        $flags[] = 'wx_drizzle';
    }
    elseif ($weatherId >= 500 && $weatherId < 600) {
        // 雨
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
        // 雪
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
        // 大气现象
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

    // 强阵风带来的额外风险（接近或超过大风、烈风级别）
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
 * 基于官方天气预警（alerts）的风险评分。
 *
 * 预警需先由适配层转换为统一结构（与数据源无关）：
 *
 *  每条 alert 为一个关联数组：
 *  [
 *    'event'          => string|null,   // 标题（可选）
 *    'description'    => string|null,   // 描述（可选）
 *    'tags'           => string[],      // 'hazard:*', 'severity:*', 'color:*', 'provider:*'
 *    'severity'       => string|null,   // 'minor'|'moderate'|'severe'|'extreme'|'unknown'
 *    'severity_score' => float|null,    // （可选）外部模型输出的 [0,1] 连续严重度
 *    'code'           => int|null,      // 数据源内部预警代码（如 QWeather）
 *    'start_ts'       => int|null,      // 预警开始时间（UTC 秒）
 *    'end_ts'         => int|null       // 预警结束时间（UTC 秒）
 *  ]
 *
 * 时间相关逻辑在本函数内部统一处理：
 *  - 已过期预警（now_ts 远大于 end_ts）风险为 0；
 *  - 正在生效的预警使用完整权重；
 *  - 尚未生效的预警按距开始时间的提前量衰减。
 *
 * @param array      $alerts  规范化后的预警数组
 * @param int|null   $nowTs   当前时间（UTC 秒）。为 null 时使用 time()。
 *
 * @return array{score:int,flags:string[]}
 */
function computeAlertsRiskScore(array $alerts, ?int $nowTs = null)
{
    if (empty($alerts)) {
        return ['score' => 0, 'flags' => []];
    }

    if ($nowTs === null) {
        $nowTs = time();
    }

    // 各类灾害的基准风险（约对应“中等等级”预警，且正在生效）
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

        $tags          = isset($alert['tags']) && is_array($alert['tags']) ? $alert['tags'] : [];
        $hazards       = extractHazardsFromTags($tags);
        $severity      = isset($alert['severity']) ? (string)$alert['severity'] : null;
        $severityScore = isset($alert['severity_score']) && is_numeric($alert['severity_score'])
                       ? (float)$alert['severity_score'] : null;

        $startTs = isset($alert['start_ts']) && is_numeric($alert['start_ts']) ? (int)$alert['start_ts'] : null;
        $endTs   = isset($alert['end_ts'])   && is_numeric($alert['end_ts'])   ? (int)$alert['end_ts']   : null;

        if (empty($hazards)) {
            $hazards = ['other'];
        }

        // 1) 时间因子：未来/当前/已过期
        $timeInfo   = mapAlertTimeFactor($startTs, $endTs, $nowTs);
        $timeFactor = $timeInfo['factor'];

        if ($timeFactor <= 0.0) {
            $allFlags[] = 'phase_past';
            continue;
        }

        // 2) 严重程度因子
        $sevFactor = mapSeverityToFactor($severity, $severityScore);

        foreach ($hazards as $h) {
            if (!isset($hazardBaseRisk[$h])) {
                $h = 'other';
            }

            $base = $hazardBaseRisk[$h];

            // 3) 综合风险 = 基准 × 严重度 × 时间因子
            $risk = (int)round($base * $sevFactor * $timeFactor);
            $risk = max(0, min(100, $risk));

            $overallScore = max($overallScore, $risk);

            $allFlags[] = 'alert_' . $h;

            if ($severity !== null) {
                $allFlags[] = 'severity_' . cei_strlower_safe($severity);
            }
            if ($timeInfo['phase'] !== '') {
                $allFlags[] = 'phase_' . $timeInfo['phase'];
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
 * 从 tags 数组中提取灾害类型 hazard 列表。
 *
 * 例如：
 *   ['hazard:wind', 'hazard:flood', 'severity:severe', 'provider:qweather']
 * 返回：
 *   ['wind', 'flood']
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
 * 将预警严重程度（文本或模型分数）映射为缩放因子。
 *
 * 逻辑：
 *  - 若提供 severity_score ∈ [0,1]，则线性映射到 [0.7, 1.4]
 *  - 否则根据 severity 字段 'minor'/'moderate'/'severe'/'extreme' 映射固定因子
 *
 * @param string|null $severity
 * @param float|null  $severityScore
 * @return float
 */
function mapSeverityToFactor(?string $severity, ?float $severityScore = null): float
{
    if ($severityScore !== null) {
        $x = max(0.0, min(1.0, $severityScore));
        return 0.7 + 0.7 * $x;
    }

    if ($severity === null) {
        return 1.0;
    }

    switch (cei_strlower_safe(trim($severity))) {
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
 * 时间因子映射：
 *
 * - 若已明确结束且超过 1 小时：factor = 0，phase = 'past'
 * - 若正在生效：factor = 1.0，phase = 'active'
 * - 若尚未开始：按提前量衰减，对“未来预警”保留一定约束但弱于当前
 *
 * @param int|null $startTs  预警生效开始时间（UTC 秒）
 * @param int|null $endTs    预警结束时间（UTC 秒）
 * @param int      $nowTs    当前时间（UTC 秒）
 * @return array{factor:float,phase:string}
 */
function mapAlertTimeFactor(?int $startTs, ?int $endTs, int $nowTs): array
{
    // 完全未知时间：视为“当前/临近”，给 1.0，但标记 unknown_time
    if ($startTs === null && $endTs === null) {
        return ['factor' => 1.0, 'phase' => 'unknown_time'];
    }

    // 有结束时间且明显已结束（留 1 小时缓冲）
    if ($endTs !== null && $nowTs > $endTs + 3600) {
        return ['factor' => 0.0, 'phase' => 'past'];
    }

    // 若有开始时间且当前在开始之前 → 未来预警
    if ($startTs !== null && $nowTs < $startTs) {
        $leadHours = ($startTs - $nowTs) / 3600.0;

        if ($leadHours <= 3) {
            return ['factor' => 0.8, 'phase' => 'lead_0_3h'];
        } elseif ($leadHours <= 12) {
            return ['factor' => 0.6, 'phase' => 'lead_3_12h'];
        } elseif ($leadHours <= 24) {
            return ['factor' => 0.45, 'phase' => 'lead_12_24h'];
        } elseif ($leadHours <= 48) {
            return ['factor' => 0.3, 'phase' => 'lead_24_48h'];
        } else {
            return ['factor' => 0.2, 'phase' => 'lead_gt_48h'];
        }
    }

    // 其他情况（已经开始但未结束 / 只有结束时间但尚未过期 / 只有开始时间且 now >= start）
    return ['factor' => 1.0, 'phase' => 'active'];
}

/**
 * 字符串小写转换工具
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