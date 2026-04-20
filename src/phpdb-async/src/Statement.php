<?php

declare(strict_types=1);

namespace PhpDb\Async;

use Async\Pool;
use Override;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Adapter\Driver\StatementInterface;
use PhpDb\Adapter\ParameterContainer;

/**
 * Wraps a StatementInterface that was prepared on a pooled connection.
 *
 * The pool slot (AdapterInterface) is held exclusively until execute() returns,
 * after which it is released back to the pool. If the statement is garbage
 * collected without being executed, __destruct() performs the release.
 */
final class Statement implements StatementInterface
{
    private bool $released = false;

    public function __construct(
        private readonly StatementInterface $inner,
        private readonly Pool $pool,
        private readonly AdapterInterface $acquiredAdapter,
    ) {}

    #[Override]
    public function execute(ParameterContainer|array|null $parameters = null): ?ResultInterface
    {
        try {
            return $this->inner->execute($parameters);
        } finally {
            $this->release();
        }
    }

    #[Override]
    public function prepare(?string $sql = null): static
    {
        $this->inner->prepare($sql);
        return $this;
    }

    #[Override]
    public function isPrepared(): bool
    {
        return $this->inner->isPrepared();
    }

    #[Override]
    public function getResource(): mixed
    {
        return $this->inner->getResource();
    }

    #[Override]
    public function setSql(?string $sql): static
    {
        $this->inner->setSql($sql);
        return $this;
    }

    #[Override]
    public function getSql(): ?string
    {
        return $this->inner->getSql();
    }

    #[Override]
    public function setParameterContainer(ParameterContainer $parameterContainer): static
    {
        $this->inner->setParameterContainer($parameterContainer);
        return $this;
    }

    #[Override]
    public function getParameterContainer(): ?ParameterContainer
    {
        return $this->inner->getParameterContainer();
    }

    public function __destruct()
    {
        $this->release();
    }

    private function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $this->pool->release($this->acquiredAdapter);
    }
}
