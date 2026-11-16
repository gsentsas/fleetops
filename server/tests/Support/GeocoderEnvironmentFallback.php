<?php

/**
 * Test-only compatibility for the minimal locked RC1 dependency graph.
 *
 * Laravel's env() delegates to PhpOption and dotenv, which may not be
 * installed when FleetOps is tested independently from the host app.
 * Do not load these fallbacks when the real packages are available.
 */

namespace PhpOption {
    if (!class_exists(Option::class)) {
        abstract class Option
        {
            public static function fromValue($value, $noneValue = null): self
            {
                return $value === $noneValue ? new None() : new Some($value);
            }

            abstract public function map($callback): self;

            abstract public function getOrCall($callback);
        }

        class Some extends Option
        {
            public function __construct(private mixed $value)
            {
            }

            public function map($callback): Option
            {
                return new self($callback($this->value));
            }

            public function getOrCall($callback)
            {
                return $this->value;
            }
        }

        class None extends Option
        {
            public function map($callback): Option
            {
                return $this;
            }

            public function getOrCall($callback)
            {
                return $callback();
            }
        }
    }
}

namespace Dotenv\Repository {
    if (!class_exists(RepositoryBuilder::class)) {
        class RepositoryBuilder
        {
            public static function createWithDefaultAdapters(): self
            {
                return new self();
            }

            public function addAdapter($adapter): self
            {
                return $this;
            }

            public function immutable(): self
            {
                return $this;
            }

            public function make(): object
            {
                return new class {
                    public function get($key)
                    {
                        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

                        return $value === false ? null : $value;
                    }
                };
            }
        }
    }
}
