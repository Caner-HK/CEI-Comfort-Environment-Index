<?php
/**
 * CEI 3.2.0 - 舒适环境指数（Comfort Environment Index）
 * -------------------------------------------------------
 * 「风险层相关性感知融合」「预警与体感协同」「forecast 时刻评估 ts 支持」
 *
 * 本版本将 CEI 明确拆分为两层：
 *   - 环境舒适层（热舒适、空气质量、紫外线、气压）
 *   - 安全风险上限层（体感/现象/预警的协同融合，输出 RiskCap 作为“压帽”上限）
 *
 * 风险层设计要点（关键修复）：
 *   1) 相关性感知融合（Hazard Bucket Fusion）：
 *      - 将风险按 hazard 分桶（如 extreme_cold、snow_ice、wind、heavy_rain、thunderstorm 等），
 *      - 对每个 hazard 同时计算：
 *          P：Physical 物理风险（由温度/风寒/热指数、weather_id、风阵等体感数据推导）
 *          A：Alert 预警信号强度（由 alerts 的 hazard + severity + 时间相位推导）
 *          Q：Alert 可信度/命中度（由 certainty/urgency/area/time phase 等标签综合）
 *      - 最终融合强度 R：预警只“补缺口”，避免同源重复扣分：
 *          R = P + Q * max(0, A - P)
 *        这样当体感数据已显示低温极端时，“寒潮预警”不会再次把风险硬拉满，
 *        但会提升该 hazard 的关注度 Focus（用于提示/建议更谨慎）。
 *
 *   2) 预警时间相位与 forecast 评估时刻：
 *      - 新增 data['ts'] 作为评估时刻（UTC 秒），用于 forecast 场景在“未来某时刻”计算 CEI。
 *      - 风险层内部用 ts 判断预警处于：
 *          lead（未开始）/ active（生效中）/ past（已过期），并对预警强度做衰减或归零。
 *      - 若未提供 ts，默认使用 time()。
 *
 *   3) 风险输出分离（便于 UI 与解释）：
 *      - risk（overall）：用于表示总体风险强度（0=无风险，100=极端风险）
 *      - risk_cap：真正用于压帽的上限分（0–100）
 *      - risk_hint：更敏感的“提示分”，更倾向于 max(P, A)（适合提醒但不一定压帽）
 *      - risk_focus：关注度分（当 P 与 A 一致且 Q 较高时更高，用于“重点关注”）
 *
 * 最终 CEI 计算公式：
 *   CEI = min(舒适度层 CEI, 安全风险上限 RiskCap)
 *
 * 分数统一规范化为 [0, 100]。
 * 输入字段兼容 OpenWeather / QWeather 风格，但本文件本身不依赖具体数据源，
 * 只要求调用方按约定构造 $data 即可。
 */

/**
 * 输出结构示例：
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
 * 计算单一时刻的 CEI 主函数。
 *
 * @param string    $unit      单位制：
 *                             - 'metric'   : °C, m/s
 *                             - 'imperial' : °F, mph
 *                             - 'standard' : K, m/s
 * @param array     $data      输入数据，必需字段：
 *                             - temp       : float，气温
 *                             - humidity   : float，相对湿度 (%)
 *                             - wind_speed : float，风速
 *                             - pm2_5, pm10, o3, co, no2, so2 : float，各类污染物浓度
 *                             - uvi        : float，紫外线指数
 *                             - pressure   : float，气压 (hPa)
 *                             可选字段：
 *                             - ts         : int|null，评估时刻（UTC 秒；forecast 场景建议传入）
 *                             - wind_gust  : float|null，阵风风速
 *                             - dew_point  : float|null，露点温度
 *                             - feels_like : float|null，体感温度（仅用于调试/扩展）
 *                             - weather_id : int|null，OpenWeather 天气代码
 *                             - alerts     : array|null，经适配层标准化后的预警数组
 * @param float     $latitude  纬度（用于判断气候带）
 * @param int       $month     当前月份（1–12）
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
 *      risk: int 风险强度（0=无风险，100=极端风险）
 *   },
 *   weights: {
 *      heat: float,
 *      air: float,
 *      uv: float,
 *      pressure: float
 *   },
 *   detail: {
 *      comfort_cei: float 仅舒适度层的 CEI,
 *      risk_cap: float    风险上限（0–100，真正用于压帽）,
 *      risk_hint: int     风险提示分（0–100，更敏感，用于提醒）,
 *      risk_focus: int    风险关注度（0–100，用于“重点关注”）,
 *      main_effect: string 当前环境主要限制来源，
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
 *         overall: int 风险强度（0–100）,
 *         cap: float 风险上限,
 *         hint: int 风险提示分,
 *         focus: int 风险关注度,
 *         from_temp: int 来自温度极端的风险贡献,
 *         from_weather: int 来自天气现象的风险贡献,
 *         from_alerts: int 来自官方预警的风险贡献,
 *         factors: string[] 用户可读风险因素（建议前端展示，hazard 列表）,
 *         debug_flags: string[] 内部调试标记（不建议作为“风险因素”展示）,
 *         hazards: array hazard 分桶详情（P/A/Q/R/Focus，用于解释与可视化）
 *
 *      }
 *   }
 * }
 */
