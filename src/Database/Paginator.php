<?php

declare(strict_types=1);

namespace Lift\Database;

use JsonSerializable;

/**
 * Paginated result set returned by {@see QueryBuilder::paginate()}.
 *
 * Implements `JsonSerializable` so it can be returned directly from a route
 * handler and will be encoded as:
 *
 * ```json
 * {
 *   "data":         [...],
 *   "total":        100,
 *   "per_page":     15,
 *   "current_page": 2,
 *   "last_page":    7,
 *   "from":         16,
 *   "to":           30
 * }
 * ```
 *
 * HTML navigation links are available via {@see links()}.
 */
final class Paginator implements JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $items       Rows for the current page.
     * @param int                        $total       Total number of matching rows.
     * @param int                        $perPage     Rows per page.
     * @param int                        $currentPage Current page number (1-based).
     * @param string                     $path        Base URL used by {@see links()} — e.g. `/posts`.
     */
    public function __construct(
        private readonly array  $items,
        private readonly int    $total,
        private readonly int    $perPage,
        private readonly int    $currentPage,
        private readonly string $path = '',
    ) {}

    // -----------------------------------------------------------------
    // Data accessors
    // -----------------------------------------------------------------

    /**
     * Return the rows for the current page.
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    /** Return the total number of matching rows across all pages. */
    public function total(): int
    {
        return $this->total;
    }

    /** Return the number of rows per page. */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /** Return the current page number (1-based). */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /** Return the last page number. */
    public function lastPage(): int
    {
        return (int) ceil($this->total / max(1, $this->perPage));
    }

    /** Return the 1-based row number of the first item on this page. */
    public function from(): int
    {
        return $this->total > 0 ? ($this->currentPage - 1) * $this->perPage + 1 : 0;
    }

    /** Return the 1-based row number of the last item on this page. */
    public function to(): int
    {
        return min($this->currentPage * $this->perPage, $this->total);
    }

    /** Return `true` when a next page exists. */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    /** Return `true` when a previous page exists. */
    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    // -----------------------------------------------------------------
    // HTML links
    // -----------------------------------------------------------------

    /**
     * Render a minimal HTML pagination bar.
     *
     * Produces `«Prev | 1 2 … n | Next»` links where each page number links
     * to `{path}?page={n}`. The current page is rendered as a `<strong>` tag.
     *
     * Returns an empty string when there is only one page.
     */
    public function links(?string $path = null): string
    {
        $last = $this->lastPage();
        if ($last <= 1) {
            return '';
        }

        $base = $path ?? $this->path;
        $parts = [];

        // « Prev
        if ($this->currentPage > 1) {
            $parts[] = '<a href="' . $this->pageUrl($base, $this->currentPage - 1) . '">&laquo; Prev</a>';
        }

        // Page numbers
        for ($i = 1; $i <= $last; $i++) {
            if ($i === $this->currentPage) {
                $parts[] = '<strong>' . $i . '</strong>';
            } else {
                $parts[] = '<a href="' . $this->pageUrl($base, $i) . '">' . $i . '</a>';
            }
        }

        // Next »
        if ($this->currentPage < $last) {
            $parts[] = '<a href="' . $this->pageUrl($base, $this->currentPage + 1) . '">Next &raquo;</a>';
        }

        return '<nav class="pagination">' . implode(' ', $parts) . '</nav>';
    }

    // -----------------------------------------------------------------
    // Serialisation
    // -----------------------------------------------------------------

    /**
     * Serialise to the standard pagination envelope for `json_encode()`.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Return the pagination envelope as an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data'         => $this->items,
            'total'        => $this->total,
            'per_page'     => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page'    => $this->lastPage(),
            'from'         => $this->from(),
            'to'           => $this->to(),
        ];
    }

    // -----------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------

    private function pageUrl(string $base, int $page): string
    {
        if ($base === '') {
            return '?page=' . $page;
        }
        $sep = str_contains($base, '?') ? '&' : '?';
        return htmlspecialchars($base . $sep . 'page=' . $page, ENT_QUOTES, 'UTF-8');
    }
}
