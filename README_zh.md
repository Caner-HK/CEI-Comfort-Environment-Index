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

CEI（环境舒适度指数）是一套将天气数据、空气质量数据以及风险信息（极端温度、危险天气、气象预警等）
统一转化为 **0–100 环境评分** 的智能算法。

它旨在让天气应用从单纯的“数据展示”迈向真正的 **智能环境感知与风险识别**，
提供既贴近人体体验、又兼顾安全边界的环境分析能力。

---

## 🚀 特性介绍

### ✔ 两层结构：舒适度层 + 风险上限层

自 v3.0.0 起，CEI 采用清晰的两层结构：

1. **舒适度层（Comfort Layer）**
   综合温度、湿度、风速、体感温度、空气质量、紫外线、气压等，计算 “当前环境有多舒服”。
   输出一个 0–100 的 **comfort CEI**，并包含：

   * 热舒适（Heat）
   * 空气舒适（Air）
   * 紫外线舒适（UV）
   * 气压舒适（Pressure）

2. **风险层（Risk Layer）**
   单独评估“当前环境是否存在健康或安全风险”，包括：

   * 极端低温 / 高温带来的体感与健康风险
   * 危险天气现象（强雷暴、暴雪、沙尘暴、龙卷风、强风等）
   * 来自官方预警系统的天气 / 灾害警报（如暴雨、台风、雪崩、重污染等）

算法计算得到一个 0–100 的 **风险分（riskScore）**，并转换成：

> **RiskCap = 100 - riskScore**

最终的 CEI 定义为：

> **CEI = min(Comfort CEI, RiskCap)**

* 当环境舒适但存在高风险（例如：天气宜人但发布雪崩预警）时，
  **风险层会给 CEI “戴一顶上限帽子”**，防止指数虚高。
* 当没有明显风险时，CEI 主要由舒适度层决定，体现真实体感。

在输出结构中，CEI 会同时给出：

* `cei`：最终 0–100 环境指数
* `components.risk`：风险分
* `detail.comfort_cei`：单纯舒适度的 CEI
* `detail.risk_cap`：由风险决定的上限
* `detail.main_effect`：当前环境主要由哪一项决定（heat / air / uv / pressure / risk）

---

### ✔ 气候带模型（Climate Zone Model）

CEI 的基础假设是：**不同地区对温度的耐受与舒适上限并不相同**。

算法基于 **纬度自动划分全球气候带**（示意）：

* **热带 / 赤道附近区域**：
  常年偏暖，对较高温度更宽容，舒适温度基准略高。
* **温带 / 副热带地区**：
  四季分明，舒适温度随季节波动。
* **高纬度 / 极地地区**：
  背景温度偏低，当地居民对低温耐受性更高。

同时结合月份自动应用 **季节修正（Seasonal Adjustment）**：

* 夏季（6–8 月）：适度提升舒适温度上限
* 冬季（12–2 月）：适度降低舒适温度基准（默认考虑衣物因素）

CEI 为不同气候带生成独立的 **comfortTemp（舒适温度基准）**，
保证同样的气象条件在不同地区得到的评分更符合当地体感认知。

---

### ✔ 真实体感模型（Physical Thermal Perception）

人体对冷热的感受，并不是由“气温”单一决定。
CEI 使用国际通行的组合模型来描述真实体感。

#### Heat Index（高温体感模型）

当温度较高时，通过 **温度 + 湿度** 共同计算热指数：
将“闷热感”“中暑风险”纳入整体评分。

#### Wind Chill（低温体感模型）

在低温 + 强风条件下，风寒效应会让体感温度远低于实际温度。
CEI 使用北美/加拿大标准公式计算风寒温度。

#### 体感模型自动切换

* **温度 ≥ 20℃**：使用 Heat Index 作为主要体感温度
* **温度 < 20℃**：使用 Wind Chill 作为主要体感温度

并引入可选字段：

* **Dew Point（露点温度）**：露点较高（例如 ≥ 24–26℃）时，在高温环境中额外惩罚湿热不适。
* **Wind Gust（阵风）**：用于更准确评估风寒体感与风险层中的强风风险。

CEI 会在输出中提供：

* `detail.thermal.effective_temp`：综合体感温度
* `detail.thermal.heat_index`：热指数
* `detail.thermal.wind_chill`：风寒温度

