# CEI 风险层（Risk Layer）ReadMe —— Hazard Bucket Fusion 设计说明（v3.2.0）

本风险层用于把“危险程度”从舒适度模型中独立出来，并以 **RiskCap（压帽上限）** 的形式约束最终 CEI：

> **CEI = min(ComfortCEI, RiskCap)**  
> 分数区间统一为 **[0, 100]**。

风险层的核心目标不是“更不舒服”，而是更准确地表达 **安全/健康风险**，并且在“体感数据”与“官方预警”同时存在时，**避免同源重复扣分**。

---

## 1. 输入与输出

### 1.1 输入（ctx）
风险层函数：`computeRiskLayer(array $ctx): array`

- 体感/天气：
  - `T`（°C）, `RH`（%）, `wind`（m/s）, `windGust`（m/s|null）
  - `dewPoint`（°C|null）, `heatIndex`（°C）
  - `weatherId`（OpenWeather 风格 int）
- 空气健康（用于风险，不是舒适得分）：
  - `pm25`, `pm10`, `o3`, `co`, `no2`, `so2`
- 官方预警（已通过适配层标准化）：
  - `alerts`（array）
- 评估时刻：
  - `evalTs`（UTC 秒；forecast 场景关键）

### 1.2 输出（核心字段）
- `risk_score`：总体风险强度（0=无风险，100=极端风险）
- `risk_cap`：风险压帽上限（0–100；**越低越危险**，用于压 CEI）
- `risk_hint_score`：提示分（更敏感，更适合“提醒”）
- `risk_focus_score`：关注度（用于“重点关注”）
- `hazards`：hazard 分桶明细（每个 hazard 的 P/A/Q/R/Focus）
- `factors`：用户可读风险因素（建议前端展示的 hazard 列表）
- `debug_flags`：内部调试标记（不建议作为用户因素展示）

---

## 2. 总体结构：两条链路 + 一次融合

风险层把风险来源拆为两条链路：

1) **Physical（体感/观测推导）** → 每个 hazard 的 `P`  
2) **Alert（预警信号）** → 每个 hazard 的 `A` 与 `Q`  
然后对每个 hazard 做 **融合** 得到 `R`（最终用于压帽的风险强度）

---

## 3. Hazard 分桶（Bucket）是什么？

我们把风险按“危险类型”分桶（hazard bucket），例如：

- `extreme_cold`, `extreme_heat`
- `snow_ice`, `heavy_rain`, `thunderstorm`, `wind`, `fog`, `dust_sand`, `tornado`
- `air_quality`
- 以及 `flood/coastal/tropical_cyclone/fire/geohazard/...`（主要由预警驱动）

这样做的意义：
- 可解释：能清楚回答“风险来自哪里”
- 可融合：同一类风险只在自己的桶里融合，不会乱扣
- 可视化：hazard 雷达、桶条形图、Focus 高亮都更自然

---

## 4. Physical 链路：计算 P（0–1）

### 4.1 温度极端（`computeTemperatureRiskScore`）
- 计算 **风寒 WindChill**（冷风险）与 **热指数 HeatIndex**（热风险）
- 得到：
  - `cold_score`（0–100）→ `P['extreme_cold'] = cold_score/100`
  - `heat_score`（0–100）→ `P['extreme_heat'] = heat_score/100`
- 细节：
  - 若存在 `windGust` 且更大，优先用 gust 推导风寒
  - 高露点在热端会对 heatIndex 做小幅提升（更贴近“闷热风险”）

### 4.2 天气现象（`computeWeatherRiskScore`）
从 `weatherId` 映射到 hazard 风险（0–1），典型规则：
- 雷暴 → `thunderstorm`
- 降雨 → `heavy_rain`（按强度分段）
- 雪/冻雨 → `snow_ice`
- 雾/霾/沙尘/龙卷 → `fog / air_quality / dust_sand / tornado`
同时引入风风险：
- `wind`：由 gust（或 wind_speed 回退）映射到 0–1

关键修正：
- 对 `snow_ice` 在 **接近 0°C（-3~1°C）** 的临界温度带做增强  
  目的：更符合道路结冰/湿滑的直觉风险。

### 4.3 空气健康风险（`computeAirHealthRisk`）
区别于“空气舒适得分”，这里输出的是 **健康风险**：
- 当前以 `PM2.5` 与 `O3` 为主导得到 `risk_01`
- `P['air_quality'] = risk_01`

---

## 5. Alert 链路：计算 A 与 Q（0–1）

风险层不直接使用“预警文本”，而使用适配层规范化后的字段：

### 5.1 hazard 提取
`extractHazardsFromTags(tags)` 从 tags 中识别：
- `hazard:xxx` → hazard 列表  
若缺失则归为 `other`。

### 5.2 预警强度 A
每个 hazard 的 A 由三部分构成：
- hazard 基准强度：`hazard_base[hz]`（不同灾害的“默认危险量级”）
- 严重度：`sev01`（由 severity 或 severity_score 映射到 0–1）
- 时间相位参与：`tFactor`（lead/active/past）

组合形式（实现里）：
- `a = base * sev01 * (0.65 + 0.35 * tFactor)`
- 并截断到 [0,1]  
多条预警同一 hazard 取最大值（最强那条主导）：
- `A[hz] = max(A[hz], a)`

### 5.3 预警可信度/命中度 Q
Q 用来表达“这条预警值不值得采纳”，来自 tags 的多因子乘积：

- `certainty`：observed/likely/possible/unknown...
- `urgency`：immediate/expected/future...
- `area`：point/local/city/regional/national/marine...
- 时间相位：`tFactor`

