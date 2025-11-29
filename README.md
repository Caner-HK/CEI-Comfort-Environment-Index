
<p align="center">
  <img src="./access/CWC-CEI-Logo.png" 
       alt="CEI Logo" 
       width="200">
</p>

<h1 align="center">CWC CEI – Comfort Environment Index</h1>

<p align="center">
  <strong>An intelligent 0–100 index that fuses weather, air quality and environmental risk into a single score</strong><br>
  Powered by <strong>CWC Platform / Caner HK</strong>
</p>

<p align="center">
  🌐 <a href="README_zh.md"><strong>中文文档（Chinese Documentation）</strong></a>
</p>

---

CWC CEI (Comfort Environment Index) is an intelligent algorithm that turns raw weather, air quality  
and risk information into a unified **0–100 environment score**.

It is designed to move weather apps beyond “just showing numbers” toward real **human-centered environmental understanding** –  
combining physical comfort, air quality, UV, pressure, and safety risk into one transparent model.

---

## 🚀 Key Concepts

### ✔ Two-Layer Design: Comfort Layer + Risk Cap Layer

Starting from **v3.0.0**, CEI is explicitly structured as a two-layer model:

1. **Comfort Layer**  
   Focuses on “how comfortable the environment feels” under normal conditions:
   - Thermal comfort (temperature, humidity, wind, apparent temperature)
   - Air quality comfort (pollutants)
   - UV comfort
   - Pressure comfort

   This produces a **0–100 comfort CEI**.

2. **Risk Layer**  
   Separately evaluates “how risky the environment is”, including:
   - Extreme cold / extreme heat and associated health risks  
   - Hazardous weather (severe thunderstorms, heavy snow, dust storms, tornadoes, strong winds, etc.)  
   - Official weather / disaster warnings (e.g., typhoon, avalanche, flood, heavy rain, severe pollution)

   The risk layer outputs a **0–100 risk score** and a corresponding **RiskCap**:

   > **RiskCap = 100 − riskScore**

The final CEI is defined as:

> **CEI = min(Comfort CEI, RiskCap)**

This means:

- If the environment feels comfortable but is objectively dangerous  
  (e.g., pleasant weather in a zone with an active avalanche warning),  
  the **risk layer caps the score**, preventing a misleadingly “high” CEI.
- If risks are low, CEI is mainly driven by the comfort layer.

The output structure exposes both layers:

- `cei` – final 0–100 index  
- `components.risk` – risk component score  
- `detail.comfort_cei` – comfort-only CEI  
- `detail.risk_cap` – cap imposed by risk  
- `detail.main_effect` – which factor dominates (heat / air / uv / pressure / risk)

---

### ✔ Climate Zone Model

People in different regions tolerate temperature differently. CEI encodes this via a **climate-aware model**.

Based on latitude and month, the algorithm classifies the location into climate zones (conceptually such as equatorial, tropical, temperate, polar, etc.),  
and assigns a **climate-specific comfort temperature (`comfortTemp`)**.

Seasonal adjustment is applied automatically:

- **Summer (Jun–Aug)** – slightly raise the comfort temperature baseline  
- **Winter (Dec–Feb)** – slightly lower the comfort temperature baseline (assuming clothing)

This ensures:

- The same air temperature can be “acceptable” in one climate zone but “uncomfortable” in another,  
  and CEI reflects this appropriately.

The latitude + month context is also returned under `detail.climate`.

---

### ✔ Physical Thermal Perception

Human thermal perception is not determined by air temperature alone.  
CEI uses a combination of physical indices to model real-world thermal comfort.

#### Heat Index (hot-side perception)

When conditions are warm/hot, CEI uses **Heat Index** (temperature + humidity) to capture:

- Muggy feeling  
- Heat stress / heat illness risk

#### Wind Chill (cold-side perception)

In cold and windy conditions, wind chill can make it feel much colder than the air temperature.  
CEI uses the standard North American / Canadian wind chill formula.

#### Automatic Thermal Model Switching

- **T ≥ 20°C** → use Heat Index as the main effective temperature  
- **T < 20°C** → use Wind Chill as the main effective temperature  

Additional fields:

- **Dew Point (`dew_point`)**  
  When dew point is high (e.g. ≥ 24–26°C) in hot conditions, CEI applies extra penalties for oppressive humidity.
- **Wind Gust (`wind_gust`)**  
  Used to refine wind chill and to assess risk from sudden strong winds.

The thermal section in the CEI result includes:

- `detail.thermal.effective_temp`  
- `detail.thermal.heat_index`  
- `detail.thermal.wind_chill`

This makes it easy to explain *why* the environment feels hot / cold / harsh.

---

### ✔ Dynamic Weight Adjustment

Different factors matter more in different weather situations.  
CEI incorporates a **dynamic weighting system**:

Base weights (normalized to 1):

- Heat comfort (`heat`)  
- Air quality comfort (`air`)  
- UV comfort (`uv`)  
- Pressure comfort (`press`)

These are then adjusted according to the current situation, for example:

- High or low temperatures → increase the weight of thermal comfort  
- Elevated PM2.5 → increase the importance of air quality  
- UVI > 8 → boost the UV component  
- Strong winds → give more weight to thermal comfort (because of wind chill)

The final weights are exported:

```php
'weights' => [
  'heat'     => float,
  'air'      => float,
  'uv'       => float,
  'pressure' => float
]
````

So both UIs and AI agents can see *which dimension matters most right now*.

---

### ✔ Weather Condition Penalty (Using OpenWeather IDs)

Weather type itself has a strong impact on comfort.
CEI uses **OpenWeather weather id** to apply condition-specific penalties to thermal comfort:

* **Thunderstorms (2xx)** – medium to strong penalty; heavier for severe thunderstorms
* **Drizzle (3xx)** – mild discomfort penalty
* **Rain (5xx)** – graded by intensity (light, moderate, heavy, freezing rain)
* **Snow (6xx)** – graded by snow intensity & type (snow, sleet, snow showers, etc.)
* **Atmospheric (7xx)** – mist, fog, haze, dust, sand, volcanic ash, squall, tornado
* **Clear (800)** – no penalty
* **Clouds (801–804)** – from very light to moderate penalties (few clouds → overcast)

These penalties *do not* touch the air quality score.
They only adjust the **thermal comfort component**, better reflecting human perception.

In addition, the **risk layer** separately accounts for hazardous weather such as:

* Severe thunderstorms
* Heavy snowstorms
* Dust storms / sandstorms
* Tornadoes
* High gusts, etc.

---

### ✔ International Air Quality Component

CEI calculates a comfort score for **six pollutants** and then takes the worst one:

* PM2.5 – fine particulate matter
* PM10 – inhalable particulates
* O₃ – ozone
* CO – carbon monoxide (converted from μg/m³ to mg/m³)
* NO₂ – nitrogen dioxide
* SO₂ – sulfur dioxide

Each pollutant uses a threshold-based scale mapped to a 0–100 score.
The final air comfort component is:

> **AirScore = min(score_PM2.5, score_PM10, score_O3, score_CO, score_NO2, score_SO2)**

This “weakest link” design matches real-world health risk:
one pollutant being very bad is enough to cause discomfort / health concerns.

---

### ✔ Risk Recognition & Cap Mechanism

Beyond comfort, CEI v3.0.0 introduces a full **risk recognition layer**:

Risk is decomposed into three sources:

1. **Temperature Risk**

   * Based on wind chill, heat index, dew point, and other indicators
   * Captures frostbite / hypothermia risk and heat stress risk
   * Exposes flags such as `temp_extreme_cold_40`, `temp_heat_35`, etc.

2. **Weather Risk**

   * Uses OpenWeather `weatherId` and `wind_gust`
   * Penalizes severe thunderstorms, freezing rain, heavy snow, dense fog, dust storms, tornadoes, etc.
   * Exposes flags such as `wx_thunderstorm_heavy`, `wx_freezing_rain`, `wind_gust_25`

3. **Alerts Risk**

   * Handles **official warnings** from multiple providers (e.g., OpenWeather, QWeather) via an adapter layer
   * The adapter maps provider-specific fields into a unified structure like:
     `hazard:wind`, `hazard:flood`, `severity:minor/moderate/severe/extreme`, `provider:xxx`
   * Each hazard type has a base risk, modified by severity.

The combined outputs are:

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
    'overall'      => int,   // 0–100
    'from_temp'    => int,
    'from_weather' => int,
    'from_alerts'  => int,
    'flags'        => string[]
  ]
]
```

