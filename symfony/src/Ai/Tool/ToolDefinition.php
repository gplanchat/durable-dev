<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Ai\Guard\ToolEffect;

/**
 * Ce qu'un outil déclare : son schéma pour le modèle, et son effet pour la garde.
 *
 * Le schéma JSON (`parameters`) reste un tableau : c'est du fil, il part tel quel au fournisseur.
 * Le reste est typé — un `effect` mal orthographié doit lever ici, pas devenir silencieusement
 * « externe » au fond de la garde.
 */
final readonly class ToolDefinition
{
    /**
     * @param array<string, mixed>|null $parameters schéma JSON, tel qu'il part au fournisseur
     */
    public function __construct(
        public string $name,
        public string $description,
        public ToolEffect $effect = ToolEffect::External,
        public ?array $parameters = null,
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('Un outil doit avoir un nom.');
        }
    }

    /**
     * Fabrique de frontière : la charge du workflow arrive du journal, donc en tableaux.
     *
     * Un outil qui ne déclare pas son effet est traité comme externe — le défaut prudent, celui qui
     * demande une validation dans tous les modes sauf `auto`.
     *
     * @param array{description?: string, effect?: string, parameters?: array<string, mixed>|null} $wire
     */
    public static function fromWire(string $name, array $wire): self
    {
        return new self(
            $name,
            (string) ($wire['description'] ?? ''),
            ToolEffect::tryFrom((string) ($wire['effect'] ?? '')) ?? ToolEffect::External,
            $wire['parameters'] ?? null,
        );
    }

    /**
     * Retour vers le fil : la charge de démarrage du workflow part en JSON, indexée par nom
     * d'outil — c'est {@see Toolset::toWire()} qui pose la clé.
     *
     * @return array{description: string, effect: string, parameters: array<string, mixed>|null}
     */
    public function toWire(): array
    {
        return [
            'description' => $this->description,
            'effect' => $this->effect->value,
            'parameters' => $this->parameters,
        ];
    }
}
