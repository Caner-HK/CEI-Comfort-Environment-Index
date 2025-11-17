<p align="center">
  <img src="./access/CWC-CEI-Logo.png" 
       alt="CEI Logo" 
       width="200">
</p>

<h1 align="center">CWC CEI – 环境舒适度指数 <br><span>（Comfort Environment Index）</span></h1>

<p align="center">
  <strong>一套将天气与空气质量整合为 0–100 舒适评分的智能算法</strong><br>
  由 <strong>CWC Platform / Caner HK</strong> 提供支持
</p>

<p align="center">
  🌐 <a href="README.md"><strong>English Documentation（英文文档）</strong></a>
</p>

---

CEI（环境舒适度指数）是一套将天气数据与空气质量数据转化为 0–100 环境舒适评分的智能算法。  
它旨在让天气应用从单纯的“数据展示”迈向真正的 **智能环境感知**，提供更贴近人体体验的天气分析能力。


## 🚀 特性介绍

### ✔ 气候带模型（Climate Zone Model）
CEI 的基础是“不同地区对温度的耐受与舒适上限并不相同”。  
算法基于 **纬度自动划分全球三大气候带**：
- **热带（Tropical）**：常年偏暖，对较高温度更宽容  
- **温带（Temperate）**：四季分明，舒适温度随季节波动  
- **极地（Polar）**：偏冷气候，人们对低温耐受性更高  

并结合月份自动应用 **季节修正（Seasonal Adjustment）**：  
- 夏季（6–8 月）：适度提升舒适温度上限  
- 冬季（12–2 月）：适度降低舒适温度基准（考虑衣物因素）  

CEI 为不同气候带生成独立的 **comfortTemp（舒适温度基准）**，使不同地区的评分更加符合当地习惯。

---

### ✔ 真实体感模型（Physical Thermal Perception）
人体对冷热的感受不是由气温单独决定，CEI 采用国际通用的双模型体系：

#### **Heat Index（高温体感模型）**
当温度较高时，用湿度与温度共同计算体感热度，将“闷热感”纳入评分。

#### **Wind Chill（低温体感模型）**
在低温 + 强风条件下，风寒效应会使体感温度远低于实际温度。  
CEI 采用北美/加拿大标准算法，计算实际体感冷度。

#### **模型自动切换（智能体感）**
- 温度 ≥ 20℃：使用 Heat Index  
- 温度 < 20℃：使用 Wind Chill  
- CEI 会根据情境自动选择最能代表体感状态的模型  

这让体感分数更加贴近实际感受。

---

### ✔ 动态权重调整（Dynamic Weight Adjustment）
环境因素在不同情境下的重要性不同。  
CEI 内置权重动态调整系统，根据天气即时状况对评分结构进行再平衡：

- **温度**：极热/极冷时提高热舒适权重  
- **PM2.5**：空气污染加重时提高空气质量权重  
- **UVI**：紫外线 > 8 时额外提高 UV 权重  
- **风速**：强风条件下提高热舒适比重（因为体感下降明显）  

动态权重的核心目标：  
> 在极端天气中，把最关键的感受因素放在最前面。

---

### ✔ 天气现象不适惩罚（Weather Condition Penalty）
天气类型会带来直接体感影响。CEI 完整解析 **OpenWeather weather id**，并根据不同天气等级应用不同强度的体感扣分。

支持全系列：
- **雷暴（2xx）**：强烈不适、危险性高 → 最高扣分（15–20）  
- **毛毛雨（3xx）**：轻度湿冷不适 → 低扣分（6）  
- **雨（5xx）**：按雨强分为小雨/中雨/大雨/冻雨 → 8–20  
- **雪（6xx）**：按雪量与雨夹雪类型分级 → 12–20  
- **大气类（7xx：雾、烟、霾、沙尘、扬沙、火山灰、飑线）** → 10–18  
- **晴（800）**：0 扣分  
- **多云（801–804）**：少云几乎不扣，阴天扣 6 分  

天气惩罚不直接拉低空气质量，而是影响“体感舒适度”（热舒适组件），让评分更符合使用者直觉。

---

### ✔ 国际空气质量评分（International AQ Component）
CEI 采用**六大污染物**的独立评分体系，并取“最差项”作为最终空气舒适度分：

- PM2.5 – 细颗粒物  
- PM10 – 可吸入颗粒物  
- O₃ – 臭氧  
- CO – 一氧化碳（自动从 μg/m³ 转 mg/m³）  
- NO₂ – 二氧化氮  
- SO₂ – 二氧化硫  

采用国际健康标准分段，将多项污染统一处理为一个 0–100 分值。  
空气质量评分呈现“短板效应”，更贴近真实暴露风险。

---

### ✔ CEI 综合输出（0–100）
综合考虑以下四大组件：

- 热舒适（Heat Score）  
- 空气舒适（Air Score）  
- 紫外线舒适（UV Score）  
- 气压舒适（Pressure Score）  
- 天气惩罚与季节/气候修正  

实时计算得出 0–100 的环境舒适度指数，并根据区间映射到：

- Level 1 – 非常舒适  
- Level 2 – 舒适  
- Level 3 – 可接受  
- Level 4 – 明显不适  
- Level 5 – 建议避免暴露  

系统内置安全机制，确保结果稳定且不会超过 100。

---

### ✔ 多语言 / 多平台无损重写能力

CWC CEI 算法在设计之初就被拆分为 **纯计算核心**，不依赖特定框架或 I/O，  
这意味着它可以在不同语言中 **无损重写（逻辑与精度保持一致）**，适配多种平台。

