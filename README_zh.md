<p align="center">
  <img src="./access/CWC-CEI-Logo.png" 
       alt="CEI Logo" 
       width="200">
</p>

<h1 align="center">CWC CEI – 环境舒适度指数 <br><span>（Comfort Environment Index）</span></h1>

<p align="center">
  <strong>一套将天气、空气质量与环境风险整合为 0–100 环境评分的智能算法</strong><br>
  由 <strong>CWC Platform / Caner HK</strong> 提供支持
</p>

<p align="center">
  🌐 <a href="README.md"><strong>English Documentation（英文文档）</strong></a>
</p>

---

CWC CEI（Comfort Environment Index，环境舒适度指数）是一套将 **天气、空气质量与风险信息**  
统一转换为 **0–100 环境评分** 的智能算法。

它的目标是让天气应用从“只展示数字”升级为 **理解人体体感和安全风险的环境感知层**：  
在统一框架中综合热舒适、空气质量、紫外线、气压以及极端风险，为用户提供可解释、可计算的环境状态描述。

---

## 🚀 核心设计概览

### ✔ 双层结构：舒适度层 + 风险上限层

自 **v3.0.0** 起，CEI 被明确拆分为两个互相独立又可组合的层级：

1. **舒适度层（Comfort Layer）**  
   关注“在当前条件下，这个环境有多舒服”，综合：

   - 热舒适（温度、湿度、风速、体感温度）
   - 空气舒适（多污染物）
   - 紫外线舒适（UV）
   - 气压舒适（Pressure）

   输出一个 **0–100 的舒适度 CEI（`comfort_cei`）**。

2. **风险层（Risk Layer）**  
   关注“这个环境有多危险”，单独评估：

   - 极端低温 / 高温及相关健康风险
   - 危险天气（强雷暴、暴雪、沙尘暴、龙卷风、强阵风、冻雨等）
   - 来自官方机构的气象 / 灾害预警（如台风、暴雨、雪崩、洪水、严重污染等）

   风险层输出 **0–100 的风险分 `riskScore`**，并换算为：

   > **RiskCap = 100 − riskScore**

最终 CEI 定义为：

> **CEI = min(Comfort CEI, RiskCap)**

含义：

- 若体感“很舒服”但客观风险较高（如：晴朗天气下存在雪崩红色预警），  
  **RiskCap 会限制最终 CEI 的上限**，防止指数给出“虚高的安全感”。
- 若风险较低，则 CEI 主要由舒适度层驱动，体现真实体感。

在输出结构中，两层数据都会被显式暴露，便于前端与 AI 使用：

- `cei` – 最终 0–100 指数  
- `components.risk` – 风险组件分数  
- `detail.comfort_cei` – 仅舒适度层的 CEI  
- `detail.risk_cap` – 风险层施加的上限  
- `detail.main_effect` – 当前环境的主导因素（heat / air / uv / pressure / risk）

---

## 🌍 气候带与季节模型（Climate Context）

CEI 认为：**不同纬度、不同季节下，人们对温度的“舒适区间”并不相同**。

### ✔ 气候带划分与舒适温度

算法基于 **纬度绝对值（`abs(latitude)`）** 将全球划分为若干气候带，例如：

- equatorial（赤道）
- tropical（热带）
- subtropical（副热带）
- temperate（温带）
- cold_temperate（寒温带）
- polar（极地）

每个气候带都会被分配一个基础 **舒适温度 `comfortTemp`（℃）**，  
例如赤道区略高、极地略低，从而使：

> 同样是 15℃，在东京和在北欧的体感评价不会完全相同，CEI 会在分数层面反映这种差异。

### ✔ 南北半球季节映射

自 **v3.1.1** 起，CEI 在季节维度引入：

- 使用自然月 `month`（1–12）作为输入  
- 对南半球进行 **6 个月平移** 得到“本地月份 `monthNorm`”：

  - 北半球：`monthNorm = month`
  - 南半球：`monthNorm = ((month + 5) % 12) + 1`

这样：

- 北半球 6–8 月是夏季，南半球则以 12–2 月为夏季镜像；
- 季节判断和舒适温度修正都基于本地季节，避免布里斯班等城市被误判为“冬天但采用夏季逻辑”。

在此基础上，CEI 对舒适温度做轻度修正：

- 本地夏季（6–8 月） → 适当提高 `comfortTemp`
- 本地冬季（12–2 月） → 适当降低 `comfortTemp`

气候上下文以结构化形式返回：

