<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * AiGate — single source of truth for "is the AI narrative summary available?".
 *
 * Gate = PHP >= 8.4 AND AiConnector present (with its AiQuota spend guard,
 * 1.3.0+) AND the 1.4.0 routing API (ProviderRegistry::resolve()) AND ProviderRegistry::isReady(). An older AiConnector keeps AI off.
 * Consulted identically by Plugin::initialize() (AI toggle) and
 * TimeReportController::isAiEnabled() (route guards) so they never diverge.
 *
 * class_exists(ProviderRegistry) is safe at initialize() time — it loads the
 * class file but not any provider SDK; isReady() touches no provider class.
 */
class AiGate
{
    public static function isReady($container, ?int $phpVersionId = null, ?bool $connectorPresent = null): bool
    {
        $versionId = $phpVersionId ?? PHP_VERSION_ID;
        if ($versionId < 80400) {
            return false;
        }

        $present = $connectorPresent
            ?? (class_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry::class)
                && class_exists(\Kanboard\Plugin\AiConnector\Model\AiQuota::class)
                && method_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry::class, 'resolve'));
        if (! $present) {
            return false;
        }

        return (new ProviderRegistry($container))->isReady();
    }
}
