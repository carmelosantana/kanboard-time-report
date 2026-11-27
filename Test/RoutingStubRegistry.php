<?php

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/** A ProviderRegistry stand-in: records every resolve() call and returns a canned profile id. */
class RoutingStubRegistry extends ProviderRegistry
{
    /** @var list<array{0:string,1:?int,2:?string}> */
    public array $resolves = [];
    public string $returns = 'routed';

    public function resolve(string $feature, ?int $projectId = null, ?string $pick = null): string
    {
        $this->resolves[] = [$feature, $projectId, $pick];
        return $this->returns;
    }

    public function pickerOptions(string $feature, ?int $projectId = null): array
    {
        return ['show' => true, 'profiles' => [['id' => 'routed', 'label' => 'Routed', 'provider' => 'x', 'model' => 'y']], 'selected' => 'routed'];
    }
}
