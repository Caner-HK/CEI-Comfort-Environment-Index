# 🌐 CWC Platform — CEI：环境舒适度指数  
由 **Caner HK** 开发的多维度人体体感指数

---

## 📌 概述

**CEI（Comfort Environment Index）** 是 CWC Platform 推出的新一代体感指数，将复杂的气象参数转换为 **0–100 的环境舒适度分数**。

传统天气 APP 仅显示：
- 温度  
- 体感温度  
- 湿度  
- 紫外线  
- 空气质量  
- 风速  
- 气压  

但无法回答：
**“现在这个环境，到底舒不舒服？”**

CEI 正是为了解决这一缺口。

---

## 🎯 CEI 的意义

### 当前天气应用的不足
- 数据孤立  
- 体感算法过于简单  
- 未考虑气候差异  
- 缺乏综合舒适度指标  
- 没有动态权重机制  

### CEI 提供
- 统一指数（0–100）  
- 温湿风 + 污染物 + 紫外线 + 气压的融合  
- 气候自适应  
- 多因子动态权重  
- 更贴近真实人体感受  

---

## 🧠 核心设计理念

1. 多源环境融合  
2. 动态权重智能模型  
3. 气候带 + 季节修正  
4. 专业科学模型（NOAA、WHO 等）  
5. 人体体感导向的评分方式  

---

## 📐 算法流程

    输入 → 单位转换
         → 动态权重调整
         → 气候区校正
         → 四大分项得分：
               - 热舒适
               - 空气质量
               - 紫外线压力
               - 气压舒适度
         → 加权综合
         → 气候因子修正
         → CEI（0–100）
         → 舒适等级

---

## 🧩 文件结构

- computeCEI()  
- dynamicWeightAdjustment()  
- adjustForClimate()  
- calculateThermalComfort()  
- calculateAirQualityScoreInternational()  
- calculateUVScore()  
- calculatePressureScore()  
- getCEILevel()  

（完整代码见 CEI.php）

---

## 🛠️ 调用示例
```php
    require 'CEI.php';

    $data = [
        'temp' => 29,
        'humidity' => 70,
        'wind_speed' => 2.5,
        'pm2_5' => 12,
        'pm10' => 35,
        'o3' => 90,
        'co' => 500,
        'no2' => 22,
        'so2' => 10,
        'uvi' => 6,
        'pressure' => 1008
    ];

    $result = computeCEI('metric', $data, 34.05, 8);
    print_r($result);
```
### 示例输出
```
    {
      "cei": 82,
      "level": "Good",
      "components": {
        "heatScore": 78,
        "airScore": 85,
        "uvScore": 70,
        "pressScore": 90
      }
    }
```
---

## 🔭 发展路线图

### v2 — 科学增强版
- 引入 PMV/PPD（ASHRAE 55 标准）  
- 加入 WBGT / VPD / 24 小时气压变化  
- 更精细的气候带分类  

### v3 — 机器学习版
- 使用体感标注数据训练  
- 自动学习权重  
- 地区差异适配  

### v4 — 个性化 CEI（PCEI）
- 年龄 / 性别 / 敏感度  
- 健康因素（呼吸、心血管）  
- 个体模型微调  

### v5 — CEI 生态
- CEI 预测曲线  
- CWC MetAI 体感建议系统  
- IoT 本地化指数  

---

## 🤝 贡献方式

欢迎：
- 提交 Issue  
- 提交 PR  
- 提出模型改进  
- 贡献专业数据  

---

## 🏷️ 许可证

Apache License 2.0  
© Caner HK — CWC Platform

---

## 🧭 联系方式

合作 / 学术交流 / 技术咨询欢迎联系：
**Caner HK — CWC Platform**
