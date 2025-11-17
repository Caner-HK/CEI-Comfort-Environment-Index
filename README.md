<p align="center">
  <img src="./access/CWC-CEI-Logo.png" 
       alt="CEI Logo" 
       width="200">
</p>

<h1 align="center">CWC CEI – Comfort Environment Index</h1>

<p align="center">
  <strong>An intelligent environmental comfort scoring algorithm (0–100)</strong><br>
  Powered by <strong>CWC Platform / Caner HK</strong>
</p>

<p align="center">
  🌏 <a href="README-zh.md"><strong>中文文档（Chinese Documentation）</strong></a>
</p>

---

CEI (Comfort Environment Index) is an environmental comfort scoring algorithm that transforms raw weather and air-quality data into a unified 0–100 comfort score.  
It is designed to push weather applications beyond simple data display and toward **intelligent environmental perception**, enabling smarter, human-centered weather insights.


## 🚀 Feature Overview (Detailed)

### ✔ Climate Zone Model
CEI begins with the principle that *comfort perception varies across different regions of the world*.  
Using latitude, the algorithm automatically classifies each location into a major climate zone:

- **Tropical** – consistently warm, higher tolerance to heat  
- **Temperate** – four distinct seasons with shifting comfort expectations  
- **Polar** – cold-dominant regions with higher tolerance to low temperatures  

The model also applies a **seasonal adjustment factor** based on month:
- Summer (June–August): comfort temperature slightly increased  
- Winter (December–February): comfort temperature slightly decreased (considering clothing and adaptation)  

Each zone receives a unique **comfortTemp baseline**, ensuring CEI behaves more naturally across regions.

---

### ✔ Physical Thermal Perception
Human thermal sensation is affected by more than temperature alone.  
CEI integrates two internationally recognized thermal discomfort models:

#### **Heat Index (hot discomfort)**
Considers humidity and temperature together to estimate perceived “muggy” or “oppressive” heat.

#### **Wind Chill (cold & wind discomfort)**
Under low temperatures and strong wind, the perceived temperature can drop significantly.  
CEI uses a North American/Canadian standard wind chill calculation.

#### **Automatic Model Switching**
- If temperature ≥ 20°C → use Heat Index  
- If temperature < 20°C → use Wind Chill  

This adaptive approach ensures CEI always uses the model that best reflects real human perception.

---

### ✔ Dynamic Weight Adjustment
Environmental factors contribute differently depending on conditions.  
The dynamic weighting system adjusts factor importance based on real-time weather:

- **Temperature** → higher weight in extreme heat/cold  
- **PM2.5** → increased importance during pollution events  
- **UV Index** → higher weight when UVI > 8  
- **Wind Speed** → strong winds amplify thermal discomfort, increasing heat comfort weight  

The goal is to ensure the CEI remains **realistic and perception-driven**, even under extreme conditions.

---

### ✔ Weather Condition Penalty (OpenWeather Weather ID)
Weather conditions directly impact comfort.  
CEI fully interprets all OpenWeather `weather id` groups and applies calibrated discomfort penalties:

- **Thunderstorms (2xx)** – strong penalties (15–20) due to danger & intensity  
- **Drizzle (3xx)** – light wet discomfort (≈6)  
- **Rain (5xx)** – graded penalties: light (8), moderate (12), heavy/freezing rain (16–20)  
- **Snow (6xx)** – graded by intensity: 12–20  
- **Atmospheric phenomena (7xx)** – mist, haze, fog, dust, sand, ash, squalls (10–18)  
- **Clear (800)** – no penalty  
- **Clouds (801–804)** – mild penalty depending on cloud coverage (1–6)  

Weather penalties adjust **thermal comfort**, reflecting real-world outdoor perception.

---

### ✔ International Air Quality Scoring
CEI evaluates six major pollutants using international standards:

- PM2.5  
- PM10  
- O₃ (ozone)  
- CO (converted from µg/m³ → mg/m³ automatically)  
- NO₂  
- SO₂  

Each pollutant is scored independently, and **the final air score is the minimum (worst) among them**, ensuring health relevance and avoiding false comfort under partial pollution.

---

### ✔ CEI Final Output (0–100)
CEI aggregates four major comfort components:

- Thermal comfort  
- Air quality comfort  
- UV comfort  
- Pressure comfort  
- + Weather condition penalty  
- + Seasonal/climate adjustment  

This produces a final 0–100 score classified into:

- **Level 1 – Very comfortable**  
- **Level 2 – Comfortable**  
- **Level 3 – Acceptable**  
- **Level 4 – Noticeably uncomfortable**  
- **Level 5 – Poor comfort / avoid exposure**  

A built-in safety clamp ensures CEI remains stable and never exceeds 100.

---

### ✔ Multi-language & Multi-platform Ready

The CWC CEI algorithm is designed as a **pure, side-effect-free calculation core**, which makes it easy to re-implement in multiple programming languages **without any loss of logic or precision**.

- The reference implementation is written in **PHP**.
- The algorithm can be losslessly ported to:
  - **JavaScript / TypeScript** (web frontends, Node.js backends)
  - **Python** (data science pipelines, AI integration, backend services)
  - **Java / Kotlin** (Android apps, server applications)
  - **C / C++ / Rust / Go** (embedded devices, high-performance services)
  - **Swift** (iOS / macOS apps)
- The core is purely functional:
  - No external I/O inside the CEI functions  
  - All inputs are explicit (weather + air-quality data + context)  
  - All outputs are deterministic (same input → same CEI result)  

This design allows CEI to be:

