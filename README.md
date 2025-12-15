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

CWC CEI (Comfort Environment Index) is an intelligent algorithm that converts  
**weather, air quality and risk information** into a unified **0–100 environment score**.

The goal is to turn weather apps from “just showing numbers” into a true  
**environment perception layer that understands human comfort and safety risk** –  
combining thermal comfort, air quality, UV, pressure and extreme risk in a single,  
explainable and machine-friendly framework.

---

## 🚀 Core Design Overview

### ✔ Two-Layer Architecture: Comfort Layer + Risk Cap Layer

Since **v3.0.0**, CEI has been clearly separated into two independent yet composable layers:

1. **Comfort Layer**  
   Measures “how comfortable the environment feels under current conditions,” combining:
   - Thermal comfort (temperature, humidity, wind, perceived temperature)
   - Air comfort (multi-pollutant air quality)
   - UV comfort (UVI)
   - Pressure comfort (pressure)

   Outputs a **0–100 Comfort CEI (`comfort_cei`)**.

2. **Risk Layer** (v3.2.0 update)  
   Measures “how dangerous the environment is,” and outputs **RiskCap** (a cap) to constrain the final CEI.
   This layer adopts **Hazard Bucket Fusion**:

   - Risks are bucketed by hazards (e.g. `extreme_cold`, `snow_ice`, `wind`, `heavy_rain`, `thunderstorm`, `air_quality`, …)
   - For each hazard, it computes:
     - **P (Physical)**: physical risk inferred from observations/feels-like signals (Heat Index, Wind Chill, `weather_id`, gusts, etc.)
     - **A (Alert)**: alert intensity inferred from official alerts (hazard + severity + time phase)
     - **Q (Quality)**: alert credibility / hit confidence (certainty / urgency / area / time phase)
   - Final fused intensity **R**: alerts only “fill the gap” to avoid double-penalizing the same source:
     > **R = P + Q · max(0, A − P)**

   It also outputs:
   - `risk` (overall): overall risk intensity (0=no risk, 100=extreme risk)
   - `risk_cap`: the actual cap used for limiting CEI (0–100, **lower means more dangerous**)
   - `risk_hint`: a more sensitive hint score (leans toward `max(P, A)`; good for “warn but not necessarily cap”)
   - `risk_focus`: a focus score (higher when P and A align with high Q; good for “highlight/attention”)

Final CEI is defined as:

> **CEI = min(Comfort CEI, RiskCap)**

Both layers are explicitly exposed for frontend and AI usage:

- `cei` – final 0–100 index  
- `components.risk` – overall risk intensity (0–100)  
- `detail.comfort_cei` – comfort-only CEI  
- `detail.risk_cap` – risk cap applied to final CEI  
- `detail.risk_hint` / `detail.risk_focus` – hint/focus signals  
- `detail.risk.hazards` – hazard bucket details (explainable/visualizable)

---

## 🌍 Climate Zone & Seasonal Model (Climate Context)

CEI assumes that **people’s comfort range for temperature differs by latitude and season**.

### ✔ Climate Zones & Comfort Temperature

The algorithm uses the **absolute latitude (`abs(latitude)`)** to divide the world into climate zones,  
for example:

- `equatorial`
- `tropical`
- `subtropical`
- `temperate`
- `cold_temperate`
- `polar`

Each zone is assigned a baseline **comfort temperature `comfortTemp` (°C)** –  
higher near the equator, lower near the poles – so that:

> The same 15°C in Tokyo and in Northern Europe will not be judged identically;  
> CEI reflects such differences in its score.

### ✔ Southern Hemisphere Seasonal Mapping

From **v3.1.1**, CEI introduces a seasonal mapping:

- Input month is the natural month `month` (1–12).
- For the Southern Hemisphere, we apply a **6-month shift** to get the *local* month `monthNorm`:

  - Northern Hemisphere: `monthNorm = month`
  - Southern Hemisphere: `monthNorm = ((month + 5) % 12) + 1`

This makes:

- June–August summer in the Northern Hemisphere, and  
- December–February the mirrored summer in the Southern Hemisphere.

All seasonal logic and comfort temperature adjustments are based on this **local month**,
so cities like Brisbane are no longer misclassified as “winter using summer logic”.

On top of this, CEI applies light corrections to `comfortTemp`:

