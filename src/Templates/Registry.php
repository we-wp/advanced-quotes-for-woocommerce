<?php

namespace WeWP\AdvancedQuotes\Templates;

use DomainException;

/**
 * Free-owned template registry. Add-ons register on the wewp_aq_register_templates action.
 */
final class Registry
{
    public const HOOK = 'wewp_aq_register_templates';

    /** @var array<string, Template> */
    private array $templates = [];

    private bool $sealed = false;

    public function register(Template $template): void
    {
        if ($this->sealed) {
            throw new DomainException('Quote template registration is closed.');
        }
        $id = $template->id();
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $id)) {
            throw new DomainException('Invalid quote template ID: '.$id);
        }
        if (isset($this->templates[$id])) {
            throw new DomainException('Quote template ID is already registered: '.$id);
        }
        $this->templates[$id] = $template;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function get(string $id): ?Template
    {
        return $this->templates[$id] ?? null;
    }

    /** The requested template, or Essential when it is unavailable (for example after Pro is deactivated). */
    public function resolve(string $id): Template
    {
        return $this->templates[$id] ?? $this->templates['essential'];
    }

    /**
     * @return array<string, Template>
     */
    public function all(): array
    {
        return $this->templates;
    }
}