```php
'detail' => [
  'climate' => [
    'zone'        => string, // 气候带，例如 'temperate'
    'factor'      => float,  // 季节缩放系数
    'comfortTemp' => float   // 该地当前季节下的舒适温度基准
  ],
  // ...
]
```

---

## 🌡 体感模型（Thermal Perception）

空气温度只是体感的一部分。CEI 使用 **Heat Index + Wind Chill + 露点 + 风场** 对体感进行综合建模。

### ✔ Heat Index（高温体感）

当环境偏暖或炎热时，CEI 使用 **热指数（Heat Index）**：

- 由温度与湿度共同决定  
- 捕捉“闷热感”及中暑风险  
- 高温 + 高湿 → 体感远高于实际温度

### ✔ Wind Chill（风寒）

在 **低温 + 有风** 条件下，风寒效应使体感温度明显低于气温。  
CEI 使用北美/加拿大标准风寒公式计算体感冷度。

### ✔ 模型自动切换与扩展因子

- **T ≥ 20℃** → 以 Heat Index 作为主要体感温度
- **T < 20℃** → 以 Wind Chill 作为主要体感温度

可选扩展字段：

- `dew_point` 露点：当露点 ≥ 24–26℃ 且温度较高时，增加湿热惩罚
- `wind_gust` 阵风：用于修正风寒体感并参与风险层的强风判定
- `feels_like`：可作为辅助展示字段，不直接驱动核心曲线

输出中会包含：

```php
'detail' => [
  'thermal' => [
    'effective_temp' => float, // 综合体感温度（已根据情况选择 Heat Index / Wind Chill）
    'heat_index'     => float,
    'wind_chill'     => float
  ],
  // ...
]
```

---

## ⚖️ 动态权重（Dynamic Weight Adjustment）

在不同情境下，哪些因素“更重要”是不一样的。  
CEI 使用动态权重系统，为四个舒适组件分配权重：

- 热舒适：`heat`
- 空气舒适：`air`
- 紫外线：`uv`
- 气压：`pressure`

权重会根据实时环境进行调整，例如：

- 极冷 / 极热 → 提升 `heat` 权重  
- PM2.5 升高 → 提升 `air` 权重  
- UVI > 8 → 提升 `uv` 权重  
- 风速较大 → 提升 `heat` 权重（风寒影响体感）

最终权重归一化为 1，并返回：

```php
'weights' => [
  'heat'     => float,
  'air'      => float,
  'uv'       => float,
  'pressure' => float
]
```

---

## 🌦 天气现象不适惩罚（Weather Condition Penalty）

在同样温度下，“雷暴 + 大风 + 雨夹雪”带来的体感显然比晴天差。  
CEI 使用 **OpenWeather `weather.id`** 对天气现象进行分级惩罚，作用于热舒适组件：

* **雷暴（2xx）**：
  强烈不适且存在潜在危险 → 中到高等级体感扣分
* **毛毛雨（3xx）**：
  轻度湿冷 → 低幅度扣分
* **雨（5xx）**：
  按强度细分小雨 / 中雨 / 大雨 / 冻雨 → 不同级别扣分
* **雪（6xx）**：
  按雪量与类型（雨夹雪等）分级 → 中到高等级不适
* **大气类（7xx：雾、烟、霾、扬沙、火山灰、飑线等）**
* **晴（800）**：
  不惩罚
* **多云（801–804）**：
  少云几乎不惩罚，阴天有轻微扣分

这些惩罚 **只影响体感舒适度**，不会直接改变空气质量得分。  
对于较为危险的现象（如强雷暴、龙卷风、沙尘暴等），风险层还会进一步计算 **安全风险分**。

---

## 🫧 国际空气质量组件（Air Quality Component）

CEI 对六类主要污染物分别进行评分，然后取最差者作为空气舒适度分：

- PM2.5  
- PM10  
- O₃  
- CO（内部自动从 μg/m³ 转换为 mg/m³）  
- NO₂  
- SO₂  

每个污染物采用阈值分段映射到 0–100，最终：

> **AirScore = min(score_PM2.5, score_PM10, score_O3, score_CO, score_NO2, score_SO2)**

这种“短板效应”设计反映真实情况：  
**只要某一种污染物严重超标，整体空气舒适度就应该下降。**

---

## ⚠️ 风险识别与预警时效（Risk & Alerts）

### ✔ 三类风险来源

风险层将风险拆为三部分：

1. **温度风险（Temperature Risk）**  
   基于热指数、风寒、露点等指标，评估中暑、冻伤、低体温等风险，并输出如：

   - `temp_extreme_cold_35`
   - `temp_heat_35`
   - `temp_windchill_-20` 等标记。

