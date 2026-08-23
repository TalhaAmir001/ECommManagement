<?php

namespace App\Services\Courier;

use App\Models\CourierProvider as CourierProviderModel;
use App\Services\Courier\Providers\LeopardsProvider;
use App\Services\Courier\Providers\ManualProvider;
use App\Services\Courier\Providers\TcsProvider;

/**
 * Resolves a courier_providers row into a fully-configured CourierProvider
 * instance. Built-in providers are registered here; custom ones can be
 * registered at runtime via the boot hook in AppServiceProvider.
 */
class CourierProviderRegistry
{
    /**
     * Built-in driver class names this app ships out of the box.
     *
     * @return list<class-string>
     */
    public function knownDriverClasses(): array
    {
        return [
            ManualProvider::class,
            LeopardsProvider::class,
            TcsProvider::class,
        ];
    }

    /**
     * Resolve a courier_providers row into a CourierProvider.
     */
    public function resolve(CourierProviderModel $row): CourierProvider
    {
        return match ($row->driver_class) {
            ManualProvider::class => new ManualProvider($row),
            LeopardsProvider::class => new LeopardsProvider($row),
            TcsProvider::class => new TcsProvider($row),
            default => $this->resolveCustom($row),
        };
    }

    /**
     * Look up a provider by its DB key (e.g. "leopards"), returning null if
     * it isn't configured.
     */
    public function findByKey(string $key): ?CourierProvider
    {
        $row = CourierProviderModel::query()->where('key', $key)->first();
        if ($row === null) {
            return null;
        }

        return $this->resolve($row);
    }

    private function resolveCustom(CourierProviderModel $row): CourierProvider
    {
        $class = $row->driver_class;
        if (! class_exists($class)) {
            throw new \RuntimeException("Courier driver class [{$class}] does not exist.");
        }
        $instance = new $class($row);
        if (! $instance instanceof CourierProvider) {
            throw new \RuntimeException("Courier driver [{$class}] must implement CourierProvider.");
        }

        return $instance;
    }
}
