<?php

namespace Sernicola\Library\AiFriendly\Service;

defined('_JEXEC') or die;

use Sernicola\Library\AiFriendly\Contract\ContentProviderInterface;
use Sernicola\Library\AiFriendly\Dto\ContentItem;

final class ContentRegistry
{
    /** @param ContentProviderInterface[] $providers */
    public function __construct(private readonly array $providers)
    {
    }

    public function providers(): array
    {
        return $this->providers;
    }

    public function resolve(string $path, ?string $language = null): ?ContentItem
    {
        foreach ($this->providers as $provider) {
            if ($item = $provider->resolve($path, $language)) {
                return $item;
            }
        }

        return null;
    }

    public function findByKey(string $key): ?ContentItem
    {
        $parts = explode(':', $key, 4);
        if (count($parts) !== 4) {
            return null;
        }
        foreach ($this->providers as $provider) {
            if ($provider->name() === $parts[0]) {
                return $provider->find($parts[2], $parts[3]);
            }
        }

        return null;
    }

    /** @return iterable<ContentItem> */
    public function all(array $filters = [], int $limit = 100, int $offset = 0): iterable
    {
        $remainingOffset = $offset;
        $remainingLimit = $limit;
        foreach ($this->providers as $provider) {
            $providerCount = $provider->count($filters);
            if ($remainingOffset >= $providerCount) {
                $remainingOffset -= $providerCount;
                continue;
            }
            foreach ($provider->list($filters, $remainingLimit, $remainingOffset) as $item) {
                yield $item;
                if (--$remainingLimit <= 0) {
                    return;
                }
            }
            $remainingOffset = 0;
        }
    }

    public function count(array $filters = []): int
    {
        return array_sum(array_map(static fn (ContentProviderInterface $provider): int => $provider->count($filters), $this->providers));
    }
}