- Embedded into **mobile apps** (iOS / Android)
- Integrated into **web frontends** and **backend APIs**
- Deployed in **IoT / edge devices** (e.g., weather stations, e-ink displays)
- Used in **data analysis pipelines** and **AI recommendation systems**

CWC CEI is not just a PHP script, but a **portable environmental comfort model** that can be shared across your entire ecosystem.

---

### ✔ CEI Output
```
{
  "cei": 68,
  "level": "CEI Level 3 – Cool/Warm but acceptable",
  "components": {
    "heatScore": 58,
    "airScore": 95,
    "uvScore": 100,
    "pressScore": 60
  }
}
```

---

## 📦 Installation

```bash
git clone https://github.com/Caner-HK/CEI-Comfort-Environment-Index
```

Include in your PHP project:

```php
require 'cei.php';
```

---

## 🧩 Usage Example

The CEI algorithm is designed to work directly with data from the **OpenWeather One Call 3.0 API** and **Air Pollution API**.

### 📡 Data Source

CEI requires weather and air quality data from the following OpenWeather APIs:

| API | Purpose | Documentation |
|-----|---------|---------------|
| **One Call API 3.0** | Provides real-time weather data including temperature, humidity, wind speed, pressure, UVI, and weather conditions. | https://openweathermap.org/api/one-call-3 |
| **Air Pollution API** | Provides pollutant concentrations such as PM2.5, PM10, O₃, CO, NO₂, SO₂. | https://openweathermap.org/api/air-pollution |

### 🔑 How to Get an API Key
To access these APIs, sign up at:  
https://home.openweathermap.org/users/sign_up

After logging in, generate your API Key here:  
https://home.openweathermap.org/api_keys

### 📥 Required Data Input

CEI uses fields from both APIs:

From **One Call API 3.0** (`current`):
- `temp`
- `humidity`
- `wind_speed`
- `pressure`
- `uvi`
- `weather[0].id`

From **Air Pollution API** (`list[0].components`):
- `pm2_5`
- `pm10`
- `o3`
- `co`
- `no2`
- `so2`

### 🧠 How It Works
1. Request current weather from **One Call 3.0 API**  
2. Request pollutant data from **Air Pollution API**  
3. Pass both datasets into `computeCEI()`  
4. Receive a complete CEI output with component scores and comfort level

Example Code:

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

## 🤖 Low-Cost AI Integration – Enabling Strong Weather Reasoning in Lightweight Models

Most general-purpose AI models struggle to accurately understand how weather affects human comfort.  
They can read temperature, wind, AQI, or humidity—but they cannot combine these factors into a meaningful, human-centered interpretation.

CEI solves this by acting as a **structured, climate-aware perception layer** that even small models can understand.

### Why this matters for low-cost AI systems

Large models (LLMs) have high inference cost and still produce inconsistent reasoning about:
- Human thermal comfort
- Wind-chill vs heat-index effects
- AQI health implications
- Climate-zone differences
- Weather-condition discomfort

By contrast, CEI provides:
- A unified 0–100 comfort score  
- Component-level breakdown (heat/air/UV/pressure)
- Weather-ID discomfort mapping  
- Climate & seasonal adjustments  
- Deterministic and interpretable features  

This allows **lightweight models with limited reasoning ability** to behave like much larger, more expensive models.

### Key advantages

#### ✔ **Low computation cost**
Small models (1B–3B parameters) can understand CEI instantly—no complex reasoning required.

#### ✔ **High-quality suggestions**
Once trained with CEI, lightweight models can produce:
- More accurate weather advice  
- Region-aware comfort recommendations  
- Activity/health/outdoor safety suggestions  
- Better personalized insights  

Often **equal to or better than large models**, because CEI provides strong structured signals.

#### ✔ **Easy to train**
Training requires simple supervised data:
```
Weather Data → CEI → Human-like Explanation
```
Small models quickly learn the mapping between:
- CEI value  
- Comfort level  
- Practical human recommendations  

#### ✔ **Commercially viable**
Low-cost inference enables:
- High-volume API calls  
- Real-time mobile app usage  
- Edge/IoT deployment  
- Scaling to millions of users without high compute bills  

CEI turns ordinary AI models into **market-ready intelligent weather advisors**, without requiring expensive cloud LLMs.

**CEI enables small, affordable AI models to understand weather like humans—and offer advice like experts.**

## 📊 Version History

| Version | Date       | Description |
|--------|------------|-------------|
| v1.0.0 | 2025-11-14 | Initial release. Basic CEI with temperature, humidity, wind, simple weighting. |
| v2.0.0 | 2025-11-17 | **Climate & Weather Enhanced Edition**: Added climate zone model, weather ID penalties, wind chill, heat index, dynamic weights, 0–100 safety mechanism, improved air-quality scoring. |

**Current version: v2.0.0 – Climate & Weather Enhanced Edition**

---

## 🤝 Contribution

We warmly welcome contributions from developers, researchers, and weather/climate enthusiasts.

### How to contribute
- **Open an Issue**  
  Report bugs, propose new features, discuss CEI model improvements.
- **Submit a Pull Request (PR)**  
  - Fix bugs  
  - Improve scoring models  
  - Add new weather factors  
  - Optimize documentation  
- **Share datasets or feedback**  
  Especially for improving comfort calibration in different climate zones.

### Contribution Guidelines
- Keep code clean and well-documented  
- Use meaningful commit messages  
- For larger changes, please open an issue first to discuss the design  
- Respect existing structure unless proposing a clear improvement

### Community Goal
Building CEI into the **most advanced open-source environmental comfort model**, integrating scientific accuracy with real-world human perception.

---

If you like this project, please ⭐ **star the repository**