function computeCEI($unit, $data, $latitude, $month, $weatherId = null)
{
    // 1) 单位制校验
    if (!in_array($unit, ['imperial', 'metric', 'standard'], true)) {
        return ['error' => 'Invalid unit type'];
    }

    // 2) 必需字段校验
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

    // 3) 评估时刻：forecast 场景建议传入未来某一时刻的 ts（UTC 秒），用于预警时间相位判断
    $evalTs = (isset($data['ts']) && is_numeric($data['ts'])) ? (int)$data['ts'] : time();

    // 4) 抽取核心气象与污染输入
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

    // 5) 可选字段
    $windGust  = (isset($data['wind_gust'])  && is_numeric($data['wind_gust']))  ? (float)$data['wind_gust']  : null;
    $dewPoint  = (isset($data['dew_point'])  && is_numeric($data['dew_point']))  ? (float)$data['dew_point']  : null;
    $feelsLike = (isset($data['feels_like']) && is_numeric($data['feels_like'])) ? (float)$data['feels_like'] : null;

    // 6) 天气现象：优先使用入参 weatherId，其次回退 data['weather_id']，最终默认晴（800）
    if ($weatherId === null && isset($data['weather_id']) && is_numeric($data['weather_id'])) {
        $weatherId = (int)$data['weather_id'];
    }
    $weatherId = ($weatherId === null) ? 800 : (int)$weatherId;

    // 7) 预警：要求上游“预警适配层”已将不同数据源统一成 alerts 结构
    $alerts = [];
    if (isset($data['alerts']) && is_array($data['alerts'])) {
        $alerts = $data['alerts'];
    }

    // 8) 单位转换：内部统一使用 °C 与 m/s（污染物与 uvi、pressure 默认已按约定单位）
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

    // 9) 气候上下文：同一温度在不同纬度/季节的人体习惯不同，用 comfortTemp 做“舒适锚点”
    $climateContext    = getClimateContext($latitude, $month);
    $climateAdjustment = $climateContext['factor'];
    $comfortTemp       = $climateContext['comfortTemp'];

    // 10) 动态权重：把“当前最影响体感的维度”权重稍微抬高
    $weights = dynamicWeightAdjustment($T, $pm25, $uvi, $wind);

    // 11) 热舒适：热指数/风寒温度 + 湿度/风影响 + 天气现象惩罚
    $heatIndex = calculateHeatIndex($T, $RH);
    $heatScore = calculateThermalComfort($T, $RH, $wind, $heatIndex, $weatherId, $comfortTemp, $dewPoint);

    // 12) 输出用的体感诊断（effective_temp）：暖季用 heatIndex，冷季用 windChill
    $windChill     = calculateWindChill($T, max($wind, $windGust ?? $wind));
    $effectiveTemp = ($T >= 20) ? $heatIndex : $windChill;

    // 13) 其他舒适组件：空气/紫外线/气压
    $airScore   = calculateAirQualityScoreInternational($pm25, $pm10, $o3, $co, $no2, $so2);
    $uvScore    = calculateUVScore($uvi);
    $pressScore = calculatePressureScore($press);

    // 14) 舒适度层聚合：加权求和 + 气候因子缩放
    $ceiComfort = $weights['heat']  * $heatScore
                + $weights['air']   * $airScore
                + $weights['uv']    * $uvScore
                + $weights['press'] * $pressScore;

    $ceiComfort *= $climateAdjustment;
    $ceiComfort  = max(0, min(100, $ceiComfort));

    // 15) 风险层：把“体感数据”与“预警信号”按 hazard 分桶融合，得到 risk_cap / hint / focus 等
    $riskLayer = computeRiskLayer([
        'T' => $T, 'RH' => $RH, 'wind' => $wind, 'windGust' => $windGust,
        'dewPoint' => $dewPoint, 'heatIndex' => $heatIndex,
        'weatherId' => $weatherId,
        'pm25' => $pm25, 'pm10' => $pm10, 'o3' => $o3, 'co' => $co, 'no2' => $no2, 'so2' => $so2,
        'alerts' => $alerts,
        'evalTs' => $evalTs,
    ]);

    // 16) 风险输出：risk_score 为强度（0-100）；risk_cap 为压帽上限（0-100，越低越“危险”）
    $riskScore = (int)round($riskLayer['risk_score']);
    $riskCap   = (float)$riskLayer['risk_cap'];
    $riskHint  = (int)round($riskLayer['risk_hint_score']);
    $riskFocus = (int)round($riskLayer['risk_focus_score']);

    // 17) 最终 CEI：取舒适层与风险压帽的较小者
    $ceiFinal = min($ceiComfort, $riskCap);
    $ceiFinal = max(0, min(100, $ceiFinal));

    // 18) main_effect：若被风险压帽，则主要影响来自 risk；否则为最短板的舒适组件
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

                // 用户可读的“风险因素”（建议前端展示它；它是 hazard 名称列表）
                'factors'      => $riskLayer['factors'],

                // 内部调试标记（建议只在 debug 面板展示，不要当作“风险因素”）
                'debug_flags'  => $riskLayer['debug_flags'],

                // hazard 分桶详情：每个 hazard 的 P/A/Q/R/Focus（用于解释与可视化）
                'hazards'      => $riskLayer['hazards'],
            ]
        ]
    ];
}