- Local summer (Jun–Aug) → slightly increases `comfortTemp`
- Local winter (Dec–Feb) → slightly decreases `comfortTemp`

Climate context is returned in a structured way:

```php
'detail' => [
  'climate' => [
    'zone'        => string, // e.g. 'temperate'
    'factor'      => float,  // seasonal scaling factor
    'comfortTemp' => float   // comfort temperature baseline under current local season
  ],
  // ...
]
````

---

## 🌡 Thermal Perception Model

Air temperature alone is not enough to describe how it feels.
CEI models thermal perception using **Heat Index + Wind Chill + dew point + wind field**.

### ✔ Heat Index (Hot-Side)

In warm or hot conditions CEI uses **Heat Index**:

* Determined by temperature + humidity
* Captures “mugginess” and heat stress risk
* High temperature + high humidity → feels much hotter than the air temperature

### ✔ Wind Chill (Cold-Side)

Under **cold + windy** conditions, wind chill makes it feel much colder than the air temperature.
CEI uses the standard North American / Canadian wind chill formula.

### ✔ Automatic Model Switching & Extension Factors

* **T ≥ 20°C** → Heat Index is used as the primary effective temperature
* **T < 20°C** → Wind Chill is used as the primary effective temperature

Optional extension fields:

* `dew_point`: when dew point ≥ 24–26°C and air temperature is high,
  CEI applies extra penalties for oppressive humidity.
* `wind_gust`: refines wind chill perception and feeds the risk layer’s strong-wind logic.
* `feels_like`: can be used for display or debugging, but does not drive the core curve directly.

Thermal details in the output:

```php
'detail' => [
  'thermal' => [
    'effective_temp' => float, // chosen Heat Index / Wind Chill as effective temp
    'heat_index'     => float,
    'wind_chill'     => float
  ],
  // ...
]
```

---

## ⚖️ Dynamic Weight Adjustment

Different factors matter in different situations.
CEI allocates dynamic weights to the four comfort components:

* Thermal comfort: `heat`
* Air comfort: `air`
* UV: `uv`
* Pressure: `pressure`

Weights are adjusted according to real-time conditions, for example:

* Extreme cold / heat → increase `heat` weight
* Elevated PM2.5 → increase `air` weight
* UVI > 8 → increase `uv` weight
* Strong winds → increase `heat` weight (wind chill significantly impacts perception)

All weights are normalized to sum to 1, and returned as:

```php
'weights' => [
  'heat'     => float,
  'air'      => float,
  'uv'       => float,
  'pressure' => float
]
```

---

## 🌦 Weather Condition Penalty

At the same temperature, “thunderstorm + strong wind + sleet” obviously feels worse than clear sky.
CEI uses **OpenWeather `weather.id`** to apply comfort penalties to the thermal component:

* **Thunderstorms (2xx)** – medium to strong penalties for uncomfortable and potentially dangerous conditions
* **Drizzle (3xx)** – mild cool/damp discomfort penalties
* **Rain (5xx)** – different penalties for light / moderate / heavy / freezing rain
* **Snow (6xx)** – graded by intensity and type (snow, sleet, mixed rain & snow)
* **Atmospheric (7xx: mist, smoke, haze, dust, sand, volcanic ash, squall, etc.)**
* **Clear (800)** – no penalty
* **Clouds (801–804)** – from almost no penalty (few clouds) to light penalties (overcast)

These penalties **only affect the thermal comfort component**,
and do not modify the air quality score directly.

For more dangerous phenomena (e.g. severe thunderstorms, tornadoes, dust storms),
the **risk layer** performs extra safety risk evaluation on top.

---

## 🫧 International Air Quality Component

CEI scores six major pollutants individually and then takes the worst one as the air comfort score:

* PM2.5
* PM10
* O₃
* CO (internally converted from μg/m³ to mg/m³)
* NO₂
* SO₂

Each pollutant score uses threshold-based mapping to 0–100. The final air comfort score is:

> **AirScore = min(score_PM2.5, score_PM10, score_O3, score_CO, score_NO2, score_SO2)**

This “weakest-link” design reflects real-world health exposure:

**If any single pollutant is bad enough, overall air comfort should drop.**

---

## ⚠️ Risk & Alerts

The Risk Layer measures “how dangerous the environment is” and outputs **RiskCap** to constrain the final CEI, preventing inflated safety impressions when conditions feel fine but objective hazards exist.  
In **v3.2.0**, CEI introduces **Hazard Bucket Fusion**: risks are bucketed by hazard and fused per bucket using both **Physical signals** and **Official alerts**, reducing double-penalization and improving explainability and UI usability.

---

### ✔ Three Risk Sources (kept conceptually, fused into hazard buckets)

You can still think of the Risk Layer as three sources, but they ultimately map into the same hazard bucket structure (see `hazards` below):

1. **Temperature Risk → part of Physical (P)**  
   Evaluated from Heat Index, Wind Chill, Dew Point, etc. to reflect heat illness, frostbite, hypothermia, and related risks. It may emit internal markers (examples):
   - `temp_extreme_cold_35`
   - `temp_heat_35`
   - `temp_use_gust` / `heat_dp_boost` (debug/explain)

   At the hazard level, it mainly contributes to:
   - `extreme_cold`
   - `extreme_heat`

2. **Weather Phenomena Risk → part of Physical (P)**  
   Identified from `weather_id` and `wind_gust` (fallback to `wind_speed`) for hazardous conditions such as:
   - severe thunderstorms, heavy rain, blizzards, freezing rain
   - fog, dust/sand storms, tornadoes
   - strong gusts / wind

   Example internal markers:
   - `wx_thunderstorm_heavy`, `wx_freezing_rain`, `wx_dust_sand_squall`, `wx_tornado`
   - `wind_from_gust_or_wind`

   At the hazard level, it mainly contributes to:
   - `thunderstorm` / `heavy_rain` / `snow_ice` / `fog` / `dust_sand` / `tornado` / `wind`, etc.

3. **Official Alerts Risk → Alert (A) and Quality (Q)**  
   Alerts from OpenWeather / QWeather (and others) are normalized by an adapter into a standard structure (example):

   ```php
   [
     'event'          => 'Wind Alert (Blue)',
     'description'    => 'Strong winds expected within the next 24 hours...',
     'tags'           => [
       'hazard:wind',
       'severity:minor',
       'certainty:likely',
       'urgency:expected',
       'area:city',
       'provider:qweather'
     ],
     'severity'       => 'minor',
     'severity_score' => null, // optional: continuous 0–1 severity (future model)
     'code'           => 1006,
     'start_ts'       => 1732854000, // UTC seconds
     'end_ts'         => 1732940400
   ]
   ```

   * **A (Alert intensity)** = hazard base × severity × time phase
   * **Q (Credibility / hit confidence)** is derived from `certainty / urgency / area` and time phase
Alerts are bucketed into `A[hazard]` and `Q[hazard]` for fusion.

---

### ✔ Hazard Bucket Fusion (v3.2.0 core): P / A / Q → R

For each hazard bucket, the risk layer keeps:
- **P (Physical)**: physical risk inferred from observations / feels-like signals
- **A (Alert)**: official alert intensity
- **Q (Quality)**: alert credibility / hit confidence

The fused intensity **R (Fusion Risk)** is:

> **R = P + Q · max(0, A − P)**

Interpretation:
- When **physical risk is already high (P high)**, alerts won’t “penalize again” (avoids double counting)
- When **alert intensity exceeds physical evidence (A > P)**, alerts only fill the gap, scaled by **Q**
- It also outputs **Focus**: more “attention-oriented”; higher when P and A align under high Q

---

### ✔ Alert Timing + Forecast Evaluation Timestamp (ts)

To support forecast CEI evaluation “at a future moment,” the risk layer uses an **evaluation timestamp `ts`**:
- `data['ts']` (UTC seconds) is supported as the evaluation time (recommended for forecasts)
- If `ts` is not provided, it falls back to `time()`

Alerts are categorized into phases:
- **lead (not started yet)**: decayed as an early reminder
- **active (in effect)**: fully applied
- **past (expired)**: zeroed or heavily decayed

All timing uses **Unix timestamps (UTC seconds)** to avoid timezone ambiguity.

---

### ✔ Risk Output Structure (UI- and explanation-friendly)

The risk layer outputs not only an overall risk score, but also three user-facing signals and detailed hazard buckets:

```php
'components' => [
  'heat'     => int,
  'air'      => int,
  'uv'       => int,
  'pressure' => int,
  'risk'     => int  // overall risk intensity 0–100
],

