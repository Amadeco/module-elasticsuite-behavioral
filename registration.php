<?php
/**
 * ElasticSuite Behavioral Analysis Module
 *
 * @category  Amadeco
 * @package   Amadeco_ElasticSuiteBehavioral
 * @copyright Copyright (c) 2026 Amadeco
 * @license   Open Software License (OSL 3.0)
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Amadeco_ElasticSuiteBehavioral',
    __DIR__
);