/**
 * 将 CEI 数值映射为等级文案。
 * 此分级为经验阈值。
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
 * 估算气候上下文：
 * - zone：按 |纬度| 分带（南北对称）
 * - comfortTemp：该气候带的“基准舒适温度”（用于热舒适锚点）
 * - factor：季节/气候轻度缩放（避免把舒适层过拟合到单一温区）
 *
 * 注意：南半球月份做 6 个月平移（反季节）以避免季节误判。
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
 * 季节/气候因子（轻度缩放）：
 * - 热带在盛夏更容易“闷热不适”（factor 略抬）
 * - 高纬寒区在严冬“体感更苛刻”（factor 略抬），在夏季则略降
 * 目标是“轻量修正”，不抢走主模型（舒适层+风险层）的表达权。
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
 * 动态权重：把“当前更显著的体感维度”权重略抬，并做归一化。
 * - 冷/热：热舒适权重上升（体感主导更明显）
 * - 大风：热舒适权重上升（风寒/风阻）
 * - PM2.5 高：空气权重上升（健康/呼吸不适）
 * - 强 UV：UV 权重上升（暴晒风险/不适）
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
 * 热指数 Heat Index（°C）：
 * - 主要适用于暖热条件（T >= 20°C）
 * - 低温时返回 T（避免不必要的“热指数噪声”）
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
 * 风寒 Wind Chill（°C）：
 * - 仅在 T < 10°C 且 wind > 1.3 m/s 时生效
 * - 其余情况返回原始 T
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
 * 温度舒适曲线（0-100）：
 * - 以 comfortTemp 为中心，偏离越大扣分越多
 * - 分段是为了“可解释、稳定、易调参”
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
 * 热舒适主函数（0-100）：
 * - 有效温度：暖季 HeatIndex；冷季 WindChill
 * - 湿度：围绕 50% 最舒适，露点高时额外闷热惩罚
 * - 风：微风最舒适，大风降低舒适
 * - 天气现象：用 weatherId 做额外不适惩罚（只作用于舒适层，不进入风险层）
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
 * 天气现象不适惩罚（0-25）：
 * - 只影响“舒适层”，表达“雨雪雷暴雾霾让人不舒服”
 * - 不用于风险压帽（风险压帽由 computeRiskLayer 负责，更关注安全/健康层面）
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
 * 空气质量舒适得分（0-100）：
 * - 每种污染物分别映射得分
 * - 取最差项作为总体得分（短板效应）
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

    // CO：输入为 µg/m³，换算到 mg/m³ 进行阈值打分
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
 * 通用污染物得分映射（分段阈值）。
 */
