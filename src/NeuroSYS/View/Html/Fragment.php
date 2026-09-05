<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The Fragment class. Several nodes with no element around them.
 *
 * What a list of cards is, and what a view's content is when the page has more than one top-level
 * section. Renders its children one per line at its own depth, so a fragment placed inside an
 * {@link Element} indents like any other child.
 */
final readonly class Fragment implements Node
{
    /** @var list<Node> */
    private array $nodes;

    /**
     * Constructs an instance of {@link self} from the given nodes, in order.
     *
     * @param Node ...$nodes
     */
    public function __construct(Node ...$nodes)
    {
        $this->nodes = array_values($nodes);
    }

    /**
     * Builds a fragment by mapping $items through $node.
     *
     * @template T
     * @param iterable<T>          $items
     * @param callable(T): Node    $node
     * @return self
     */
    public static function each(iterable $items, callable $node): self
    {
        $nodes = [];

        foreach ($items as $item) {
            $nodes[] = $node($item);
        }

        return new self(...$nodes);
    }

    /**
     * @param int $depth
     * @return string
     */
    public function render(int $depth = 0): string
    {
        return implode(
            "\n" . str_repeat('  ', $depth),
            array_map(static fn(Node $node): string => $node->render($depth), $this->nodes),
        );
    }
}