2. **天气风险（Weather Risk）**  
   使用 `weather_id` 与 `wind_gust` 识别：

   - 强雷暴、强降雨、暴雪、冻雨  
   - 浓雾、沙尘暴、龙卷风等现象  
   对应输出 `wx_thunderstorm_heavy`, `wx_freezing_rain`, `wind_gust_25` 等标记。

3. **预警风险（Alerts Risk）**  
   通过适配层对 OpenWeather、QWeather（和风天气）等平台的预警进行结构化统一，  
   转换为类似下列结构：

   ```php
   [
     'event'          => '大风蓝色预警',
     'description'    => '未来 24 小时内将出现 6～7 级大风……',
     'tags'           => [
       'hazard:wind',
       'severity:minor',
       'color:blue',
       'provider:qweather'
     ],
     'severity'       => 'minor',
     'severity_score' => null, // 预留给未来模型输出 0–1 连续严重度
     'code'           => 1006, // 数据源内部预警代码

     'start_ts'       => 1732854000, // 预警开始时间（Unix 时间戳，UTC 秒）
     'end_ts'         => 1732940400  // 预警结束时间
   ]
   ```

### ✔ 预警时效处理

自 **v3.1.0** 起，CEI 在计算 `alerts` 风险时考虑时间维度：

- 当前时间 < `start_ts` → 预警 **尚未开始**：  
  风险影响较弱（可视为“提前提醒”），避免过早拉低 CEI。
- `start_ts` ≤ 当前时间 ≤ `end_ts` → 预警 **生效中**：  
  按完整权重计入风险。
- 当前时间 > `end_ts` → 预警 **已过期**：  
  仅作为背景信息，风险影响可大幅减弱或忽略。

时间统一使用 **Unix 时间戳（UTC 秒）**，避免时区偏差。

### ✔ 风险输出结构

在最终结果中，风险相关字段如下：

```php
'components' => [
  'heat'     => int, // 0–100
  'air'      => int,
  'uv'       => int,
  'pressure' => int,
  'risk'     => int  // 0–100
],

'detail' => [
  'risk' => [
    'overall'      => int,      // 风险总分 0–100
    'from_temp'    => int,      // 来自温度的风险
    'from_weather' => int,      // 来自天气现象的风险
    'from_alerts'  => int,      // 来自官方预警的风险
    'flags'        => string[]  // 风险标记，用于机器与 AI 解释
  ],
  'risk_cap' => float           // 100 - riskScore
]
```

---

## 📤 统一、结构化的输出

一次典型的 `computeCEI()` 调用将返回类似结构：

```php
[
  'cei'   => int,    // 0–100 最终指数
  'level' => string, // 例如 "CEI Level 2 – Comfortable"

  'components' => [
    'heat'     => int,
    'air'      => int,
    'uv'       => int,
    'pressure' => int,
    'risk'     => int
  ],

  'weights' => [
    'heat'     => float,
    'air'      => float,
    'uv'       => float,
    'pressure' => float
  ],

  'detail' => [
    'comfort_cei' => float,              // 仅舒适层 CEI
    'risk_cap'    => float,              // 风险施加的上限（100 - riskScore）
    'main_effect' => 'heat'|'air'|'uv'|'pressure'|'risk',

    'climate' => [
      'zone'        => string,           // 气候带
      'factor'      => float,            // 季节缩放因子
      'comfortTemp' => float             // 舒适温度基准
    ],

    'thermal' => [
      'effective_temp' => float,
      'heat_index'     => float,
      'wind_chill'     => float
    ],

    'risk' => [
      'overall'      => int,
      'from_temp'    => int,
      'from_weather' => int,
      'from_alerts'  => int,
      'flags'        => string[]
    ]
  ]
]
```

特点：

- **UI 友好**：适合用于色带、仪表、卡片、时间序列图等组件  
- **AI 友好**：所有关键语义都结构化呈现，模型不需要重新做物理推理  
- **机器友好**：易于日志记录、统计分析和策略引擎使用

---

## 👨‍💻 多语言 / 多平台无损重写能力

CWC CEI 算法在设计上保持 **纯计算核心**，不依赖特定框架或 I/O，
因此可以在不同语言中 **无损重写（逻辑与精度保持一致）**，适配多种平台。

* 当前参考实现为 **PHP 版本**
* 可平滑迁移到：

  * **JavaScript / TypeScript**（Web 前端、Node.js 后端）
  * **Python**（数据分析、AI 模型、后端服务）
  * **Java / Kotlin**（Android / 服务器应用）
  * **C / C++ / Rust / Go**（嵌入式设备、高性能服务）
  * **Swift**（iOS / macOS 客户端）

