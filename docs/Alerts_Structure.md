## 🔔 Alerts 输入要求（Normalized Alerts Spec）

CEI v3.2.0 的风险层支持 `data['alerts']`（可选）。  
**本仓库不绑定任何天气数据源**（OpenWeather / QWeather 等），但要求调用方在进入 CEI 前先通过“适配层（Adapter）”把原始预警统一成下述结构。

> ✅ 推荐：对 OpenWeather / QWeather / NWS / MeteoAlarm 等来源都先做同一套 normalize，CEI 只吃“统一格式”。

---

### 1) 字段结构（每条 alert）

`data['alerts']` 必须是一个数组，每个元素是一条预警（关联数组）：

```php
$data['alerts'] = [
  [
    'event'          => '大风蓝色预警',
    'description'    => '未来 24 小时内将出现 6～7 级大风……',

    // ✅ 核心：用于 hazard 分桶识别 + 可信度（Q）推导
    'tags' => [
      'hazard:wind',
      'severity:minor',
      'certainty:likely',
      'urgency:expected',
      'area:city',
      'provider:qweather',
      // 你也可以附加其他 tag，但建议遵循 "key:value"
    ],

    // 可选：严重等级（字符串）或连续严重度（0–1）
    'severity'       => 'minor',
    'severity_score' => null,

    // 可选：数据源内部 code（用于追踪/调试/去重）
    'code'           => 1006,

    // ✅ 核心：UTC 秒，用于时间相位 lead/active/past
    'start_ts'       => 1732854000,
    'end_ts'         => 1732940400,
  ],
  // ...
];
````

---

### 2) 必需字段 vs 可选字段

#### ✅ 必需字段（强烈建议保证存在）

* `tags`：`string[]`

  * 至少包含一个 `hazard:xxx`，否则会落入 `other` 分桶
* `start_ts` / `end_ts`：`int|null`（UTC 秒）

  * 用于判断预警相位：lead / active / past
  * 允许缺失（但会降低时间判定准确性）

#### 可选字段（缺了也能跑，但建议提供）

* `event`：`string|null`（预警名称，用于日志/UI）
* `description`：`string|null`（预警描述，可用于展示）
* `severity`：`string|null`（`minor|moderate|severe|extreme` 或来源自带值）
* `severity_score`：`float|null`（**0–1** 连续强度；如果有模型输出强度建议用它）
* `code`：`int|string|null`（来源内部 code 或 id）

> 如果 `severity_score` 存在，则优先用 `severity_score` 映射强度；否则用 `severity` 字符串映射。

---

### 3) Tags 规范（重点）

`tags` 是 CEI 风险层理解预警的关键输入，推荐使用 `key:value` 形式的小写标签（value 可带下划线）。

#### 3.1 hazard（必需，至少一个）

```text
hazard:wind
hazard:heavy_rain
hazard:thunderstorm
hazard:snow_ice
hazard:flood
hazard:tropical_cyclone
hazard:tornado
hazard:fog
hazard:dust_sand
hazard:fire
hazard:air_quality
hazard:geohazard
hazard:avalanche
hazard:coastal
hazard:other
```

* 允许一条预警带多个 hazard（例如台风同时带 `wind` + `heavy_rain`）
* 如果没有任何 `hazard:*`，CEI 会自动归类为 `other`

#### 3.2 severity（可选，但推荐）

```text
severity:minor
severity:moderate
severity:severe
severity:extreme
```

* 如果 `severity_score` 有值，可以不写 severity tag（但写了也不影响）

#### 3.3 certainty（可选，用于 Q：可信度）

```text
certainty:observed
certainty:likely
certainty:possible
certainty:unknown
```

#### 3.4 urgency（可选，用于 Q：紧迫性）

```text
urgency:immediate
urgency:expected
urgency:future
urgency:past
urgency:unknown
```

#### 3.5 area（可选，用于 Q：覆盖范围/命中度）

```text
area:point
area:local
area:city
area:regional
area:province
area:national
area:marine
area:unknown
```

#### 3.6 provider（可选）

```text
provider:openweather
provider:qweather
provider:nws
provider:meteoalarm
```

> 你可以扩展更多 tags，例如 `color:blue`、`type:meteorological`、`region:hk`，CEI 会忽略不认识的 tag，但会保留对核心 tag 的解析。

---

### 4) 时间字段要求（UTC 秒）

风险层使用 `data['ts']` 作为评估时刻（UTC 秒）来判断预警相位：

* `evalTs < start_ts` → **lead（未开始）**：按提前提醒衰减
* `start_ts <= evalTs <= end_ts` → **active（生效中）**：完整计入
* `evalTs > end_ts + 3600` → **past（已过期）**：归零或忽略

因此：

* ✅ forecast 场景建议传入 `data['ts']`
* ✅ 实况场景可以不传 `ts`（默认 `time()`）

---

### 5) 最佳实践（强烈建议）

#### 5.1 去重建议（适配层做）

同一事件可能来自多个来源或多次刷新，建议在适配层去重（比如按 `provider + code + start_ts + hazard`）。

#### 5.2 统一 hazard 命名

不同来源的预警类型粒度不同，建议适配层建立映射表，将其统一到 CEI hazard 集合（上文 hazard 列表）。

#### 5.3 severity_score 的策略

如果你未来用模型做严重度回归输出（0–1），直接填 `severity_score`，效果通常比离散 `minor/moderate/...` 更平滑。

---

### 6) 示例：从 OpenWeather / QWeather 适配成 CEI alerts

```php
$normalizedAlerts = [
  [
    'event'       => 'Flood Watch',
    'description' => 'River flooding possible...',
    'tags'        => [
      'hazard:flood',
      'severity:moderate',
      'certainty:likely',
      'urgency:expected',
      'area:regional',
      'provider:openweather',
    ],
    'severity'  => 'moderate',
    'start_ts'  => 1733757600,
    'end_ts'    => 1733898000,
    'code'      => 'ow_alert_12345',
  ],
];

$data['alerts'] = $normalizedAlerts;
$data['ts'] = 1733800000; // forecast/评估时刻（UTC 秒，可选）
```

---

### 7) 常见错误排查

* **风险层没有识别到预警**：检查 `alerts` 是否为数组；每条 alert 是否包含 `tags` 且 `tags` 为数组
* **hazards 全是 other**：检查是否有 `hazard:xxx` tag
* **预警不生效**：检查 `start_ts/end_ts` 是否是 UTC 秒、是否与 `data['ts']` 同一时间基准
* **提示太强/太弱**：检查 `certainty/urgency/area` 的 tag 值是否合理；或是否提供了更精确的 `severity_score`