And the risk cap:

```php
'risk_cap' => float // = 100 - riskScore
```

This makes it possible to say things like:

* “Comfort is high, but avalanche risk is extreme – CEI is capped at 10.”
* “Cold and windy, but no major risk signals – CEI is low mainly due to thermal comfort.”

---

### ✔ Unified, Structured Output

A typical `computeCEI()` call returns:

```php
[
  'cei'   => int,    // 0–100 final index
  'level' => string, // e.g. "CEI Level 2 – Comfortable",

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
    'comfort_cei' => float, // comfort layer only
    'risk_cap'    => float, // 100 - riskScore
    'main_effect' => 'heat'|'air'|'uv'|'pressure'|'risk',

    'climate' => [
      'zone'        => string,
      'factor'      => float,
      'comfortTemp' => float
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

This structure is:

* **UI-friendly** – perfect for dashboards, progress bars, gauges
* **AI-friendly** – perfect as a semantic layer for models to generate advice
* **Machine-friendly** – easy to log, compare, aggregate, and post-process

---

### ✔ Language- & Platform-Agnostic Core

The CEI core is designed as a **pure computation module**:

* No network requests inside the algorithm
* No database or file I/O
* All data must be provided as function parameters
* Given the same input, CEI always returns the same output

The reference implementation is written in **PHP**, but the logic can be ported 1:1 to:

* JavaScript / TypeScript (web front-end, Node.js)
* Python (data pipelines, AI services)
* Java / Kotlin (Android, server-side)
* C / C++ / Rust / Go (embedded, high-performance back-ends)
* Swift (iOS / macOS apps)

This makes CEI suitable for:

* Mobile weather / health apps
* Web & API back-ends
* IoT / edge devices (e-ink displays, home weather stations)
* Data analytics / AI decision-making engines

---

## 📦 Installation

```bash
git clone https://github.com/Caner-HK/CEI-Comfort-Environment-Index
```

In your PHP project:

```php
require 'cei.php';
```

---

## 🧩 Usage Example

CEI is designed to work naturally with **OpenWeather One Call 3.0** and **Air Pollution API**,
and with other providers via adapter layers (e.g., QWeather).

### Data Sources

| API               | Purpose                                                                        | Docs                                                                                         |
| ----------------- | ------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------- |
| One Call API 3.0  | Real-time weather: temp, humidity, wind, gusts, pressure, UV, weather id, etc. | [https://openweathermap.org/api/one-call-3](https://openweathermap.org/api/one-call-3)       |
| Air Pollution API | Pollutant concentrations: PM2.5, PM10, O₃, CO, NO₂, SO₂                        | [https://openweathermap.org/api/air-pollution](https://openweathermap.org/api/air-pollution) |

You can fetch alerts from OpenWeather, QWeather or other providers, then normalize them using a separate adapter and pass them via the `alerts` field.

### Required Fields (Core)

From `current` in One Call 3.0:

* `temp`
* `humidity`
* `wind_speed`
* `wind_gust` (optional)
* `pressure`
* `uvi`
* `dew_point` (optional)
* `feels_like` (optional, not required by the core model)
* `weather[0].id`

From `list[0].components` in Air Pollution API:

* `pm2_5`
* `pm10`
* `o3`
* `co`
* `no2`
* `so2`

Alerts (optional) – already normalized:

```php
$normalizedAlerts = [
  [
    'event'          => 'Gale Blue Warning',
    'description'    => 'Gale force winds expected in the next 24 hours…',
    'tags'           => [
      'hazard:wind',
      'severity:minor',
      'color:blue',
      'provider:qweather'
    ],
    'severity'       => 'minor',
    'severity_score' => null,
    'code'           => 1006

    'start_ts'       => 1732854000, // Alert start time (Unix Timestamp)
    'end_ts'         => 1732940400, // Alert end time (Unix Timestamp)
  ],
  // ...
];
```

### Example Call

```php
require 'cei.php';