核心设计：

* CEI 核心函数为 **纯函数**：

  * 不在内部做任何网络请求或文件读写
  * 所有输入均为显式传入（天气 + 空气质量 + 环境上下文 + 预警）
  * 相同输入一定得到相同输出（方便测试与回放验证）

因此，CEI 非常适合：

* 集成到 **手机 App**（天气应用、健康应用）
* 用于 **Web 前端 / 后端 API**（SaaS、开放平台）
* 部署在 **物联网 / 边缘设备**（天气站、电子墨水屏终端）
* 嵌入 **数据分析 / AI 决策系统**（出行建议、健康风险评估）

---

## 🧩 集成与示例

### ✔ 安装

```bash
git clone https://github.com/Caner-HK/CEI-Comfort-Environment-Index
````

在 PHP 项目中引入：

```php
require 'cei.php';
```

### 🌤 通过 OpenWeather 获取天气数据

CEI 算法默认面向 **OpenWeather One Call 3.0 API** 与 **Air Pollution API** 的数据结构，
并通过适配器支持其他数据源（如 QWeather 和风天气）转换为统一输入格式。

#### 1️⃣ 准备 OpenWeather API Key

1. 注册 OpenWeather 账号：
   [https://home.openweathermap.org/users/sign_up](https://home.openweathermap.org/users/sign_up)
2. 在控制台创建并查看 API Key：
   [https://home.openweathermap.org/api_keys](https://home.openweathermap.org/api_keys)

#### 2️⃣ 使用的 OpenWeather 接口

| API                   | 用途                                     | 文档地址                                                                                         |
| --------------------- | -------------------------------------- | -------------------------------------------------------------------------------------------- |
| **One Call API 3.0**  | 实时天气：气温、湿度、风速、阵风、露点、紫外线指数、气压、天气现象等     | [https://openweathermap.org/api/one-call-3](https://openweathermap.org/api/one-call-3)       |
| **Air Pollution API** | 空气质量：PM2.5、PM10、O₃、CO、NO₂、SO₂ 等主要污染物浓度 | [https://openweathermap.org/api/air-pollution](https://openweathermap.org/api/air-pollution) |

#### 3️⃣ CEI 所需核心字段映射

**从 One Call API 3.0 的 `current` 字段获取：**

* `temp`（气温）
* `humidity`（相对湿度）
* `wind_speed`（风速）
* `wind_gust`（阵风，可选）
* `pressure`（气压）
* `uvi`（紫外线指数）
* `dew_point`（露点温度，可选）
* `feels_like`（体感温度，可选，用于调试或扩展）
* `weather[0].id` → 映射为 `weather_id`（天气现象编号）

**从 Air Pollution API 的 `list[0].components` 获取：**

* `pm2_5`
* `pm10`
* `o3`
* `co`
* `no2`
* `so2`

**CEI 接受天气警报输入，只需要将天气警报数据格式化为上述预警风险部分的结构。One Call API 3.0 中通过 `alerts` 提供政府发布的警报信息**

* `event`（预警事件）
* `description`（预警事件描述）
* `tags`（列表，预警事件代表标签）
* `severity`（预警事件的严重等级）
* `severity_score`（由模型生成出的风险数值）
* `code`（数据源内部的预警代码）
* `start_ts`（预警开始时间）
* `end_ts`（预警结束时间）

调用 CEI 时，只需要将上述字段整理为一个 `$data` 数组传入。

### 💻 调用示例

```php
require 'cei.php';

// 1. 将 OpenWeather 的 One Call + Air Pollution 数据提取并整理为数组
$data = [
    // ——— 实时天气（来自 One Call API 3.0 的 current）———
    'temp'       => -1.0,
    'humidity'   => 26,
    'wind_speed' => 1.9,
    'wind_gust'  => 2.8,   // 可选
    'dew_point'  => -16.0, // 可选
    'feels_like' => -3.5,  // 可选
    'uvi'        => 0.0,
    'pressure'   => 1023,
    'weather_id' => 800,   // 对应 current.weather[0].id

    // ——— 空气质量（来自 Air Pollution API 的 list[0].components）———
    'pm2_5'      => 8,
    'pm10'       => 16,
    'o3'         => 40,
    'co'         => 180,
    'no2'        => 12,
    'so2'        => 5,

    // ——— 预警（可选，由适配层统一后的数组）———
    'alerts'     => $normalizedAlerts ?? [],
];