function calculatePollutantScore($concentration, $thresholds) {
    foreach ($thresholds as $threshold) {
        if ($concentration <= $threshold[0]) return $threshold[1];
    }
    return 10;
}

/**
 * 紫外线舒适得分（0-100）：
 * - UVI 越高越不舒适（且存在晒伤风险）
 */
function calculateUVScore($uvi) {
    if ($uvi <= 2) return 100;
    if ($uvi <= 5) return 85;
    if ($uvi <= 7) return 70;
    if ($uvi <= 10) return 55;
    return 40;
}

/**
 * 气压舒适得分（0-100）：
 * - 以 1013.25 hPa 为基准
 * - 偏离越大越不舒适，但保留下限
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
 * 风险层主函数（Hazard Bucket Fusion）：
 * 输入 ctx：
 * - 体感数据：T/RH/wind/windGust/dewPoint/heatIndex/weatherId
 * - 健康数据：pm25/pm10/o3/co/no2/so2
 * - 官方预警：alerts（已适配成统一结构）
 * - 评估时刻：evalTs（用于 forecast）
 *
 * 输出：
 * - risk_score：总体风险强度（0-100）
 * - risk_cap：风险压帽上限（0-100，越低越危险）
 * - risk_hint_score：风险提示分（更敏感）
 * - risk_focus_score：关注度（用于“重点关注”）
 * - hazards：每个 hazard 的 P/A/Q/R/Focus
 * - factors：用户可读风险因素（建议前端展示）
 * - debug_flags：内部调试标记
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

    // 1) 体感侧风险（Physical P）：温度极端、天气现象、空气健康风险
    $tempRisk    = computeTemperatureRiskScore($T, $RH, $wind, $windGust, $dewPoint, $heatIndex);
    $weatherRisk = computeWeatherRiskScore($weatherId, $windGust, $T, $wind);
    $airHealth   = computeAirHealthRisk($pm25, $pm10, $o3, $co, $no2, $so2);

    // 2) 构建 P（0-1）：按 hazard 分桶
    $P = [];
    $P['extreme_cold'] = clamp01($tempRisk['cold_score'] / 100.0);
    $P['extreme_heat'] = clamp01($tempRisk['heat_score'] / 100.0);

    foreach ($weatherRisk['hazards'] as $hz => $r01) {
        $P[$hz] = max($P[$hz] ?? 0.0, clamp01($r01));
    }

    $P['air_quality'] = max($P['air_quality'] ?? 0.0, clamp01($airHealth['risk_01']));

    // 3) 预警侧信号（Alert A/Q）：A=强度(0-1)，Q=可信度/命中度(0-1)
    $alertSignals = computeAlertHazardSignals($alerts, $evalTs);
    $A = $alertSignals['A'];
    $Q = $alertSignals['Q'];

    // 4) 融合：R = P + Q * max(0, A - P)
    //    解释：预警只在“高于体感”时补缺口，避免同源重复扣分
    $hazards = [];
    $betaFocus = 0.35;

    $hzKeys = array_values(array_unique(array_merge(array_keys($P), array_keys($A))));
    sort($hzKeys);

    foreach ($hzKeys as $hz) {
        $p = (float)($P[$hz] ?? 0.0);
        $a = (float)($A[$hz] ?? 0.0);
        $q = (float)($Q[$hz] ?? 0.0);

        $r = $p + $q * max(0.0, ($a - $p));

        // 5) 边界修正：当体感处于“临界”且预警强、可信度高时，允许小幅提高 R
        //    目的：提升“道路结冰、雪、风”等低温临界场景的可信警觉性（但仍不硬拉满）
        if ($p >= 0.35 && $p <= 0.65 && $a >= 0.75 && $q >= 0.60) {
            $r += 0.05 * ($a - $p);
            $debug[] = 'borderline_boost_' . $hz;
        }

        $r = clamp01($r);

        // 6) 关注度 Focus：更偏提醒用途，强调“只要有一边很高就值得关注”
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

    // 7) 总体聚合：Noisy-OR（不会被单一项“线性加爆”，更适合多风险并存的概率式聚合）
    $impactW = getHazardImpactWeights();
    $R_overall     = combineHazardsNoisyOR($hazards, $impactW, 'R');
    $Focus_overall = combineHazardsNoisyOR($hazards, $impactW, 'Focus');

    // 8) risk_score：风险强度（越高越危险）；risk_cap：压帽上限（越低越危险）
    $risk_score = 100.0 * $R_overall;
    $risk_cap   = mapOverallRiskToCap($R_overall);

    // 9) risk_hint：提示分（更敏感），用 max(P,A) 聚合（更像“提醒系统”）
    $H_overall = computeHintOverall($hazards, $impactW);
    $risk_hint_score = 100.0 * $H_overall;

    // 10) factors：用户可读风险因素（hazard 列表），建议前端将其作为“风险因素”展示
    $factors = pickUserFactors($hazards);

    // 11) 兼容字段：用于前端已有结构，或用于解释“风险主要来自哪里”
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

    // 12) debug_flags：内部调试标记集合（不要作为“风险因素”展示）
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
 * 从 alerts 计算每个 hazard 的：
 * - A：预警强度（0-1）：hazard_base * severity * timeFactor
 * - Q：预警可信度/命中度（0-1）：timeFactor * certainty * urgency * area
 *
 * 说明：
 * - A 表达“预警本身有多强”
 * - Q 表达“这条预警在当前评估时刻是否可信、是否值得采纳”
 * - 最终是否影响压帽由融合公式 R 决定（只补缺口，不重复扣分）
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

        // tags：应由适配层统一（hazard:*, severity:*, certainty:*, urgency:*, area:* 等）
        $tags = (isset($alert['tags']) && is_array($alert['tags'])) ? $alert['tags'] : [];
        $hazards = extractHazardsFromTags($tags);
        if (empty($hazards)) $hazards = ['other'];

        $severity      = isset($alert['severity']) ? (string)$alert['severity'] : null;
        $severityScore = (isset($alert['severity_score']) && is_numeric($alert['severity_score']))
            ? (float)$alert['severity_score'] : null;

        // start/end：UTC 秒；用于 forecast 的 evalTs 做相位判断
        $startTs = (isset($alert['start_ts']) && is_numeric($alert['start_ts'])) ? (int)$alert['start_ts'] : null;
        $endTs   = (isset($alert['end_ts'])   && is_numeric($alert['end_ts']))   ? (int)$alert['end_ts']   : null;

        $timeInfo = mapAlertTimeFactor($startTs, $endTs, $evalTs);
        $tFactor  = $timeInfo['factor'];
        $phase    = $timeInfo['phase'];

        // 已过期：直接跳过
        if ($tFactor <= 0.0) {
            $debug[] = 'phase_past';
            continue;
        }

        // 严重度映射到 0-1（更利于与 P/Q 一起融合）
        $sev01 = mapSeverityTo01($severity, $severityScore);

        // 可信度/命中度因子：来自 tags（若缺失则用默认值）
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

        // Q：综合可信度（越接近 1 越可信）
        $q = clamp01($tFactor * $certainty01 * $urgency01 * $area01);

        // 每个 hazard 的基准强度（0-1），大致对应“中等等级、活跃相位”的量级
        $hazardBase = getAlertHazardBase01();

        foreach ($hazards as $hz) {
            $base = $hazardBase[$hz] ?? $hazardBase['other'];

            // A：预警强度（用 tFactor 做轻度参与，避免 lead 阶段过度强）
            $a = $base * $sev01 * (0.65 + 0.35 * $tFactor);
            $a = clamp01($a);

            // 聚合策略：同一 hazard 多条预警取最大（最强那条主导）
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
 * 预警时间相位映射：
 * - past：已结束超过 1 小时，factor=0
 * - lead：未开始，按提前量衰减（越远越弱）
 * - active：生效中，factor=1
 * - unknown_time：缺少时间，给中等保守 factor
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
 * 严重度映射到 0-1：
 * - 若 severity_score ∈ [0,1]：更细粒度，线性映射到 [0.45, 1.0]
 * - 否则用离散 severity：minor/moderate/severe/extreme
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
 * 从 tags 中提取形如 "prefix:value" 的因子。
 * - 若命中映射表则返回对应值
 * - 若出现未知 value 则返回 default
 * - 若完全无此前缀 tag 则返回 default
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
 * 预警 hazard 基准强度（0-1）：
 * - 代表“中等严重度 + 生效中”时，该 hazard 预警一般有多强
 * - 这里的值偏“保守”，避免把 alerts 当作硬扣分器
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
 * 温度极端风险（0-100）：
 * - cold_score：基于风寒 windChill
 * - heat_score：基于热指数 heatIndex（露点高时放大）
 * - score：两者取 max
 *
 * 注意：这是“安全/健康层面的风险”，不是“舒不舒服”。
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
 * 天气现象风险（0-100 + hazards 0-1）：
 * - 从 weatherId 识别雪、冻雨、雷暴、强降雨、雾、沙尘、龙卷等
 * - 同时引入风阵/风速映射到 wind hazard
 * - 对“0°C 附近雪/冻雨”做临界增强（道路结冰风险更符合直觉）
 *
 * 返回 hazards：hazard => risk01（用于融合的 P）
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

    // 风：优先使用 wind_gust，其次 wind_speed（如果上游不提供 gust）
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

    // 雪/冻雨在接近 0°C 时，道路结冰更典型（临界增强）
    if ($T !== null && is_numeric($T) && isset($haz['snow_ice'])) {
        $t = (float)$T;
        if ($t >= -3.0 && $t <= 1.0) {
            $haz['snow_ice'] = clamp01($haz['snow_ice'] + 0.08);
            $debug[] = 'snow_ice_temp_band';
        }
    }

    // score：把 hazards 的 risk01 也映射到 0-100，用于“兼容字段/粗略概览”
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
 * 风速/阵风映射到风险（0-1）：
 * - 分段线性：从 12 m/s 开始认为有明显风险
 * - 这里更偏“行走/驾车/高空物体风险”的直觉尺度
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
 * 空气健康风险（0-1）：
 * - 这是“健康层面的风险”，不同于空气舒适（comfort）得分
 * - 当前实现以 PM2.5 与 O3 为主导（其余污染物可后续扩展）
 *
 * 输出 risk_01 会被并入 P['air_quality']，参与风险融合与压帽。
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
 * hazard 影响权重：
 * - 影响 Noisy-OR 聚合：权重越高，该 hazard 对总体风险更“敏感”
 * - 极端温度/台风/龙卷等偏高；雾等偏低
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
 * Noisy-OR 聚合：
 * - 每个 hazard 贡献 x（0-1），乘以权重 w
 * - 总体风险 = 1 - Π(1 - x*w)
 * 特点：多个中等风险会叠加变大，但不会简单线性相加“爆表”。
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
 * 将总体风险 r01（0-1）映射到风险压帽 cap（0-100）：
 * - cap 越低表示越危险（压帽越强）
 * - 使用幂函数让“低风险区更宽松，高风险区更敏感”
 */
