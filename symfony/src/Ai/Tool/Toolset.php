<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Ai\Guard\ToolEffect;

/**
 * Les outils dont un agent dispose.
 *
 * Trois choses les voulaient sous trois formes différentes : le toolbox une liste de schémas, la
 * garde une map nom → effet, le journal un objet JSON indexé par nom. D'où deux fabriques statiques
 * sur {@see ToolDefinition} et un `effects()` privé dans la fabrique d'agent, qui reconstruisaient
 * chacun la même chose. La collection les remplace : la liste est ici, les projections sont des
 * méthodes.
 *
 * Fabrique de frontière dans les deux sens, comme {@see \App\Ai\Context\Conversation} : le fil est
 * un objet indexé par nom d'outil — c'est la forme qu'attendent les fournisseurs — et il ne
 * traverse pas le code.
 */
final readonly class Toolset implements \Countable, \IteratorAggregate
{
    /** @var list<ToolDefinition> */
    public array $definitions;

    public function __construct(ToolDefinition ...$definitions)
    {
        $this->definitions = array_values($definitions);
    }

    /**
     * La charge du workflow arrive du journal, donc en tableaux.
     *
     * @param array<string, array{description?: string, effect?: string, parameters?: array<string, mixed>|null}> $wire
     */
    public static function fromWire(array $wire): self
    {
        $definitions = [];
        foreach ($wire as $name => $definition) {
            $definitions[] = ToolDefinition::fromWire((string) $name, $definition);
        }

        return new self(...$definitions);
    }

    /**
     * @return array<string, array{description: string, effect: string, parameters: array<string, mixed>|null}>
     */
    public function toWire(): array
    {
        $wire = [];
        foreach ($this->definitions as $definition) {
            $wire[$definition->name] = $definition->toWire();
        }

        return $wire;
    }

    public function with(ToolDefinition ...$more): self
    {
        return new self(...$this->definitions, ...$more);
    }

    /**
     * Ce que fait un outil — la seule question que la garde pose à ce catalogue.
     *
     * Un outil inconnu est externe : le défaut prudent, celui qui demande une validation dans tous
     * les modes sauf `auto`.
     */
    public function effectOf(string $name): ToolEffect
    {
        // ponytail: balayage linéaire. Un agent porte une poignée d'outils ; le jour où il en
        // porte cent, une map construite au constructeur.
        foreach ($this->definitions as $definition) {
            if ($definition->name === $name) {
                return $definition->effect;
            }
        }

        return ToolEffect::External;
    }

    public function count(): int
    {
        return \count($this->definitions);
    }

    /**
     * @return \Traversable<int, ToolDefinition>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->definitions);
    }
}