// 2. 单位与时空信息
$unit     = 'metric'; // 'metric' / 'imperial' / 'standard'
$latitude = 36.1;     // 用于气候带和季节修正
$month    = 11;       // 当前月份（1–12）

// 3. 调用 CEI 主函数
$cei = computeCEI($unit, $data, $latitude, $month);

print_r($cei);
```
---

## 🧠 CEI 作为低成本 AI 的“环境感知层”

大模型可以直接阅读“温度 -3℃、风速 6 m/s、PM2.5 60”这种数字，  
但小模型往往很难稳定可靠地推理出：

- 这对人意味着怎样的体感？  
- 是否存在健康或安全风险？  
- 在不同气候带，这样的天气应如何解释？

CEI 通过提供结构化的 **环境舒适与风险语义层**，让小模型也能给出高质量的天气解释和建议。

### ✔ 典型训练模式示意

```text
输入：原始天气数据 + 计算后的 CEI 结构
输出：给用户的自然语言解释/建议
```

模型只需学习：

- CEI 越低 → 越不适合户外活动  
- 风寒/高温/高露点 → 需保暖、防暑、补水  
- 空气组件较低 → 建议佩戴口罩、减少剧烈运动  
- `risk.flags` 中出现雪崩/山洪/台风 → 应提示严重安全风险

而无需“自己再实现一遍气象与医学模型”。

优势：

- 显著降低训练与推理成本  
- 输出稳定、一致，不易“胡编风险”  
- 可在手机、边缘设备和低配服务器上部署  
- 在很多业务场景下，小模型 + CEI 的组合优于“裸用大模型 + 原始天气数据”

---

## 📊 版本历史

| 版本号  | 日期       | 更新内容                                                                                                                                                                                                                                               |
|--------|------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| v1.0.0 | 2025-11-14 | 初始版本：包含基础 CEI 计算（温度、湿度、风速）与简化权重模型。                                                                                                                                                                                         |
| v2.0.0 | 2025-11-17 | 气候带 / 天气现象更新：加入气候带模型、天气不适惩罚、风寒指数、热指数、动态权重以及更完善的空气质量评分，统一 0–100 输出。                                                                                                                            |
| v3.0.0 | 2025-11-28 | 风险感知 / 极端条件更新：引入独立风险层（极端温度、危险天气、官方预警），增加 RiskCap 上限机制，提供结构化风险拆分输出，并支持 `dew_point` / `wind_gust` / `alerts` 等字段。                                                                     |
| v3.1.0 | 2025-11-29 | 预警时效更新：为统一 alerts 结构新增 `start_ts` / `end_ts`（Unix 时间戳，UTC 秒），在风险评分中区分“未开始 / 生效中 / 已过期”预警，优化提前预警场景下的风险约束，避免尚未发生的事件过早拉低 CEI。                                                     |
| v3.1.1 | 2025-12-02 | 南半球气候区修正：基于绝对纬度重新划分气候带，引入南半球季节映射（`monthNorm`），修复南半球城市在夏冬季判断与舒适温度上的偏差，使 CEI 在全球范围内对冷暖区域的表现更加合理。                                                                     |

> **当前版本：v3.1.1 – 南半球气候区修正**

---

## 🤝 参与贡献

欢迎开发者、研究者、气象从业者与 AI 实践者参与 CEI 的共建。

### ✔ 可以如何参与？

- **提交 Issue**
  - 反馈 Bug  
  - 提出模型优化建议（体感曲线、AQ 阈值、风险逻辑、权重策略等）  
  - 建议新增气候带、城市本地化配置或更多预警数据源

- **提交 Pull Request（PR）**
  - Bug 修复  
  - 模型改进（热舒适、空气质量、风险评估等）  
  - 新的数据源适配器（更多国家/地区的天气与预警平台）  
  - 新的语言实现（JS / Python / Go / Rust / …）  
  - 文档与示例（集成指南、使用案例、图示）

- **分享数据与反馈**
  - 提供不同气候区、不同用户群体、不同使用场景下的体感与反馈  
  - 这些数据将用于未来对 CEI 的标定与数据驱动升级

### ✔ 项目愿景

将 CEI 打造成 **开放、透明、可验证、可扩展的环境舒适与风险指数标准**：  
在科学模型与人类感受之间搭建可靠桥梁，为下一代智能天气助手和环境感知型 AI 系统提供基础设施。

---

如果你觉得这个项目有价值，欢迎在仓库中点亮一颗 ⭐ Star。