function mapOverallRiskToCap(float $r01): float
{
    $r01 = clamp01($r01);
    $gamma = 1.35;
    $cap = 100.0 * (1.0 - pow($r01, $gamma));
    return max(0.0, min(100.0, $cap));
}

/**
 * 风险提示分聚合（0-1）：
 * - 对每个 hazard 使用 h = max(P, A)
 * - 用 Noisy-OR 聚合得到 H_overall
 * 解释：提示分更像“提醒系统”，不一定触发压帽，但会提醒用户注意。
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
 * 选择“用户可读风险因素”（hazard 列表）：
 * - 条件：R 足够高 或 Focus 足够高
 * - 排序：优先 Focus，其次 R
 * - 最多取 8 个，避免 UI 过载
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
 * 最大预警贡献（用于兼容字段 from_alerts）：
 * - 估计“预警侧”对总体的最大可能影响，使用 a*q（强度×可信度）
 * - 注意：这不是压帽的直接来源，只是用于解释/兼容统计
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
 * 从 tags 中提取 hazard 列表：
 * - 识别 "hazard:xxx"
 * - 返回去重后的 hazard 数组
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
 * 安全的小写转换（UTF-8 友好）。
 */
function cei_strlower_safe(string $str): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($str, 'UTF-8');
    }
    return strtolower($str);
}

/**
 * 将浮点数截断到 [0,1]。
 */
function clamp01(float $x): float
{
    if ($x < 0.0) return 0.0;
    if ($x > 1.0) return 1.0;
    return $x;
}

/**
 * 线性插值（t∈[0,1]，返回 [a,b]）。
 */
function lerp01(float $t, float $a, float $b): float
{
    $t = clamp01($t);
    return $a + ($b - $a) * $t;
}