'detail' => [
  'risk_cap'   => float, // cap 0–100, lower means more dangerous (NOT simply 100-risk)
  'risk_hint'  => int,   // hint score (more sensitive; tends to max(P, A))
  'risk_focus' => int,   // focus score (good for UI highlight)

  'risk' => [
    'overall' => int,     // same as components.risk
    'cap'     => float,   // same as detail.risk_cap
    'hint'    => int,     // same as detail.risk_hint
    'focus'   => int,     // same as detail.risk_focus

    'from_temp'    => int,
    'from_weather' => int,
    'from_alerts'  => int,

    'factors'     => string[],  // suggested user-facing hazards (max 8)
    'debug_flags' => string[],  // internal debug flags (not recommended for UI)

    'hazards' => [
      'wind' => [
        'P' => 0.000, 'A' => 0.650, 'Q' => 0.720, 'R' => 0.468, 'Focus' => 0.650,
        'source' => ['physical' => false, 'alert' => true]
      ],
      // ...
    ]
  ]
]
```

> Intuition:
>
> * `risk_cap` for capping (limits the final CEI)
> * `risk_hint` for warnings (more sensitive; not always capping)
> * `risk_focus` for highlighting (higher when P and A align with high Q)
> * `hazards` for explainability and visualization

---

## 📤 Unified, Structured Output

A typical `computeCEI()` call returns a structure like:

```php
[
  'cei'   => int,    // 0–100 Final Index
  'level' => string,

  'components' => [
    'heat'     => int,
    'air'      => int,
    'uv'       => int,
    'pressure' => int,
    'risk'     => int  // Risk intensity 0–100
  ],

  'weights' => [
    'heat'     => float,
    'air'      => float,
    'uv'       => float,
    'pressure' => float
  ],

  'detail' => [
    'comfort_cei' => float,
    'risk_cap'    => float, // Cap pressure limit (0–100, lower values ​​indicate higher risk)
    'risk_hint'   => int,   // Risk warning score (more sensitive)
    'risk_focus'  => int,   // Risk Awareness
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
      'overall'      => int,    // same as components.risk
      'cap'          => float,  // same as detail.risk_cap
      'hint'         => int,    // same as detail.risk_hint
      'focus'        => int,    // same as detail.risk_focus

      // Compatible fields (for the convenience of continued use by the old UI/statistics)
      'from_temp'    => int,
      'from_weather' => int,
      'from_alerts'  => int,

      // User-readable factors (recommended for display)
      'factors'      => string[],

      // Internal debugging (not recommended for display as a factor)
      'debug_flags'  => string[],

      // Hazard binning details (explainable/visualizable)
      'hazards'      => array
    ]
  ]
]
```

Properties:

* **UI-friendly** – ideal for color bars, gauges, cards, timelines
* **AI-friendly** – all key semantics are structured; models do not need to redo physics/medical reasoning
* **Machine-friendly** – easy to log, aggregate, and feed into rule engines

---

## 👨‍💻 Language- & Platform-Agnostic Core

CEI is implemented as a **pure computation core**, independent of frameworks and I/O,
so it can be **ported losslessly** across languages with consistent logic and precision.

* Current reference implementation: **PHP**
* Easily portable to:

  * **JavaScript / TypeScript** (web front-end, Node.js back-end)
  * **Python** (data pipelines, AI services)
  * **Java / Kotlin** (Android, server applications)
  * **C / C++ / Rust / Go** (embedded, high-performance back-ends)
  * **Swift** (iOS / macOS apps)

Core properties:

* The CEI core function is a **pure function**:

  * No network requests inside
  * No database or file I/O
  * All inputs are explicit (weather + air quality + context + alerts)
  * Same input always yields the same output (ideal for testing and replay)

This makes CEI suitable for:

* Mobile apps (weather, health)
* Web front-ends and back-end APIs (SaaS, open platforms)
* IoT / edge devices (weather stations, e-ink panels)
* Data analytics and AI decision engines (travel advice, health risk assessment)

### ✔ Future Plan: C Rewrite & SWIG Cross-Platform Adapters

Once the CEI algorithm stabilizes, the core computation will be **rewritten in C** and wrapped via **SWIG (Simplified Wrapper and Interface Generator)** to provide native bindings:

* **Performance gains** – C delivers higher throughput, ideal for embedded devices and high-frequency back-end calls
* **Write once, reuse everywhere** – SWIG adapters expose the same C core to Python, JavaScript, Java, Ruby, C#, Go, and more
* **Native speed + ecosystem ergonomics** – platforms call the same optimized C library while retaining each language’s tooling and developer workflow

This roadmap turns CEI into a truly **cross-platform environment intelligence standard library**, preserving algorithmic consistency while delivering optimal performance across stacks.

---

## 🧩 Integration & Example

### ✔ Installation

```bash
git clone https://github.com/Caner-HK/CEI-Comfort-Environment-Index
```

In your PHP project:

```php
require 'cei.php';
```

### 🌤 Fetching Weather Data via OpenWeather

The CEI reference implementation is designed around
**OpenWeather One Call 3.0 API** and **Air Pollution API**
(and also supports other providers such as QWeather via adapter layers).

#### 1️⃣ Prepare an OpenWeather API Key

1. Sign up for an OpenWeather account:
   [https://home.openweathermap.org/users/sign_up](https://home.openweathermap.org/users/sign_up)
2. Create and view your API keys:
   [https://home.openweathermap.org/api_keys](https://home.openweathermap.org/api_keys)

#### 2️⃣ APIs Used

| API                   | Purpose                                                                                          | Docs                                                                                         |
| --------------------- | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------- |
| **One Call API 3.0**  | Real-time weather: temperature, humidity, wind, gusts, dew point, UV, pressure, weather id, etc. | [https://openweathermap.org/api/one-call-3](https://openweathermap.org/api/one-call-3)       |
| **Air Pollution API** | Air quality: PM2.5, PM10, O₃, CO, NO₂, SO₂ concentrations                                        | [https://openweathermap.org/api/air-pollution](https://openweathermap.org/api/air-pollution) |

#### 3️⃣ Required Fields for CEI

**From `current` in One Call 3.0:**

* `temp`
* `humidity`
* `wind_speed`
* `wind_gust` (optional)
* `pressure`
* `uvi`
* `dew_point` (optional)
* `feels_like` (optional, for display/debug)
* `weather[0].id` → mapped to `weather_id`

**From `list[0].components` in Air Pollution API:**

* `pm2_5`
* `pm10`
* `o3`
* `co`
* `no2`
* `so2`

**CEI accepts weather alerts as an optional input.**

One Call API 3.0 exposes governmental alerts via `alerts`.
You can feed these into a model or mapping layer and produce the CEI alert structure:

* `event` – alert title
* `description` – alert body text
* `tags` – array of semantic tags (e.g. hazards, provider)
* `severity` – text severity label
* `severity_score` – numeric severity from a model (0–1)
* `code` – provider’s internal code
* `start_ts` / `end_ts` – alert validity window (Unix timestamps, UTC seconds)

At CEI call time, you only need to pass a `$data` array containing these fields.

### 💻 Example Call

```php
require 'cei.php';