$data = [
    'temp'       => -1.0,
    'humidity'   => 26,
    'wind_speed' => 1.9,
    'wind_gust'  => 2.8,   // optional
    'dew_point'  => -16.0, // optional
    'feels_like' => -3.5,  // optional (doesn't drive the core curve directly)
    'pm2_5'      => 8,
    'pm10'       => 16,
    'o3'         => 40,
    'co'         => 180,
    'no2'        => 12,
    'so2'        => 5,
    'uvi'        => 0.0,
    'pressure'   => 1023,

    'alerts'     => $normalizedAlerts, // may be an empty array if no alerts
    'weather_id' => 800,
];

$unit     = 'metric'; // 'metric' / 'imperial' / 'standard'
$latitude = 36.1;     // used for climate zone and seasonal adjustment
$month    = 11;       // 1–12

$cei = computeCEI($unit, $data, $latitude, $month);

print_r($cei);
```

---

## 🤖 CEI as a Low-Cost AI “Perception Layer”

Most general-purpose AI models can read “temperature: -3°C, wind: 6 m/s, PM2.5: 60”,
but they do not inherently understand what this **feels like** or **how risky it is**.

CEI fills that gap by acting as a **Human-Perception & Risk Layer** for AI:

* Converts raw weather & pollution into structured, human-meaningful features
* Encodes climate context, thermal indices, pollution, risk, and alerts
* Produces a stable 0–100 index plus interpretable sub-scores and flags

### Why CEI is especially useful for small / cheap models

Small models (on mobile, edge, or low-cost servers):

* Cannot perform deep physical & medical reasoning reliably
* But **can** learn to interpret a structured schema like CEI

With CEI, your training task becomes:

```text
Input:   Weather data + CEI structure
Output:  Natural-language advice or explanation
```

The model no longer has to reinvent:

* Climate-zone sensitivity
* Heat index / wind chill logic
* Pollutant health impacts
* Risk interpretation from alerts

Instead, it learns: “Given this CEI structure, what should I tell the user?”

Benefits:

* **Much cheaper training & inference**
* **Higher stability & consistency** across responses
* **Easy to deploy on-device or on low-cost servers**
* Often **better than a large model** that sees only raw weather numbers

---

## 📊 Version History

| Version | Date       | Description                                                                                                                                                                                                                                   |
| ------- | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| v1.0.0  | 2025-11-14 | Initial release: basic CEI with temperature, humidity, wind and simple weighting.                                                                                                                                                             |
| v2.0.0  | 2025-11-17 | Climate- & condition-aware update: added climate zone model, weather-type penalties, wind chill, heat index, dynamic weights and improved air quality scoring.                                                                                |
| v3.0.0  | 2025-11-28 | Risk-aware / extreme conditions update: introduced a separate risk layer (temperature, hazardous weather, official alerts), RiskCap mechanism, structured risk outputs and support for `dew_point`, `wind_gust`, `alerts` and related fields. |
| v3.1.0 | 2025-11-29 | Alerts expiration update: Add `start_ts` / `end_ts` (Unix timestamps, UTC seconds) to unify the alerts structure, support the differentiation of alerts that have not started, are in progress, and have expired in the risk score, and optimize the risk constraints in early warning scenarios. |

> **Current version: v3.1.0 – Alerts expiration update**

---

## 🤝 Contributing

We welcome contributions from developers, researchers, meteorologists and AI practitioners.

### How to contribute

* **Open an Issue**

  * Report bugs
  * Propose improvements to the comfort or risk model
  * Suggest new climate / regional tunings

* **Open a Pull Request (PR)**
  You can contribute:

  * Bug fixes
  * Model improvements (thermal curve, AQ thresholds, risk logic, etc.)
  * New data source adapters (more weather/alert providers)
  * Ports to other languages (JS / Python / Go / Rust / …)
  * Documentation (guides, examples, diagrams)

* **Share Data & Feedback**
  Real-world feedback from different climates / user groups / use-cases is highly valuable
  for future calibration and data-driven CEI evolution.

### Project Vision

Our goal is to make CEI the **most transparent, open and practical environment comfort & risk index** –
a bridge between scientific models and human perception, and a solid foundation for future
intelligent weather assistants and environment-aware AI systems.

---

If you find this project useful, consider giving the repository a ⭐ Star.