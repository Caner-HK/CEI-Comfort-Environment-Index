# 🌐 CWC Platform — CEI: Comfort Environment Index  
A multi-dimensional, human-centric environmental comfort index  
Created by **Caner HK**

---

## 📌 Overview

**CEI (Comfort Environment Index)** is an open environmental comfort scoring system under the **CWC Platform**.  
Its goal is to transform raw meteorological and environmental sensor data into a unified **0–100 human comfort score**.

Most weather applications only provide:
- Temperature / Feels-like temperature  
- Humidity  
- UV index  
- Air quality index (AQI)  
- Wind speed  
- Atmospheric pressure  

But none can answer:
**“How comfortable is it right now?”**

CEI fills this gap.

---

## 🎯 Why CEI?

### Problems in current weather apps:
- Environmental data is fragmented  
- Comfort calculations are simplistic  
- No climate or seasonal adaptation  
- No unified comfort score  
- No dynamic weighting of multi-factor influence  

### CEI provides:
- Unified comfort score (0–100)  
- Thermal, air quality, UV, pressure fusion  
- Climate-adaptive scoring  
- Dynamic weight system  
- Human-centric perception modeling  

---

## 🧠 Core Design Principles

1. Multi-factor meteorological fusion  
2. Dynamic weight adjustment  
3. Climate region & seasonal adaptation  
4. Scientific environmental scoring  
5. Human comfort orientation  

---

## 📐 Algorithm Flow (Simplified)

    Input → Unit normalization
          → Dynamic weight adjustment
          → Climate zone correction
          → Component scoring:
                - Thermal Comfort
                - Air Quality
                - UV Stress
                - Pressure Stability
          → Weighted Calculation
          → Climate Adjustment
          → CEI Output (0–100)
          → Comfort Level Classification

---

## 🧩 File Structure

- computeCEI()  
- dynamicWeightAdjustment()  
- adjustForClimate()  
- calculateThermalComfort()  
- calculateAirQualityScoreInternational()  
- calculateUVScore()  
- calculatePressureScore()  
- getCEILevel()  

(Full implementation located in CEI.php)

---

## 🛠️ Usage Example

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

### Sample Output

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

---

## 🔭 Roadmap

### v2 — Scientific Enhancement
- PMV/PPD comfort model (ASHRAE 55)
- WBGT / VPD / pressure variability
- Improved climate mapping

### v3 — Machine-Learned CEI
- Real comfort-labeled dataset  
- Regression models  
- Region-aware calibration  

### v4 — Personalized CEI (PCEI)
- Age / gender / sensitivity  
- Health-aware comfort scoring  
- Personal adaptation  

### v5 — CEI Ecosystem
- CEI forecast  
- Integration with CWC MetAI  
- IoT optimization  

---

## 🤝 Contributing

We welcome:
- Issues  
- PRs  
- Data contributions  
- Model improvement proposals  

---

## 🏷️ License

Apache License 2.0  
© Caner HK — CWC Platform

---

## 🧭 Contact

For research, collaboration, or integration:
**Caner HK — CWC Platform**