// 1. Extract and normalize data from One Call + Air Pollution
$data = [
    // ——— Real-time weather (from One Call API 3.0: current) ———
    'temp'       => -1.0,
    'humidity'   => 26,
    'wind_speed' => 1.9,
    'wind_gust'  => 2.8,   // optional
    'dew_point'  => -16.0, // optional
    'feels_like' => -3.5,  // optional
    'uvi'        => 0.0,
    'pressure'   => 1023,
    'weather_id' => 800,   // from current.weather[0].id

    // ——— Air quality (from Air Pollution API: list[0].components) ———
    'pm2_5'      => 8,
    'pm10'       => 16,
    'o3'         => 40,
    'co'         => 180,
    'no2'        => 12,
    'so2'        => 5,

    // ——— Alerts (optional, normalized by an adapter layer) ———
    'alerts'     => $normalizedAlerts ?? [],
];

// 2. Units and spatiotemporal context
$unit     = 'metric'; // 'metric' / 'imperial' / 'standard'
$latitude = 36.1;     // used for climate zone & seasonal adjustment
$month    = 11;       // current month (1–12)

// 3. Call CEI core function
$cei = computeCEI($unit, $data, $latitude, $month);

print_r($cei);
```

---

## 🧠 CEI as a Low-Cost AI “Environment Perception Layer”

Large models can read numbers like “temperature −3°C, wind 6 m/s, PM2.5 60”,
but small models often struggle to reliably infer:

* What this really *feels like* to a person
* Whether it implies meaningful health or safety risk
* How to interpret the same conditions across different climate zones

CEI provides a structured **Comfort & Risk Semantic Layer**
that lets even small models generate high-quality explanations and advice.

### ✔ Typical Training Pattern

```text
Input:  Raw weather data + computed CEI structure
Output: Natural-language explanation/advice for the user
```

The model learns that:

* Lower CEI → less suitable for outdoor activities
* Wind chill / heat index / high dew point → emphasize cold/heat and hydration advice
* Low air component → recommend masks / avoid intense exercise
* `risk.flags` containing avalanche / flash-flood / typhoon → highlight severe safety risks

The model does **not** need to re-implement meteorology and medical models.

Benefits:

* Greatly reduced training and inference cost
* Stable, consistent outputs – less likely to “hallucinate risk”
* Deployable on phones, edge devices and low-spec servers
* In many business scenarios, **small model + CEI** outperforms
  **large model + raw weather data** in stability and controllability

---

## 📊 Version History

| Version | Date       | Description                                                                                                                                                                                                                                                                                                |
| ------- | ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| v1.0.0  | 2025-11-14 | Initial release: basic CEI computation with temperature, humidity, wind and a simple weighting scheme.                                                                                                                                                                                                     |
| v2.0.0  | 2025-11-17 | Climate & condition-aware update: added climate zone model, weather-type discomfort penalties, wind chill, heat index, dynamic weights and more refined air quality scoring, all mapped to a unified 0–100 scale.                                                                                          |
| v3.0.0  | 2025-11-28 | Risk-aware / extreme-conditions update: introduced a separate risk layer (extreme temperatures, hazardous weather, official alerts), a RiskCap mechanism, structured risk breakdown outputs, and support for `dew_point`, `wind_gust`, `alerts` and related fields.                                        |
| v3.1.0  | 2025-11-29 | Alert timing update: added `start_ts` / `end_ts` (Unix timestamps, UTC seconds) to the unified alerts structure; differentiated “not started / active / expired” alerts in the risk score; improved risk constraints in early-warning scenarios to avoid over-penalizing CEI before events actually begin. |
| v3.1.1  | 2025-12-02 | Southern Hemisphere climate fix: redefined climate zones based on absolute latitude and introduced Southern Hemisphere seasonal mapping (`monthNorm`), correcting summer/winter classification and comfort temperature for Southern Hemisphere cities, improving global behavior over cold/warm regions.   |
| v3.2.0  | 2025-12-14 | Risk layer upgrade: Hazard Bucket Fusion (P/A/Q/R) to avoid double-penalization by fusing alerts with physical signals; added `ts` for forecast-time evaluation; added `risk_hint` and `risk_focus`; exposed hazard bucket details (`hazards`) and a user-facing `factors` list. |

> **Current version: v3.2.0 – Risk Layer Upgrade**

---

## 🤝 Contributing

Developers, researchers, meteorologists and AI practitioners are all welcome to contribute to CEI.

### ✔ How You Can Help

* **Open Issues**

  * Report bugs
  * Propose model improvements (thermal curve, AQ thresholds, risk logic, weighting)
  * Suggest new climate zones, city/local tuning or additional alert sources

* **Open Pull Requests (PRs)**

  * Bug fixes
  * Model enhancements (comfort, air quality, risk evaluation)
  * New data source adapters (more national/regional weather & alert platforms)
  * New language ports (JS / Python / Go / Rust / …)
  * Documentation & examples (integration guides, use cases, diagrams)

* **Share Data & Feedback**

  * Real-world perception & feedback from different climates, user groups and scenarios
  * These will inform future calibration and data-driven evolution of CEI

### ✔ Project Vision

Our goal is to make CEI an **open, transparent, verifiable and extensible standard**
for environment comfort & risk:

A robust bridge between scientific models and human perception,
and a foundational building block for the next generation of intelligent weather assistants
and environment-aware AI systems.

---

If you find this project useful, please consider giving the repository a ⭐ Star.