方便前端和 AI 模型进一步解释“冷/热是怎么来的”。

---

### ✔ 动态权重调整（Dynamic Weight Adjustment）

环境因素在不同情境下的重要性不同。
CEI 内置权重动态调整系统，根据实时天气对评分结构进行再平衡：

* **温度**：出现明显偏冷 / 偏热时，提高热舒适权重
* **PM2.5**：空气污染加重时，提高空气质量权重
* **UVI**：紫外线指数 > 8 时，额外提高 UV 权重
* **风速**：强风条件下，提高热舒适权重（因为风寒导致体感下降明显）

所有权重最终会归一化为 1：

* `weights.heat`
* `weights.air`
* `weights.uv`
* `weights.pressure`

动态权重的核心目标：

> 在极端天气中，把最关键的感受因素放在最前面：
> “此刻到底是冷热更重要，还是污染更重要？”

---

### ✔ 天气现象不适惩罚（Weather Condition Penalty）

天气类型会带来直接体感影响。
CEI 基于 **OpenWeather weather id**，解析天气类型并根据等级施加不适惩罚。

覆盖的主要类别包括：

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

这些惩罚主要作用于 **热舒适组件**，将“同样温度下因为天气现象导致的主观不适”表达出来，
而不会直接改变空气质量评分。

同时，在风险层中还会对部分天气（如强雷暴、暴雪、沙尘暴、龙卷风）附加**安全级别风险分**。

---

### ✔ 国际空气质量评分（International AQ Component）

CEI 对六类主要污染物分别打分，并取“最差项”作为整体空气舒适度分（短板效应）：

* PM2.5 – 细颗粒物
* PM10 – 可吸入颗粒物
* O₃ – 臭氧
* CO – 一氧化碳（自动从 μg/m³ 转换为 mg/m³）
* NO₂ – 二氧化氮
* SO₂ – 二氧化硫

采用分级阈值将浓度映射为 0–100 评分，
并通过“取最差项”的方式，更贴近真实健康暴露风险。

---

### ✔ 风险识别与上限机制（RiskCap）

除了舒适度层外，CEI 在 v3.0.0 新增了完整的 **风险识别与上限机制**，统一处理：

* 极端低温 / 高温
* 强风与阵风
* 强雷暴、暴雨、暴雪、沙尘暴、龙卷风等危险天气
* 官方发布的气象预警与灾害警报（如暴雨、台风、雪崩、洪水、重污染等）

风险信息被拆分为三个来源：

1. **Temperature Risk（温度风险）**
   基于风寒温度、热指数、露点、湿热等指标，对冻伤/中暑等风险进行分级。

2. **Weather Risk（天气现象风险）**
   使用天气代码与阵风信息，对雷暴、大雪、冻雨、沙尘、龙卷风等情况生成风险分与标记。

3. **Alerts Risk（气象预警风险）**
   通过独立的适配层，将 OpenWeather / QWeather 等平台的预警统一成结构化字段：
   `hazard:xxx` + `severity:minor/moderate/severe/extreme`，并映射为 0–100 风险分。

风险层的输出统一为：

* `components.risk`：综合风险分（0 = 无明显风险，100 = 极高风险）
* `detail.risk.overall`：整体风险
* `detail.risk.from_temp` / `from_weather` / `from_alerts`：来源分析
* `detail.risk.flags[]`：机器友好、可本地化的人类可读标记（如 `alert_avalanche`, `temp_extreme_cold_35`）

最终通过 **RiskCap = 100 - riskScore** 约束整体 CEI，
在 UI 与 AI 文本输出中都能给出“既舒服又安全 / 舒适但不安全 / 不舒服也不安全”等不同场景的清晰解释。

---

### ✔ CEI 综合输出（0–100）

综合考虑上述各类因子后，CEI 输出完整的结构化结果，包括：

* 总体指数：`cei`（0–100）
* 等级描述：`level`（如 Level 1–5 / Severe 等）
* 组件得分：`components.heat / air / uv / pressure / risk`
* 动态权重：`weights.heat / air / uv / pressure`
* 细节信息：`detail.climate / detail.thermal / detail.risk / detail.main_effect`

可直接用于：