- 当前参考实现为 **PHP 版本**
- 算法可以平滑迁移到：
  - **JavaScript / TypeScript**（Web 前端、Node.js 后端）
  - **Python**（数据分析、AI 模型、后端服务）
  - **Java / Kotlin**（Android / 服务器应用）
  - **C / C++ / Rust / Go**（嵌入式设备、高性能服务）
  - **Swift**（iOS / macOS 客户端）

核心特性：

- CEI 核心函数为 **纯函数**：
  - 不在内部做任何网络请求或文件读写  
  - 所有输入均为显式传入（天气 + 空气质量 + 环境上下文）  
  - 相同输入一定得到相同输出（便于测试与复现）  

因此，CEI 非常适合：

- 集成到 **手机 App**（天气应用、健康应用）
- 用于 **Web 前端 / 后端 API**（SaaS、开放平台）
- 部署在 **物联网 / 边缘设备**（天气站、电子墨水屏终端）
- 嵌入 **数据分析 / AI 决策系统**（出行建议、健康风险评估）

换句话说，CWC CEI 不是一段某种语言的脚本，而是一个可以在整个技术栈中共享的 **通用环境舒适度模型**。

---

## 📦 安装

```bash
git clone https://github.com/Caner-HK/CEI-Comfort-Environment-Index
```

PHP 项目中引用：

```php
require 'cei.php';
```

---


## 🧩 调用示例

CEI 算法直接基于 **OpenWeather One Call 3.0 API** 和 **Air Pollution API** 的数据结构构建。

### 📡 数据来源说明

CEI 需要从以下两个 OpenWeather 接口获取实时天气与空气质量数据：

| API | 用途 | 文档地址 |
|-----|------|-----------|
| **One Call API 3.0** | 提供实时天气，如温度、湿度、风速、气压、紫外线、天气状况等。 | https://openweathermap.org/api/one-call-3 |
| **Air Pollution API** | 提供空气污染物浓度，包括 PM2.5、PM10、O₃、CO、NO₂、SO₂。 | https://openweathermap.org/api/air-pollution |

### 🔑 如何获取 API Key
前往 OpenWeather 注册账号：  
https://home.openweathermap.org/users/sign_up

登录后在此生成 API Key：  
https://home.openweathermap.org/api_keys

### 📥 CEI 所需字段

从 **One Call API 3.0** 的 `current` 字段获取：
- `temp`（温度）
- `humidity`（湿度）
- `wind_speed`（风速）
- `pressure`（气压）
- `uvi`（紫外线）
- `weather[0].id`（天气现象编号）

从 **Air Pollution API** 的 `list[0].components` 字段获取：
- `pm2_5`
- `pm10`
- `o3`
- `co`
- `no2`
- `so2`

### 🧠 CEI 调用流程
1. 使用 **One Call 3.0 API** 获取实时天气  
2. 使用 **Air Pollution API** 获取空气质量污染物数据  
3. 将两份数据输入到 `computeCEI()`  
4. 返回包含舒适度分数、等级、各子项得分的完整 CEI 结果

示例代码：
```php
require 'cei.php';

$data = [
    'temp'       => 6.0,
    'humidity'   => 46,
    'wind_speed' => 9.85,
    'pm2_5'      => 3,
    'pm10'       => 6,
    'o3'         => 50,
    'co'         => 150,
    'no2'        => 12,
    'so2'        => 5,
    'uvi'        => 1.4,
    'pressure'   => 1036,
];

$weatherId = 803; // broken clouds
$unit = 'metric';
$latitude = 22.3;
$month = 1;

$cei = computeCEI($unit, $data, $latitude, $month, $weatherId);

print_r($cei);
```

---

## 📊 版本历史

| 版本号  | 日期       | 更新内容 |
|--------|------------|----------|
| v1.0.0 | 2025-11-14 | 初始版本：包含基础 CEI 计算（温度、湿度、风速）与简单权重模型。 |
| v2.0.0 | 2025-11-17 | **气候带 / 天气现象增强版**：加入气候带模型、天气状况惩罚、风寒指数、热指数、动态权重、0–100 安全机制，并优化空气质量评分。 |

**当前版本：v2.0.0 – 气候带 / 天气现象增强版**

---

## 🤝 参与贡献（欢迎加入共建）

我们欢迎所有开发者、研究者、气象爱好者参与 CEI 的建设与完善。

### 如何参与？

- **提交 Issue**  
  反馈 Bug、提出新功能建议、讨论 CEI 模型的优化方向。

- **提交 Pull Request（PR）**  
  你可以贡献：
  - Bug 修复  
  - 模型优化（热舒适、空气质量、天气惩罚等）  
  - 新增气候带或本地化支持  
  - 代码结构优化  
  - 文档完善（README、示例、说明文档）

- **分享数据与反馈**  
  可提供不同气候区的体感数据，用于未来 CEI 的标定和模型升级。

### 贡献指南

- 保持代码整洁，注释清晰  
- 使用有意义的提交信息  
- 大型变更建议先提交 Issue 讨论设计方向  
- 在现有结构基础上进行扩展（除非提出明确的重构方案）

### 项目愿景

将 CEI 打造成 **最先进的开源环境舒适度模型**，在科学模型与人类体感之间构建可靠桥梁，让天气应用真正“聪明”起来。

---

如果你喜欢这个项目，欢迎给仓库一个 ⭐ Star

