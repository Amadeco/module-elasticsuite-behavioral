# Amadeco ElasticSuite Behavioral Analysis

[![Latest Stable Version](https://img.shields.io/github/v/release/Amadeco/module-elasticsuite-behavioral)](https://github.com/Amadeco/module-elasticsuite-behavioral/releases)
[![Magento 2](https://img.shields.io/badge/Magento-2.4.8%2B-brightgreen.svg)](https://business.adobe.com/products/magento/magento-commerce.html)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://www.php.net)
[![License](https://img.shields.io/github/license/Amadeco/module-elasticsuite-behavioral)](LICENSE)

[SPONSOR: Amadeco](https://www.amadeco.fr)

## Overview

**Amadeco ElasticSuite Behavioral** is an advanced algorithmic merchandising engine for Adobe Commerce (Magento 2), designed to automate product ranking based on real user engagement.

Born from the need to manage extensive catalogs where manual sorting is impossible, this module injects a "statistical feedback loop" into your search engine. By analyzing **Impressions, Page Views (CTR proxy), Add-to-Carts, and Sales**, it calculates a granular relevance score for every product using **Bayesian Smoothing** and logarithmic normalization.

Stop relying on static "Best Sellers" lists; start ranking products by their actual attractiveness and performance.

## Features

### 📊 Algorithmic Scoring (Behavioral Intelligence)
Computes a dynamic score (0-100) combining multiple weighted metrics:
- **Attractiveness**: Click-Through Rate (CTR) estimation.
- **Conversion**: Sales quantity and Revenue generated.
- **Intent**: Add-to-Cart events.
- **Social Proof**: Review ratings.

### 📐 Advanced Statistics
- **Bayesian Smoothing**: Prevents statistical noise on low-traffic items. A product with 1 click on 1 view won't artificially outrank a product with 1,000 views and 50 clicks.
- **Logarithmic Normalization**: Dampens the impact of outliers (mega-sellers) to maintain a balanced catalog distribution.

### 📦 Inventory & Lifecycle Awareness
The engine is strictly coupled with your inventory strategy to prevent promoting unavailable items:
- **Out-of-Stock Filtering**: Products that are not salable (MSI compatible) automatically receive a score of 0, pushing them to the bottom of the list.
- **Discontinued Handling**: Specific support for a `discontinued` attribute to bury end-of-life products.
- **"Coming Soon" Strategy**: Option to allow "Freshness Boost" even for Out-of-Stock items, ideal for teasing upcoming collections.

### 📉 & 📈 Dynamic Ranking Policies
- **Bounce Rate Penalty**: Automatically demotes products that generate traffic (high views) but fail to convert (zero sales/carts).
- **Freshness Boost**: New products get a configurable "Boost" period to appear at the top and gather performance data before the algorithm takes over.
- **Discovery Shuffle**: Introduces a controlled randomization factor to prevent the "Echo Chamber" effect (where only top products get seen), allowing mid-tail products to be discovered.

## Metric Definitions & Methodology

To ensure transparency and privacy compliance, this module relies on standard ElasticSuite components:

* **Data Collection Source**: All analytical events (Views, Clicks, Add-to-Carts) are captured using the **Smile_ElasticSuiteTracker** module. This ensures accurate first-party data collection without relying on external trackers (like Google Analytics).
* **"Views" (Impressions)**: Count of times a product was displayed in a Category List or Search Result (sourced from `page.product_list.visible_ids` event).
* **"Clicks" (CTR Numerator)**: Count of confirmed arrivals on the Product Detail Page (sourced from `catalog_product_view` event).

> **⚠️ Methodology Note**: The "Click-Through Rate" calculated by this module is technically an **"Arrival Rate"**. It measures the ratio of *Product Page Views* to *List Impressions*.
> While this serves as a highly effective proxy for attractiveness, it does not measure the raw click event on the `<a>` tag itself. This design choice avoids false positives (accidental clicks) but requires the user to successfully load the product page to count as a "Click".

## Requirements

- **Magento**: 2.4.8 or newer
- **PHP**: 8.3 or newer
- **Smile ElasticSuite**: Core, Catalog, and **Tracker** modules enabled.

## Installation

### Composer Installation

Execute the following commands in your Magento root directory:

```bash
composer require amadeco/module-elasticsuite-behavioral
bin/magento module:enable Amadeco_ElasticSuiteBehavioral
bin/magento setup:upgrade
bin/magento setup:di:compile
```

### Manual Installation

1. Create directory `app/code/Amadeco/ElasticSuiteBehavioral` in your Magento installation.
2. Clone or download this repository into that directory.
3. Enable the module and update the database:

```bash
bin/magento module:enable Amadeco_ElasticSuiteBehavioral
bin/magento setup:upgrade
bin/magento setup:di:compile
```

## Configuration & Usage

### 1. General Configuration

Navigate to **Stores > Configuration > Elasticsuite > Behavioral Merchandising**.

* **Analysis Lookback Period**: Define how many days of history to analyze (Default: 60 days).
* **Weights**: Adjust the relative importance of each metric (Sales, CTR, Revenue, etc.) to fit your business model.
* **Constraints**: Enable `Filter Out-of-Stock Products` or `Filter Discontinued Products` to enforce inventory rules.

### 2. Tuning the Algorithm

* **Freshness Duration**: Number of days a new product receives the "New Product Boost".
* **Bayesian Confidence Threshold**: Minimum number of views required before a product's raw CTR is fully trusted.
* **Discovery Shuffle**: Enable this to periodically rotate products with similar scores.

### 3. Data Processing

The module operates asynchronously to preserve frontend performance.

* **Cron Job**: The scoring calculation runs automatically every night at 03:00 AM (`behavioral_analysis_run`).
* **Manual Trigger**: You can trigger the analysis and index invalidation manually via CLI:

```bash
bin/magento behavioral:calculate
```

### 4. Applying the Boost

Once data is calculated, create or edit an **ElasticSuite Optimizer** in the Magento Admin:

1. Go to **Marketing > ElasticSuite > Optimizers**.
2. Create a new Optimizer.
3. In "Behavioral Algorithm Tuning", enable **Behavioral Scoring**.
4. Adjust the **Global Performance Weight** (Standard: 2.0) and **Freshness Boost Weight** (Standard: 1.5).
5. Apply this Optimizer to your Categories or Search results.

## Technical Architecture

This module follows strict **SOLID principles** and Magento **Service Contracts**.

* **Data Source**: Hybrid fetching from Elasticsearch (Traffic data via **Smile_ElasticSuiteTracker**) and MySQL (Sales/Catalog data).
* **Math Engine**: Implements `Amadeco\ElasticSuiteBehavioral\Model\Calculator\BayesianSmoother` to normalize data distributions.
* **Stock Resolver**: Compatible with both Legacy Inventory and Magento MSI (`Magento_InventorySalesApi`).

## Compatibility

* **Magento Open Source / Adobe Commerce**: 2.4.8+
* **PHP**: 8.3+ (Strict typing used)
* **ElasticSuite**: 2.11+

## Contributing

Contributions are welcome! Please read our [Contributing Guidelines](https://www.google.com/search?q=CONTRIBUTING.md).

## Support

This module is provided "as is" by Amadeco as a contribution to the Open Source community.
For issues or feature requests, please create an issue on our GitHub repository.

## License

This module is licensed under the **OSL-3.0**. See the [LICENSE](https://www.google.com/search?q=LICENSE) file for details.