* 前端可视化（进度条、色带、气泡卡片、趋势图）
* AI 文本生成（穿衣建议、出行建议、健康提示等）
* 策略系统（如自动推送提醒、日程联动等）

---

### ✔ 多语言 / 多平台无损重写能力

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

## 📦 安装

```bash
git clone https://github.com/Caner-HK/CEI-Comfort-Environment-Index
```

在 PHP 项目中引用（文件名可根据你的项目结构调整）：

```php
require 'cei.php';
```

---

## 🧩 调用示例

CEI 算法默认面向 **OpenWeather One Call 3.0 API** 与 **Air Pollution API** 的数据结构，
并通过适配器支持其他数据源（如 QWeather 和风天气）转换为统一输入格式。

### 📡 数据来源说明

CEI 需要从以下两个 OpenWeather 接口获取实时天气与空气质量数据：

| API                   | 用途                                     | 文档地址                                                                                         |
| --------------------- | -------------------------------------- | -------------------------------------------------------------------------------------------- |
| **One Call API 3.0**  | 提供实时天气，如温度、湿度、风速、阵风、露点、气压、紫外线、天气状况等。   | [https://openweathermap.org/api/one-call-3](https://openweathermap.org/api/one-call-3)       |
| **Air Pollution API** | 提供空气污染物浓度，包括 PM2.5、PM10、O₃、CO、NO₂、SO₂。 | [https://openweathermap.org/api/air-pollution](https://openweathermap.org/api/air-pollution) |

### 🔑 如何获取 API Key

注册 OpenWeather 账号：
[https://home.openweathermap.org/users/sign_up](https://home.openweathermap.org/users/sign_up)

在此生成 API Key：
[https://home.openweathermap.org/api_keys](https://home.openweathermap.org/api_keys)

### 📥 CEI 所需字段（核心）

从 **One Call API 3.0** 的 `current` 字段获取：

* `temp`（气温）
* `humidity`（相对湿度）
* `wind_speed`（风速）
* `wind_gust`（阵风，可选）
* `pressure`（气压）
* `uvi`（紫外线指数）
* `dew_point`（露点温度，可选）
* `feels_like`（体感温度，可选，用于调试或扩展）
* `weather[0].id`（天气现象编号）

从 **Air Pollution API** 的 `list[0].components` 字段获取：

* `pm2_5`
* `pm10`
* `o3`
* `co`
* `no2`
* `so2`

如需使用气象预警，可通过独立的 **alerts 适配层** 将不同平台的预警统一为：

```php
[
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
    'severity_score' => null, // 可选，预留给未来的模型评分
    'code'           => 1006, // 数据源内部预警代码

    'start_ts'       => 1732854000, // 预警开始时间（Unix 时间戳）
    'end_ts'         => 1732940400, // 预警结束时间（Unix 时间戳）
  ],
  // ...
]
```

并通过 `alerts` 字段传入 CEI。

### 🧠 CEI 调用流程（示例）

```php
require 'cei.php';

$data = [
    'temp'       => -1.0,
    'humidity'   => 26,
    'wind_speed' => 1.9,
    'wind_gust'  => 2.8,   // 可选
    'dew_point'  => -16.0, // 可选
    'feels_like' => -3.5,  // 可选，不参与核心热模型，只作为扩展信息
    'pm2_5'      => 8,
    'pm10'       => 16,
    'o3'         => 40,
    'co'         => 180,
    'no2'        => 12,
    'so2'        => 5,
    'uvi'        => 0.0,
    'pressure'   => 1023,

    // 已通过适配层统一的气象预警（如有）
    'alerts'     => $normalizedAlerts ?? [],

    'weather_id' => 800,
];

$unit     = 'metric';  // 'metric' / 'imperial' / 'standard'
$latitude = 36.1;      // 纬度，用于气候带与季节修正
$month    = 11;        // 当前月份，用于季节修正

$cei = computeCEI($unit, $data, $latitude, $month);

print_r($cei);
```

---

## 🤖 低成本 AI 集成 —— 让小模型也能“懂天气 + 懂风险”

多数通用 AI 模型可以读懂“温度是多少、风速是多少”，
但很难直接推理“这样的天气对人到底意味着什么”。

CEI 提供的是一层 **人体感知 + 风险语义层（Human-Perception & Risk Layer）**，
让即便推理能力有限的模型也能输出接近专家水准的解释和建议。

### CEI 提供给模型的结构化特征包括：

* 0–100 的综合 CEI（已考虑风险）
* 纯舒适度 CEI：`detail.comfort_cei`
* 风险上限：`detail.risk_cap`
* 组件得分：heat / air / uv / pressure / risk
* 动态权重：heat / air / uv / pressure
* 风险拆分：from_temp / from_weather / from_alerts
* 机器友好标记：`risk.flags[]`（如 `alert_avalanche`, `temp_extreme_cold_35` 等）

模型不需要自己学习所有气象物理与医学风险，只需要“学会如何解释 CEI”。

### 为什么对低成本模型特别有价值？

* **推理链已被显式编码**：
  小模型不需要凭空推理，只需根据 CEI 的结构化输入生成自然语言。
* **训练样本可以非常简单**：
  只需构造 “天气 + CEI → 文本建议” 的监督样本，即可完成训练。
* **多地区、多气候带、多污染场景统一建模**：
  气候带、季节、污染、风险全部已经在 CEI 中统一编码。

示例训练思路（伪）：

```text
输入：原始天气数据 + 计算后的 CEI 结构
输出：自然语言建议
```

小模型可以学会：

* CEI 越低 → 越不适合户外活动
* 风寒 / 热指数 / 露点高 → 需要注意保暖/防暑/补水
* PM2.5 & AQI 不佳 → 建议佩戴口罩、减少剧烈运动
* 存在 alerts 风险 → 提示用户存在雪崩/山洪/暴雨/台风等安全问题

在许多应用场景中，使用 CEI 结构做支撑的小模型，
在稳定性与解释一致性方面甚至会优于“裸用”大模型。

### 商业落地优势

* 支持手机本地模型推理
* 支持 IoT / 边缘设备本地决策
* 服务端可以采用轻量模型，大幅降低推理成本
* 对高并发、低延迟有更好的适配性

---

## 📊 版本历史

| 版本号  | 日期       | 更新内容                                                                                                            |
|--------|------------|---------------------------------------------------------------------------------------------------------------------|
| v1.0.0 | 2025-11-14 | 初始版本：包含基础 CEI 计算（温度、湿度、风速）与简化权重模型。                                                     |
| v2.0.0 | 2025-11-17 | 气候带 / 天气现象更新：加入气候带模型、天气不适惩罚、风寒指数、热指数、动态权重、空气质量模块优化，统一 0–100 输出。 |
| v3.0.0 | 2025-11-28 | 风险感知 / 极端条件更新：引入独立风险层（极端温度、危险天气、官方预警），RiskCap 上限机制，支持 alerts 适配与风险拆分输出，新增 dew_point / wind_gust / alerts 等输入字段。 |
| v3.1.0 | 2025-11-29 | Alerts 时效更新：为统一 alerts 结构新增 `start_ts` / `end_ts`（Unix 时间戳，UTC 秒），支持在风险评分中区分未开始、进行中和已过期预警，优化提前预警场景下的风险约束。 |


> **当前版本：v3.1.0 – Alerts 时效更新**

---

## 🤝 参与贡献（欢迎加入共建）

我们欢迎开发者、研究者、气象从业者与 AI 从业者参与 CEI 的建设与完善。

### 如何参与？

* **提交 Issue**
  反馈 Bug、提出新功能建议、讨论 CEI 模型的优化方向（包括体感曲线、风险阈值、本地化等）。

* **提交 Pull Request（PR）**
  你可以贡献：

  * Bug 修复
  * 模型优化（热舒适、空气质量、风险评估等）
  * 新的数据源适配（例如更多国家/地区预警系统）
  * 其他语言实现（JS / Python / Go 等）
  * 文档完善（README、示例、集成指南）

* **分享数据与反馈**
  可以提供不同地区、不同人群、不同场景下的体感反馈与风险案例，
  用于未来基于数据的校准与模型升级。

### 项目愿景

将 CEI 打造成 **开放、透明、可验证且可扩展的环境舒适与风险指数标准**，
在科学模型与人类体感之间构建可靠桥梁，让天气应用真正“聪明”起来，
成为未来智能天气助手与环境 AI 系统的底层基础设施之一。

---

如果你喜欢这个项目，欢迎给仓库一个 ⭐ Star。