组合：
- `q = tFactor * certainty01 * urgency01 * area01`
- `Q[hz] = max(Q[hz], q)`

### 5.4 时间相位（`mapAlertTimeFactor(startTs, endTs, evalTs)`）
根据评估时刻 `evalTs` 判断：
- **active**：生效中 → factor=1
- **lead**：未开始 → factor 随提前量衰减（越远越弱）
- **past**：结束后超过 1h → factor=0（直接忽略）
- **unknown_time**：无 start/end → factor=0.85（保守中等）

这使 forecast 场景下：
- “未来某一小时的 CEI”会自动使用那一小时的预警相位，而不是用当前相位硬算。

---

## 6. 核心融合：R = P + Q * max(0, A - P)

对每个 hazard（桶）同时拥有：
- `P`：Physical（体感推导）
- `A`：Alert 强度
- `Q`：Alert 可信度

融合得到 `R`（最终用于压帽）：

> **R = P + Q · max(0, A − P)**

解释：
- 若体感已经很强（P 高），预警不会“再扣一次分”（避免重复）
- 只有当预警强度高于体感（A > P）时，预警才会在差值上补缺口
- Q 控制“补多少”：可信度低则补得少，可信度高则补得多

### 6.1 临界增强（borderline boost）
当出现典型“临界但危险”的组合时，允许对 R 做很小幅提升：
- `P` 在 0.35–0.65（临界）
- `A` ≥ 0.75（预警强）
- `Q` ≥ 0.60（可信度高）

用途：
- 强化低温临界雪冰、临界大风等“很容易出事故”的时段，
  但仍保持不把风险硬拉满。

---

## 7. Focus：关注度（0–1）用于提醒/高亮

Focus 不是压帽主量，而是“提醒强度”：

> **Focus = max(P, A) + β · min(P, A) · Q**（β≈0.35）

直觉：
- 只要 P 或 A 有一边很高，就值得关注（max 部分）
- 当两者同时存在且 Q 高时，关注度会进一步提高（协同项）

---

## 8. 从桶到总体：Noisy-OR 聚合 + 影响权重

每个 hazard 有不同的“危险影响权重” `w(hz)`（如台风/龙卷更高，雾更低）。

总体聚合使用 **Noisy-OR**（概率式叠加，不会线性爆炸）：

> overall = 1 − Π(1 − x_hz · w_hz)

其中 `x_hz` 可以是 `R` 或 `Focus`：
- `R_overall`：用于 risk_score 与 risk_cap
- `Focus_overall`：用于 risk_focus_score

---

## 9. risk_score 与 risk_cap 的关系

- `risk_score = 100 * R_overall`  
  越高越危险（直觉一致）

- `risk_cap = mapOverallRiskToCap(R_overall)`  
  越低越危险（因为它是“上限”）

当前实现使用幂函数让高风险区更敏感：
> cap = 100 * (1 - r01^γ)，γ≈1.35

因此：
- 低风险：cap 仍接近 100，基本不压舒适层
- 高风险：cap 下降明显，主动把 CEI 压到“危险可感知”的区间

---

## 10. risk_hint（提示分）与 factors（用户可读因素）

### 10.1 risk_hint_score（更敏感）
提示分用 `max(P, A)` 聚合（比 R 更敏感）：
- 目的：适合做“提醒”，即使不一定触发压帽，也要让用户知道风险存在。

### 10.2 factors（建议前端展示）
`factors` 直接输出 hazard 名称列表（最多 8 个），挑选逻辑：
- `R >= 0.35` 或 `Focus >= 0.45` 就入选
- 排序优先 Focus，其次 R
- 避免 UI 过载

### 10.3 debug_flags（不要当因素展示）
`debug_flags` 用于开发诊断：相位、边界增强、是否使用 gust 等。
建议只在 debug 面板显示。

---

## 11. 预警适配层的最小规范（建议）

风险层假定 alerts 已被标准化为类似结构（每条 alert）：

- `start_ts` / `end_ts`：UTC 秒（可为空，但最好提供）
- `severity` 或 `severity_score`（0–1）
- `tags`：字符串数组，至少建议包含：
  - `hazard:xxx`
  - `certainty:observed|likely|possible|unknown`
  - `urgency:immediate|expected|future|unknown`
  - `area:point|local|city|regional|national|marine|unknown`

这样风险层才能做到：
- 正确判断 forecast 的 lead/active/past
- 以 Q 控制“是否采纳预警强度”
- 在 UI 上给出清晰可解释的 hazard 因素

---

## 12. 推荐 UI 用法（实践建议）

- 主分展示：
  - `cei`（最终）
  - `detail.risk_cap`（压帽上限：为什么被压）
- 风险解释：
  - `detail.risk.factors`（用户可读）
  - `detail.risk.hazards`（可视化：桶条形图/雷达图）
- 提醒增强：
  - `risk_hint` 与 `risk_focus` 可以做“提醒强度”和“重点关注”徽标
- Debug：
  - `debug_flags` 仅在调试界面显示，不要直接面向用户

---

## 13. 可调参点（未来扩展建议）

- `hazard_base`：不同灾害的预警基准强度（地区化可调）
- `impactW`：hazard 影响权重（面向不同人群：儿童/老人/户外作业可定制）
- `γ`：risk→cap 映射曲线（决定压帽“敏感度”）
- `βFocus`：Focus 协同强度（决定“重点关注”的强化程度）
- 空气健康风险：可扩展 PM10/NO2/SO2/CO 的健康风险建模（目前偏保守）

